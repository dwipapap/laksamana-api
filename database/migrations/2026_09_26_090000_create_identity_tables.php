<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity in core (#43, PRD #1): User, Modul, Izin Akses, Larangan, Admin
 * Modul, Divisi (+ its words), Kepala Divisi, Penempatan Divisi and the legacy
 * Sesi tokens. ERD and legacy mapping: docs/db/identity.md.
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

        $s->create('user', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->string('nama', 120)->index();
            $t->string('username', 40)->nullable()->unique(); // legacy '' = NULL
            $t->string('nama_tampilan', 60)->default('');
            $t->string('pin', 32); // plain until security follow-up #1 (PRD non-goal)
            $t->boolean('aktif')->default(true); // false = User Nonaktif
            $t->string('tim', 255)->default('');
            $t->string('no_hp', 32)->default('');
            $t->string('talenta_id', 32)->default('')->index();
            $t->string('cabang', 120)->default('');
            $t->string('organisasi', 80)->default('');
            $t->string('jabatan', 120)->default('');
            $t->string('level_jabatan', 60)->default('');
            $t->string('status_kerja', 60)->default('');
            $t->date('tanggal_bergabung')->nullable(); // legacy '' = NULL
            $tech($t);
        });
        $s->table('user', $actors);

        $s->create('modul', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->string('kunci', 64)->unique(); // the legacy modules.key
            $t->string('label', 120)->default('');
            $t->boolean('aktif')->default(true);
            $t->integer('urutan')->default(0);
            $t->boolean('terbatas')->default(false); // Modul Terbatas
            $tech($t);
            $actors($t);
        });

        foreach (['izin_akses', 'larangan', 'admin_modul'] as $name) {
            $s->create($name, function (Blueprint $t) use ($tech, $actors, $name): void {
                $t->ulid('id')->primary();
                $t->string('legacy_id', 191)->nullable()->unique(); // "<users.id>|<module>"
                $t->foreignUlid('user_id')->constrained('user')->cascadeOnDelete();
                // NULL = every active Modul (`*`) / Superadmin; Larangan always names one Modul.
                $col = $t->foreignUlid('modul_id');
                if ($name !== 'larangan') {
                    $col->nullable();
                }
                $col->constrained('modul')->cascadeOnDelete();
                $tech($t);
                $actors($t);
            });
        }

        $s->create('divisi', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->string('kode', 32)->unique();
            $t->integer('urutan')->default(0); // first match wins
            $tech($t);
            $actors($t);
        });

        $s->create('divisi_kata', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            // NULL = office word (office, kantor): wins over every Divisi word -> Nonshift
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->cascadeOnDelete();
            $t->string('kata', 32)->unique();
            $tech($t);
            $actors($t);
        });

        $s->create('kepala_divisi', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->nullable()->unique(); // "<divisi>|<users.id>"
            $t->foreignUlid('divisi_id')->constrained('divisi')->restrictOnDelete();
            // RESTRICT: a User who is Kepala Divisi cannot be deleted (#2).
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->unique(['divisi_id', 'user_id']);
            $tech($t);
            $actors($t);
        });

        $s->create('penempatan_divisi', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique(); // divOverride key (users.id)
            $t->foreignUlid('user_id')->unique()->constrained('user')->cascadeOnDelete();
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete(); // NULL = Nonshift
            $tech($t);
            $actors($t);
        });

        // Retired with ADR-0001 (#82). The token is the legacy id and the key.
        $s->create('sesi_legacy', function (Blueprint $t): void {
            $t->string('token', 64)->primary();
            $t->foreignUlid('user_id')->constrained('user')->cascadeOnDelete();
            $t->dateTime('kedaluwarsa', 3)->index(); // legacy expiry is epoch milliseconds
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['sesi_legacy', 'penempatan_divisi', 'kepala_divisi', 'divisi_kata', 'divisi',
            'admin_modul', 'larangan', 'izin_akses', 'modul', 'user'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
