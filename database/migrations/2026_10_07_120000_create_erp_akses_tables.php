<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Access inside a Modul (docs/erp/akses.md): Peran, Akses Halaman (Tingkat +
 * Lingkup), Kewenangan, and the one Peran a User holds per Modul. Replaces the
 * nine per-module matrices kept in module blobs and enforced only in the
 * browser (kk_akses, bk_akses, …).
 *
 * Every matrix row carries modul_id and composite FKs, so a Peran can only be
 * given pages, Kewenangan and Users of its own Modul: the database refuses a
 * Kas role on a Stock page.
 */
return new class extends Migration
{
    private const TABLES = ['penempatan_peran', 'peran_kewenangan', 'kewenangan', 'peran_halaman', 'peran', 'halaman'];

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
        // A row of a Modul that matrix rows point at together with its modul_id.
        $milikModul = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('modul_id')->constrained('modul')->restrictOnDelete();
            $t->string('kunci', 64);
            $t->string('nama', 120);
            $t->unique(['modul_id', 'kunci']);
            $t->unique(['id', 'modul_id']); // target of the composite FKs below
        };

        // Registered by code (a seeder per Modul), never created from a screen.
        $s->create('halaman', function (Blueprint $t) use ($milikModul, $tech): void {
            $milikModul($t);
            $t->boolean('bisa_ubah')->default(true);       // false caps Tingkat at 1 (legacy AKS_HAL_ISI)
            $t->boolean('data_per_orang')->default(false); // the page uses Lingkup
            $t->integer('urutan')->default(0);
            $tech($t, softDelete: true);
        });

        $s->create('peran', function (Blueprint $t) use ($milikModul, $tech): void {
            $milikModul($t);
            // 1 = the Peran of a User without a placement; NULL otherwise. One per Modul.
            $t->boolean('bawaan')->nullable();
            $t->integer('urutan')->default(0);
            $tech($t, softDelete: true);
            $t->unique(['modul_id', 'bawaan']);
        });

        $s->create('kewenangan', function (Blueprint $t) use ($milikModul, $tech): void {
            $milikModul($t);
            $tech($t, softDelete: true);
        });

        $s->create('peran_halaman', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->ulid('modul_id');
            $t->ulid('peran_id');
            $t->ulid('halaman_id');
            $t->unsignedTinyInteger('tingkat'); // 0 tak terlihat, 1 lihat, 2 boleh ubah
            $t->string('lingkup', 8)->nullable(); // only for a data_per_orang page
            $tech($t);
            $t->unique(['peran_id', 'halaman_id']);
            $t->foreign(['peran_id', 'modul_id'])->references(['id', 'modul_id'])->on('peran')->cascadeOnDelete();
            $t->foreign(['halaman_id', 'modul_id'])->references(['id', 'modul_id'])->on('halaman')->cascadeOnDelete();
        });

        $s->create('peran_kewenangan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->ulid('modul_id');
            $t->ulid('peran_id');
            $t->ulid('kewenangan_id');
            $t->string('lingkup', 8)->default('semua');
            $tech($t);
            $t->unique(['peran_id', 'kewenangan_id']);
            $t->foreign(['peran_id', 'modul_id'])->references(['id', 'modul_id'])->on('peran')->cascadeOnDelete();
            $t->foreign(['kewenangan_id', 'modul_id'])->references(['id', 'modul_id'])->on('kewenangan')->cascadeOnDelete();
        });

        $s->create('penempatan_peran', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->nullable()->unique(); // "<modul>|<legacy user key>"
            // CASCADE: like izin_akses, a deleted User takes their placements along.
            $t->foreignUlid('user_id')->constrained('user')->cascadeOnDelete();
            $t->ulid('modul_id');
            $t->ulid('peran_id');
            $tech($t);
            $t->unique(['user_id', 'modul_id']); // one Peran per User per Modul
            // RESTRICT: a Peran still held by someone cannot be dropped.
            $t->foreign(['peran_id', 'modul_id'])->references(['id', 'modul_id'])->on('peran')->restrictOnDelete();
        });

        foreach ([
            'peran' => ['bawaan IS NULL OR bawaan = 1'],
            'peran_halaman' => [
                'tingkat BETWEEN 0 AND 2',
                "lingkup IS NULL OR lingkup IN ('sendiri','divisi','semua')",
            ],
            'peran_kewenangan' => ["lingkup IN ('sendiri','divisi','semua')"],
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
