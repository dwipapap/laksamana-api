<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Jadwal in core (#47, PRD #1): shift cells, requests and the normalised
 * setting (shifts, jabatan, shiftKru, manajemen, maksBeruntun/jedaMin).
 * Heads and Penempatan Divisi already live in identity (#43/#44).
 * ERD and legacy mapping: docs/db/jadwal.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; created_by/updated_by become FKs once `user` exists.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };

        // One row per crew x date. Legacy PK (user_id, tgl) becomes
        // legacy_id "{user_legacy}|{YYYY-MM-DD}" + UNIQUE(user_id, tgl).
        // user_id is now the User ULID (FK); the wire keeps legacy ids.
        $s->create('jadwal_sel', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->unique();
            $t->foreignUlid('user_id')->constrained('user')->cascadeOnDelete();
            $t->date('tgl')->index();
            $t->string('shift', 16);
            $t->string('jam_mulai', 5)->default('');
            $t->string('jam_selesai', 5)->default('');
            $t->string('catatan', 120)->default('');
            $t->unique(['user_id', 'tgl']);
            $tech($t);
        });
        $s->table('jadwal_sel', $actors);

        // Requests keep every legacy column verbatim (the wire serves them);
        // legacy `id` moves to legacy_id, `user_id` becomes the User ULID.
        $s->create('jadwal_pengajuan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 32)->unique();
            $t->foreignUlid('user_id')->constrained('user')->cascadeOnDelete();
            $t->string('jenis', 16)->default('OFF');
            $t->date('tgl_mulai');
            $t->date('tgl_selesai');
            $t->text('alasan')->nullable();
            $t->string('status', 16)->default('MENUNGGU')->index();
            $t->bigInteger('dibuat_at')->default(0);
            $t->string('dibuat_oleh', 120)->default('');
            $t->bigInteger('putus_at')->default(0);
            $t->string('putus_oleh', 120)->default('');
            $t->string('putus_nota', 255)->default('');
            $t->string('shift', 16)->default('');
            $t->string('jam_mulai', 5)->default('');
            $t->string('jam_selesai', 5)->default('');
            $t->bigInteger('head_at')->default(0);
            $t->string('head_oleh', 120)->default('');
            $t->index('user_id');
            $tech($t);
        });
        $s->table('jadwal_pengajuan', $actors);

        // One row per shift code. `kode` is the legacy map key (no separate
        // legacy_id, like modul.kunci / divisi.kode). Columns are NULL when
        // the blob lacks the key, so partial definitions round-trip
        // verbatim; `libur` is normalised to 0/1 when present (`true` → 1);
        // `ekstra` keeps unknown shift attributes verbatim.
        $s->create('jadwal_shift', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('kode', 16)->unique();
            $t->string('nama', 32)->nullable();
            $t->string('jam_mulai', 5)->nullable();
            $t->string('jam_selesai', 5)->nullable();
            $t->string('warna', 16)->nullable();
            $t->boolean('libur')->nullable();
            $t->integer('urutan')->nullable();
            $t->json('ekstra')->nullable();
            $tech($t);
        });
        $s->table('jadwal_shift', $actors);

        // Per-crew maps from the setting blob. legacy_id is the User legacy
        // id; rows for Users that no longer exist are dropped (FK).
        $s->create('jadwal_jabatan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->foreignUlid('user_id')->unique()->constrained('user')->cascadeOnDelete();
            $t->string('jabatan', 255)->default('');
            $tech($t);
        });
        $s->table('jadwal_jabatan', $actors);

        // The crew default shift. kode_shift is NOT a FK: a deleted shift
        // code stays on the crew (as in legacy) instead of nulling the row.
        $s->create('jadwal_shift_kru', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->foreignUlid('user_id')->unique()->constrained('user')->cascadeOnDelete();
            $t->string('kode_shift', 16)->default('');
            $tech($t);
        });
        $s->table('jadwal_shift_kru', $actors);

        // Who may open the recap without heading a division. `urutan` keeps
        // the array order of the legacy `manajemen` list.
        $s->create('jadwal_manajemen', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->foreignUlid('user_id')->unique()->constrained('user')->cascadeOnDelete();
            $t->integer('urutan')->default(0);
            $tech($t);
        });
        $s->table('jadwal_manajemen', $actors);

        // Singleton (legacy_id '1'): scalar settings + `ekstra` for `template`
        // and any unknown top-level keys, stored verbatim. NULL scalars mean
        // the key was absent in the blob and stay absent on the wire.
        $s->create('jadwal_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 16)->unique();
            $t->integer('maks_beruntun')->nullable();
            $t->integer('jeda_menit')->nullable();
            $t->json('ekstra')->nullable();
            $tech($t);
        });
        $s->table('jadwal_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['jadwal_pengaturan', 'jadwal_manajemen', 'jadwal_shift_kru', 'jadwal_jabatan',
            'jadwal_shift', 'jadwal_pengajuan', 'jadwal_sel'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
