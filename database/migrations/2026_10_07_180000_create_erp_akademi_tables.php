<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Akademi (docs/erp/akademi.md): learning materials for some or all
 * Divisi, monthly programmes of materials, and each User's progress, which
 * outlives a deleted material or programme (G-17).
 */
return new class extends Migration
{
    private const TABLES = [
        'aktivitas_akademi', 'progres_program', 'progres_materi', 'program_materi', 'program_belajar',
        'materi_divisi', 'materi', 'pengaturan_akademi',
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
        $legacy = fn (Blueprint $t) => $t->string('legacy_id', 191)->nullable()->unique();

        $s->create('pengaturan_akademi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->date('berlaku_dari')->unique();
            $t->unsignedTinyInteger('nilai_lulus_bawaan')->default(70);
            $tech($t);
        });

        $s->create('materi', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('judul', 255);
            $t->string('jenis', 32)->nullable();
            $t->string('kategori', 64)->nullable();
            $t->boolean('wajib')->default(false);
            $t->boolean('terbit')->default(false);
            $t->unsignedTinyInteger('nilai_lulus')->nullable(); // NULL = the default in force
            $t->json('langkah')->nullable(); // lesson steps: text, video links, quiz questions
            $tech($t, softDelete: true);
        });

        // No rows = for every Divisi.
        $s->create('materi_divisi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('materi_id')->constrained('materi')->cascadeOnDelete();
            $t->foreignUlid('divisi_id')->constrained('divisi')->restrictOnDelete();
            $tech($t);
            $t->unique(['materi_id', 'divisi_id']);
        });

        $s->create('program_belajar', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('judul', 255);
            $t->date('bulan'); // first day of the month
            $t->date('tenggat')->nullable();
            $t->string('catatan', 1000)->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('program_materi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('program_belajar_id')->constrained('program_belajar')->restrictOnDelete();
            $t->foreignUlid('materi_id')->constrained('materi')->restrictOnDelete();
            $t->unsignedSmallInteger('urutan');
            $tech($t);
            $t->unique(['program_belajar_id', 'materi_id']);
            $t->unique(['program_belajar_id', 'urutan']);
        });

        // RESTRICT everywhere: a learner's history outlives the material (G-17).
        $s->create('progres_materi', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->foreignUlid('materi_id')->constrained('materi')->restrictOnDelete();
            $t->unsignedSmallInteger('percobaan')->default(0);
            $t->unsignedTinyInteger('nilai')->nullable();
            $t->boolean('lulus')->default(false);
            $t->timestamp('terakhir_at')->nullable();
            $t->timestamp('selesai_at')->nullable();
            $tech($t);
            $t->unique(['user_id', 'materi_id']);
        });

        $s->create('progres_program', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->foreignUlid('program_materi_id')->constrained('program_materi')->restrictOnDelete();
            $t->boolean('selesai')->default(false);
            $t->unsignedTinyInteger('nilai')->nullable();
            $t->timestamp('dicatat_at')->nullable();
            $tech($t);
            $t->unique(['user_id', 'program_materi_id']);
        });

        $s->create('aktivitas_akademi', function (Blueprint $t) use ($legacy): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('user_id')->nullable()->constrained('user')->nullOnDelete();
            $t->string('user_impor', 120)->nullable();
            $t->string('aksi', 60);
            $t->json('detail')->nullable();
            $t->timestamp('terjadi_at')->index();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        foreach ([
            'pengaturan_akademi' => ['nilai_lulus_bawaan BETWEEN 0 AND 100'],
            'materi' => ['nilai_lulus IS NULL OR nilai_lulus BETWEEN 0 AND 100'],
            'program_belajar' => ['DAYOFMONTH(bulan) = 1', 'tenggat IS NULL OR tenggat >= bulan'],
            'progres_materi' => ['nilai IS NULL OR nilai BETWEEN 0 AND 100', 'NOT lulus OR selesai_at IS NOT NULL'],
            'progres_program' => ['nilai IS NULL OR nilai BETWEEN 0 AND 100'],
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
