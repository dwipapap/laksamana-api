<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS master data for people (ADR-0012): Divisi, Jabatan and the HR record
 * of a User. Identity is still legacy in production, so a person is the
 * legacy User id (`user_id`, string) plus a nullable `user_ulid`, like every
 * other hris_* table (ADR-0009). No FK to `user`.
 *
 *   hris_divisi           every Divisi; shift Divisi share the Jadwal codes
 *   hris_divisi_kepala    Kepala Divisi of a kantor Divisi (shift heads stay in Jadwal)
 *   hris_jabatan_level    configurable levels, seeded 1–6
 *   hris_jabatan          master Jabatan (level, optional Divisi)
 *   hris_karyawan         the HR record of a User, one per User
 *   hris_karyawan_riwayat changes of Divisi, Jabatan, atasan and status kerja
 *
 * No seed rows here: production gets the core schema empty and the test
 * harness empties core tables, so HrisMasterBawaan creates the shift Divisi
 * and draft levels on demand. Kantor Divisi, Jabatan and Karyawan come from
 * `php artisan hris:impor-karyawan`.
 */
return new class extends Migration
{
    private const TABLES = ['hris_karyawan_riwayat', 'hris_karyawan', 'hris_jabatan',
        'hris_jabatan_level', 'hris_divisi_kepala', 'hris_divisi'];

    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0007 technical columns; created_by/updated_by stay NULL while
        // identity is legacy (the acting NAME goes to *_oleh where needed).
        $tech = function (Blueprint $t, bool $softDelete = true): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            if ($softDelete) {
                $t->softDeletes();
            }
        };

        $s->create('hris_divisi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('kode', 32)->unique(); // generated from nama, never changed
            $t->string('nama', 60);
            $t->string('jenis', 8)->default('kantor'); // shift | kantor
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t);
        });

        $s->create('hris_divisi_kepala', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('divisi_id')->constrained('hris_divisi')->cascadeOnDelete();
            $t->string('user_id', 64);
            $t->ulid('user_ulid')->nullable();
            $tech($t, softDelete: false);
            $t->unique(['divisi_id', 'user_id']);
            $t->index('user_id');
        });

        $s->create('hris_jabatan_level', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 60);
            $t->integer('urutan')->default(0); // higher = more senior
            $t->boolean('aktif')->default(true);
            $tech($t);
        });

        $s->create('hris_jabatan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 120)->unique();
            $t->foreignUlid('level_id')->constrained('hris_jabatan_level')->restrictOnDelete();
            $t->foreignUlid('divisi_id')->nullable()->constrained('hris_divisi')->restrictOnDelete();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t);
        });

        $s->create('hris_karyawan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64)->unique();
            $t->ulid('user_ulid')->nullable();
            $t->foreignUlid('divisi_id')->nullable()->constrained('hris_divisi')->restrictOnDelete();
            $t->string('divisi_impor', 80)->nullable(); // legacy value that matched no Divisi
            $t->foreignUlid('jabatan_id')->nullable()->constrained('hris_jabatan')->restrictOnDelete();
            $t->string('jabatan_impor', 120)->nullable();
            $t->string('atasan_user_id', 64)->nullable(); // NULL = the Divisi's Kepala Divisi
            $t->string('status_kerja', 12)->nullable(); // PROBATION | KONTRAK | TETAP | MAGANG
            $t->string('nik', 16)->nullable()->unique();
            $t->string('jenis_kelamin', 1)->nullable(); // L | P
            $t->date('tanggal_lahir')->nullable();
            $t->string('alamat', 500)->nullable();
            $t->string('kontak_darurat_nama', 120)->nullable();
            $t->string('kontak_darurat_hp', 32)->nullable();
            $t->date('tanggal_gabung')->nullable();
            $t->date('akhir_percobaan')->nullable();
            $t->date('akhir_kontrak')->nullable();
            $t->date('tanggal_keluar')->nullable();
            $t->string('alasan_keluar', 16)->nullable(); // RESIGN | KONTRAK_HABIS | PHK | LAINNYA
            $t->string('catatan_keluar', 255)->nullable();
            $tech($t);
            $t->index('atasan_user_id');
            $t->index('tanggal_keluar');
        });

        $s->create('hris_karyawan_riwayat', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('karyawan_id')->constrained('hris_karyawan')->restrictOnDelete();
            $t->string('kolom', 16); // divisi | jabatan | atasan | status_kerja
            $t->string('nilai_lama', 190)->nullable(); // id or code, as stored
            $t->string('nilai_baru', 190)->nullable();
            $t->string('label_lama', 190)->nullable(); // name at that time, for the timeline
            $t->string('label_baru', 190)->nullable();
            $t->date('berlaku_dari');
            $t->string('catatan', 255)->nullable();
            $t->string('dibuat_oleh', 120)->default('');
            $tech($t, softDelete: false);
            $t->index(['karyawan_id', 'berlaku_dari']);
        });

        foreach ([
            'hris_divisi' => ["jenis IN ('shift','kantor')"],
            'hris_karyawan' => [
                "status_kerja IS NULL OR status_kerja IN ('PROBATION','KONTRAK','TETAP','MAGANG')",
                "jenis_kelamin IS NULL OR jenis_kelamin IN ('L','P')",
                "alasan_keluar IS NULL OR alasan_keluar IN ('RESIGN','KONTRAK_HABIS','PHK','LAINNYA')",
                '(tanggal_keluar IS NULL) = (alasan_keluar IS NULL)',
            ],
            'hris_karyawan_riwayat' => ["kolom IN ('divisi','jabatan','atasan','status_kerja')"],
        ] as $table => $checks) {
            foreach ($checks as $i => $expr) {
                DB::connection('core')->statement(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_chk_{$i}` CHECK ({$expr})"
                );
            }
        }

    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (self::TABLES as $table) {
            $s->dropIfExists($table);
        }
    }
};
