<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marketing in core (#49, PRD #1): CRM rows (klien, acara, tindak lanjut,
 * persetujuan, pengguna, staf, template/kategori tugas, kategori,
 * notifikasi, aktivitas), the two id-keyed lists that used to live inside
 * `settings` (vip, permintaan desain) as child tables, and the remaining
 * key/value documents in marketing_pengaturan.
 * ERD and legacy mapping: docs/db/marketing.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. created_by/updated_by stay NULL for
        // marketing: actors are free-text session names kept in the row
        // itself, with no link to an Office User (see docs/db/marketing.md).
        // There are no Laravel timestamps: the legacy millisecond stamps
        // (`updated_at`, `created_at`) ARE the change record and already
        // carry those names, and `version` is the ADR concurrency column.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        // Every row table keeps its legacy id in `legacy_id` and the full
        // row verbatim in `data` (open-ended CRM fields); the typed columns
        // are the query surface, derived from the row on every write.
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
        };

        $s->create('marketing_klien', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('nama', 255)->nullable();
            $t->string('perusahaan', 255)->nullable();
            $t->string('hp', 32)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('source', 64)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->string('mkt_pic', 64)->nullable()->index();
            $t->date('last_contact')->nullable();
            $t->date('next_fu')->nullable()->index();
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $t->index('hp');
            $tech($t);
        });
        $s->table('marketing_klien', $actors);

        $s->create('marketing_acara', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('client_id', 64)->nullable()->index();
            $t->string('nama', 255)->nullable();
            $t->string('jenis', 64)->nullable();
            $t->date('tanggal')->nullable()->index();
            $t->integer('pax')->default(0);
            $t->string('status', 32)->nullable()->index();
            $t->string('pipe_col', 32)->nullable();
            $t->string('mkt_pic', 64)->nullable()->index();
            $t->boolean('invoice_sent')->default(false);
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_acara', $actors);

        $s->create('marketing_tindak_lanjut', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('client_id', 64)->nullable()->index();
            $t->string('event_id', 64)->nullable()->index();
            $t->string('by_user', 64)->nullable();
            $t->dateTime('at_time')->nullable()->index();
            $t->date('next_fu')->nullable();
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_tindak_lanjut', $actors);

        $s->create('marketing_persetujuan', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('event_id', 64)->nullable()->index();
            $t->string('status', 32)->nullable()->index();
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_persetujuan', $actors);

        // Local panel users (roles inside the Marketing Panel), NOT Office
        // Users: no FK to `user`.
        $s->create('marketing_pengguna', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $t->string('role', 32)->nullable()->index();
            $t->string('divisi', 64)->nullable();
            $t->boolean('active')->default(true);
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_pengguna', $actors);

        $s->create('marketing_staf', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('nama', 255)->nullable();
            $t->string('divisi', 64)->nullable()->index();
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_staf', $actors);

        $s->create('marketing_template_tugas', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('divisi', 64)->nullable()->index();
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_template_tugas', $actors);

        $s->create('marketing_kategori_tugas', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_kategori_tugas', $actors);

        $s->create('marketing_kategori', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_kategori', $actors);

        $s->create('marketing_notifikasi', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->dateTime('at_time')->nullable()->index();
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_notifikasi', $actors);

        // Append-only timeline. Legacy ids (act_…) stay unique in legacy_id.
        $s->create('marketing_aktivitas', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('ref_type', 32)->nullable();
            $t->string('ref_id', 64)->nullable();
            $t->string('action', 255)->nullable();
            $t->string('by_user', 255)->nullable();
            $t->dateTime('at_time')->nullable();
            $t->longText('data');
            $t->index(['ref_type', 'ref_id']);
            $t->index('at_time');
            $tech($t);
        });
        $s->table('marketing_aktivitas', $actors);

        // Reservasi VIP: one row per id, in stable list order (`urutan` is
        // insert-only, mirroring the legacy JSON array semantics).
        $s->create('marketing_vip', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->date('tanggal')->nullable()->index();
            $t->string('jenis', 64)->nullable();
            $t->bigInteger('updated_at')->default(0);
            $t->unsignedInteger('urutan')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_vip', $actors);

        // Request Design: schemaless briefs (refs carry embedded images);
        // progress (status/pic) stays a map in marketing_pengaturan.
        $s->create('marketing_permintaan_desain', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->bigInteger('updated_at')->default(0);
            $t->unsignedInteger('urutan')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('marketing_permintaan_desain', $actors);

        // Every remaining settings document (scalars, designreqprog/opsi and
        // the open-ended extra:* docs). `k` keeps the legacy key verbatim,
        // including the extra: prefix.
        $s->create('marketing_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('marketing_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['marketing_pengaturan', 'marketing_permintaan_desain', 'marketing_vip',
            'marketing_aktivitas', 'marketing_notifikasi', 'marketing_kategori',
            'marketing_kategori_tugas', 'marketing_template_tugas', 'marketing_staf',
            'marketing_pengguna', 'marketing_persetujuan', 'marketing_tindak_lanjut',
            'marketing_acara', 'marketing_klien'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
