<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Kerja Tim (docs/erp/kerja-tim.md): BD's team tools (tasks with
 * several PICs, recurring routines, cross-Divisi coordination requests,
 * agenda) and the one activity log and notification table every Modul
 * shares instead of keeping its own.
 *
 * log_aktivitas points at its object by kind + key without an FK, on
 * purpose: a log row must outlive the object it describes (kerja-tim.md).
 */
return new class extends Migration
{
    private const TABLES = [
        'notifikasi', 'log_aktivitas', 'agenda_tim', 'rutinitas_tim', 'permintaan_koordinasi', 'tugas_tim_pic', 'tugas_tim',
    ];

    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0007 technical columns; created_by/updated_by FK `user`.
        $tech = function (Blueprint $t, bool $softDelete = false): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            if ($softDelete) {
                $t->softDeletes();
            }
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        $legacy = fn (Blueprint $t) => $t->string('legacy_id', 64)->nullable()->unique();
        $divisi = function (Blueprint $t, string $kolom = 'divisi'): void {
            $t->foreignUlid("{$kolom}_id")->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string("{$kolom}_impor", 64)->nullable();
        };
        $orang = function (Blueprint $t, string $kolom): void {
            $t->foreignUlid("{$kolom}_id")->nullable()->constrained('user')->restrictOnDelete();
            $t->string("{$kolom}_impor", 120)->nullable();
        };

        $s->create('tugas_tim', function (Blueprint $t) use ($legacy, $divisi, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('judul', 255);
            $t->text('deskripsi')->nullable();
            $divisi($t);
            $t->foreignUlid('proyek_id')->nullable()->constrained('proyek')->restrictOnDelete();
            $t->string('jenis', 40)->nullable();
            $t->string('status', 8)->default('to_do');
            $t->string('prioritas', 8)->default('medium');
            $t->boolean('penting')->default(false);
            $t->boolean('mendesak')->default(false);
            $t->date('tenggat')->nullable();
            $t->unsignedTinyInteger('progres')->default(0);
            $tech($t, softDelete: true);
            $t->index(['status', 'tenggat']);
        });

        $s->create('tugas_tim_pic', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tugas_tim_id')->constrained('tugas_tim')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->unsignedTinyInteger('urutan')->default(0); // 0 = the lead PIC
            $tech($t);
            $t->unique(['tugas_tim_id', 'user_id']);
        });

        $s->create('permintaan_koordinasi', function (Blueprint $t) use ($legacy, $divisi, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('judul', 255);
            $t->text('deskripsi')->nullable();
            $divisi($t, 'dari_divisi');
            $divisi($t, 'ke_divisi');
            $orang($t, 'diminta_oleh');
            $orang($t, 'ditugaskan_ke');
            $t->string('status', 8)->default('diminta');
            $t->string('prioritas', 8)->default('medium');
            $t->date('tenggat')->nullable();
            $t->foreignUlid('proyek_id')->nullable()->constrained('proyek')->restrictOnDelete();
            $tech($t);
        });

        $s->create('rutinitas_tim', function (Blueprint $t) use ($legacy, $divisi, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nama', 255);
            $divisi($t);
            $orang($t, 'pic');
            $t->string('frekuensi', 32)->nullable();
            $t->boolean('penting')->default(false);
            $t->boolean('mendesak')->default(false);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        $s->create('agenda_tim', function (Blueprint $t) use ($legacy, $divisi, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('judul', 255);
            $t->date('tanggal');
            $t->string('jenis', 40)->nullable();
            $divisi($t);
            $tech($t);
        });

        // One log for every Modul. objek_jenis + objek_kunci deliberately carry no FK.
        $s->create('log_aktivitas', function (Blueprint $t) use ($legacy): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('modul_id')->constrained('modul')->restrictOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('user')->nullOnDelete();
            $t->string('user_impor', 120)->nullable();
            $t->string('aksi', 80);
            $t->string('objek_jenis', 40)->nullable();
            $t->string('objek_kunci', 64)->nullable();
            $t->json('detail')->nullable();
            $t->timestamp('terjadi_at');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['modul_id', 'terjadi_at']);
            $t->index(['objek_jenis', 'objek_kunci']);
        });

        $s->create('notifikasi', function (Blueprint $t) use ($legacy): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('user_id')->constrained('user')->cascadeOnDelete(); // the recipient
            $t->foreignUlid('modul_id')->constrained('modul')->restrictOnDelete();
            $t->string('jenis', 40)->nullable();
            $t->string('judul', 190);
            $t->text('isi')->nullable();
            $t->string('tautan', 500)->nullable();
            $t->timestamp('dibaca_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'dibaca_at']);
        });

        $prioritas = "prioritas IN ('urgent','high','medium','low')";
        foreach ([
            'tugas_tim' => [
                "status IN ('backlog','to_do','doing','waiting','review','done')",
                $prioritas,
                'progres BETWEEN 0 AND 100',
            ],
            'permintaan_koordinasi' => [
                "status IN ('diminta','diproses','review','selesai')",
                $prioritas,
                'dari_divisi_id IS NULL OR ke_divisi_id IS NULL OR dari_divisi_id <> ke_divisi_id',
            ],
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
