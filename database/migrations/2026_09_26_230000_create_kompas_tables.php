<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Kompas in core (#69, PRD #1): the omset blob, the Catatan Void / QRIS BRI
 * accountability tables, the Investor Compass reports and the Analytics state
 * + access matrix. Every table takes the kompas_ prefix (ADR-0003); the
 * settings documents live in kompas_pengaturan. ERD and mapping: docs/db/kompas.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. The actors stay NULL: every write carries
        // a display name (or the session key), never an Office User id.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        // Legacy millisecond stamps, not Laravel timestamps: `updated_at` is the
        // change record and, for the blobs, the version the wire exposes.
        $ms = function (Blueprint $t): void {
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
        };
        // The two one-row JSON documents (app_state, an_state). `oleh` is the
        // legacy `updated_by` NAME: ADR-0003 gives `updated_by` to the FK.
        $blob = function (string $name, int $oleh) use ($s, $tech, $ms, $actors): void {
            $s->create($name, function (Blueprint $t) use ($oleh, $tech, $ms): void {
                $t->ulid('id')->primary();
                $t->string('legacy_id', 64)->unique();
                $t->longText('data');
                $t->string('oleh', $oleh)->default('');
                $ms($t);
                $tech($t);
            });
            $s->table($name, $actors);
        };

        // The omset blob: daily rows, Daily Reports, deposits, employees,
        // compliments, settings… written whole by Cashier and Finance > Omset.
        $blob('kompas_app_state', 120);

        // Analytics: its own one-row document plus the page × actor matrix.
        $blob('kompas_an_state', 80);

        // an_akses. `kunci` is a role name, a `#<legacy user id>` or a `@<name>`
        // key (deploy/analytics): the `#` form names a User, so it becomes a
        // real FK. The legacy auto-increment id never leaves the database and is
        // not `legacy_id`; the module's own key (kunci|halaman) is.
        $s->create('kompas_an_akses', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 128)->unique();
            $t->string('kunci', 80);
            $t->string('halaman', 40);
            $t->tinyInteger('tingkat')->default(2);
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $t->unique(['kunci', 'halaman']);
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_an_akses', $actors);

        // an_peran: the legacy primary key is `kunci`, which is the legacy id.
        $s->create('kompas_an_peran', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 80)->unique();
            $t->string('kunci', 80);
            $t->string('peran', 20)->default('staf');
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_an_peran', $actors);

        // Catatan Void: accountability rows, never deleted — cancelled instead.
        $s->create('kompas_void_log', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 40)->unique();
            $t->date('tgl')->index();
            $t->string('bill', 60)->default('');
            $t->string('item', 200)->default('');
            $t->string('penginput', 120)->default('');
            $t->string('salah', 120)->default('');
            $t->text('alasan')->nullable();
            $t->bigInteger('nominal')->default(0);
            $t->string('oleh', 120)->default('');
            $t->string('oleh_id', 60)->default('');
            // the User `oleh_id` names (a legacy account id, or the ULID v1 sends)
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $t->bigInteger('dibuat')->default(0);
            $t->bigInteger('diubah')->default(0);
            $t->string('diubah_oleh', 120)->default('');
            $t->bigInteger('batal_at')->default(0);
            $t->string('batal_oleh', 120)->default('');
            $t->string('batal_alasan', 255)->default('');
            $t->bigInteger('subtotal')->default(0);
            $t->bigInteger('service')->default(0);
            $t->bigInteger('tax')->default(0);
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_void_log', $actors);

        // bri_mutasi: the bank file's rows, matched against reservation DPs.
        // `sidik` stays unique — it is the ON DUPLICATE KEY target of an upload.
        $s->create('kompas_bri_mutasi', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 40)->unique();
            $t->string('sidik', 90)->unique();
            $t->date('tgl')->index();
            $t->string('jam', 8)->default('');
            $t->bigInteger('nominal')->default(0);
            $t->string('ket', 255)->default('');
            $t->date('settle')->nullable();
            $t->date('booking')->nullable();
            $t->string('res_id', 60)->default('');
            $t->string('dp_id', 60)->default('')->index();
            $t->string('res_nama', 160)->default('');
            $t->date('res_tgl')->nullable();
            $t->string('cara', 12)->default('');
            $t->string('catatan', 255)->default('');
            $t->string('cocok_oleh', 120)->default('');
            $t->bigInteger('cocok_at')->default(0);
            $t->string('oleh', 120)->default('');
            $t->string('oleh_id', 60)->default('');
            // the User `oleh_id` names (a legacy account id, or the ULID v1 sends)
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $t->bigInteger('dibuat')->default(0);
            $t->bigInteger('diubah')->default(0);
            $t->string('diubah_oleh', 120)->default('');
            $t->bigInteger('batal_at')->default(0);
            $t->string('batal_oleh', 120)->default('');
            $t->string('batal_alasan', 255)->default('');
            $t->string('sumber', 12)->default('unggah');
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_bri_mutasi', $actors);

        // bri_dp_abai: DPs marked as not a BRI fund. The legacy primary key is
        // dp_id, which is the legacy id here.
        $s->create('kompas_bri_dp_abai', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 60)->unique();
            $t->string('dp_id', 60)->default('');
            $t->string('res_id', 60)->default('');
            $t->string('nama', 160)->default('');
            $t->date('tgl')->nullable()->index();
            $t->bigInteger('nominal')->default(0);
            $t->string('alasan', 255)->default('');
            $t->string('oleh', 120)->default('');
            $t->bigInteger('abai_at')->default(0);
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_bri_dp_abai', $actors);

        // inv_lapor: one monthly PDF per (bulan, jenis) — the composite key is
        // the ON DUPLICATE KEY target of an upload, so it stays unique. The file
        // itself lives in <KOMPAS_DATA_DIR>/lapor.
        $s->create('kompas_inv_lapor', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->char('bulan', 7);
            $t->string('jenis', 20);
            $t->string('kunci', 80);
            $t->string('nama', 190)->default('');
            $t->unsignedInteger('ukuran')->default(0);
            $t->bigInteger('at')->default(0);
            $t->string('oleh', 80)->default('');
            $t->unique(['bulan', 'jenis']);
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_inv_lapor', $actors);

        // Settings documents: the void tax/service percentages (legacy
        // void_setting, one row) as the `void` document.
        $s->create('kompas_pengaturan', function (Blueprint $t) use ($tech, $ms): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $ms($t);
            $tech($t);
        });
        $s->table('kompas_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['kompas_pengaturan', 'kompas_inv_lapor', 'kompas_bri_dp_abai', 'kompas_bri_mutasi',
            'kompas_void_log', 'kompas_an_peran', 'kompas_an_akses', 'kompas_an_state', 'kompas_app_state'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
