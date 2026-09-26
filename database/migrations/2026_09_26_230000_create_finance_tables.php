<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Finance in core (#67, PRD #1, ADR-0002/0003): Kas Kecil, Brankas and the
 * invoice/kwitansi queue. Every table takes the finance_ prefix and keeps the
 * legacy table's own name after it (finance_kk_trx for kk_trx), so a grep in
 * the legacy sources still finds its twin. Legacy ids live in `legacy_id`
 * (values, ERD and deviations: docs/db/finance.md).
 *
 * The kk_/bk_/inv_ ids the old screens echo are INTEGERS and the frontends
 * build inline handlers from them (`onclick="kkEdit('+t.id+')"`), so the compat
 * surface must keep handing out integers: a row created after the cutover mints
 * its id from finance_counter, which the importer seeds with the legacy
 * AUTO_INCREMENT (KasKecil::nextId()).
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. Finance actors are free-text names inside
        // the rows (dibuat_oleh, minta_oleh, putus_oleh), so created_by/updated_by
        // stay NULL; bk_state's legacy free-text `updated_by` became diubah_oleh.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
        };
        // The legacy millisecond stamps. Only bk_state's updated_at is on the
        // wire (it is the vault's version); elsewhere they are bookkeeping.
        $ms = function (Blueprint $t): void {
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
        };
        $table = function (string $name, Closure $cols) use ($s, $row, $ms, $tech, $actors): void {
            $s->create($name, function (Blueprint $t) use ($row, $cols, $ms, $tech): void {
                $row($t);
                $cols($t);
                $ms($t);
                $tech($t);
            });
            $s->table($name, $actors);
        };

        // ───────────────────────────── Kas Kecil ──
        // uq_kk_pos_nama / uq_kk_kategori_nama stay: the services turn the 23000
        // duplicate into '"…" sudah ada dalam daftar.'
        $table('finance_kk_pos', function (Blueprint $t): void {
            $t->string('nama', 120);
            $t->integer('urut')->default(0);
            $t->boolean('aktif')->default(true);
            $t->unique('nama', 'uq_kk_pos_nama');
        });
        $table('finance_kk_kategori', function (Blueprint $t): void {
            $t->string('nama', 120);
            $t->integer('urut')->default(0);
            $t->boolean('aktif')->default(true);
            $t->unique('nama', 'uq_kk_kategori_nama');
        });
        // kategori_id keeps the legacy id as a plain string (the app writes it
        // straight from the client) with the legacy FK semantics: SET NULL.
        $table('finance_kk_trx', function (Blueprint $t): void {
            $t->date('tgl')->index();
            $t->string('keterangan', 255)->default('');
            $t->string('kategori_id', 64)->nullable()->index();
            $t->boolean('input')->default(false);
            $t->boolean('bon')->default(false);
            $t->bigInteger('dibuat_at')->default(0);
            $t->string('dibuat_oleh', 120)->default('');
            $t->foreign('kategori_id')->references('legacy_id')->on('finance_kk_kategori')->nullOnDelete();
        });
        // Split rows: trx_id/pos_id hold the legacy ids, so read() stays the
        // legacy statement. fk_tp_trx cascades (hapusTrx relies on it), fk_tp_pos
        // refuses a used source, exactly like the legacy constraints.
        $table('finance_kk_trx_pos', function (Blueprint $t): void {
            $t->string('trx_id', 64)->index();
            $t->string('pos_id', 64)->index();
            $t->bigInteger('debet')->default(0);
            $t->bigInteger('kredit')->default(0);
            $t->unique(['trx_id', 'pos_id'], 'uq_kk_trx_pos');
            $t->foreign('trx_id')->references('legacy_id')->on('finance_kk_trx')->cascadeOnDelete();
            $t->foreign('pos_id')->references('legacy_id')->on('finance_kk_pos');
        });
        // Akses Halaman: only the differences from the frontend default matrix.
        $table('finance_kk_akses', function (Blueprint $t): void {
            $t->string('kunci', 80);
            $t->string('halaman', 40);
            $t->unsignedTinyInteger('tingkat')->default(2);
            $t->unique(['kunci', 'halaman'], 'uq_kk_akses');
        });
        // '#<user id>' => role. The one Office-User link in this module: kunci
        // stays verbatim (the wire shape is keyed by it) and user_id resolves it.
        $s->create('finance_kk_peran', function (Blueprint $t) use ($ms, $tech): void {
            $t->ulid('id')->primary();
            $t->string('kunci', 80)->unique();
            $t->string('peran', 24)->default('');
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $ms($t);
            $tech($t);
        });
        $s->table('finance_kk_peran', $actors);

        // ───────────────────────────── Brankas ──
        // Same page matrix and roles for the vault panel.
        $table('finance_bk_akses', function (Blueprint $t): void {
            $t->string('kunci', 80);
            $t->string('halaman', 40);
            $t->unsignedTinyInteger('tingkat')->default(2);
            $t->unique(['kunci', 'halaman'], 'uq_bk_akses');
        });
        $s->create('finance_bk_peran', function (Blueprint $t) use ($ms, $tech): void {
            $t->ulid('id')->primary();
            $t->string('kunci', 80)->unique();
            $t->string('peran', 24)->default('');
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $ms($t);
            $tech($t);
        });
        $s->table('finance_bk_peran', $actors);
        // The vault is ONE document (legacy row id=1): only the CFO edits it.
        // `updated_at` IS the wire version; legacy `updated_by` (a free-text
        // name, never read back) is diubah_oleh, because ADR-0003 wants
        // updated_by to be the ULID actor column.
        $table('finance_bk_state', function (Blueprint $t): void {
            $t->longText('data');
            $t->string('diubah_oleh', 80)->default('');
        });

        // ───────────────────────────── invoices & kwitansi ──
        // One row per reservation/marketing deal; a decision moves the SAME row
        // MENUNGGU → DIBUAT/DITOLAK, so legacy_id is the legacy varchar id the
        // Reservasi and Marketing screens already know.
        $table('finance_inv_kwitansi', function (Blueprint $t): void {
            $t->string('res_id', 64)->unique();
            $t->string('no_invoice', 60)->default('');
            $t->string('status', 20)->default('MENUNGGU')->index();
            $t->mediumText('ringkas')->nullable();
            $t->string('minta_oleh', 120)->default('');
            $t->bigInteger('minta_at')->default(0)->index();
            $t->text('catatan')->nullable();
            $t->string('putus_oleh', 120)->default('');
            $t->bigInteger('putus_at')->default(0);
            $t->text('penanda')->nullable();
            $t->string('jenis', 24)->default('KWITANSI');
        });
        $table('finance_inv_penanda', function (Blueprint $t): void {
            $t->string('nama', 160)->default('');
            $t->string('jabatan', 160)->default('');
            $t->mediumText('ttd')->nullable();
            $t->integer('urut')->default(0)->index();
            $t->boolean('aktif')->default(true);
        });
        // inv_setting (a k/v map, the bd_pengaturan shape) keeps its own table:
        // settings() and saveSettings() speak only these keys.
        $s->create('finance_inv_setting', function (Blueprint $t) use ($ms, $tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 40)->unique();
            $t->mediumText('v')->nullable();
            $ms($t);
            $tech($t);
        });
        $s->table('finance_inv_setting', $actors);

        // Technical: the next integer id per int-keyed table, seeded from the
        // legacy AUTO_INCREMENT so a row created on core continues the legacy
        // numbering (docs/db/finance.md). Not a domain row: no tech columns.
        $s->create('finance_counter', function (Blueprint $t): void {
            $t->string('name', 64)->primary();
            $t->unsignedBigInteger('next_value')->default(1);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['finance_counter', 'finance_inv_setting', 'finance_inv_penanda', 'finance_inv_kwitansi',
            'finance_bk_state', 'finance_bk_peran', 'finance_bk_akses', 'finance_kk_peran', 'finance_kk_akses',
            'finance_kk_trx_pos', 'finance_kk_trx', 'finance_kk_kategori', 'finance_kk_pos'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
