<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 master data for people and Divisi (docs/erp/orang-divisi.md):
 * one Divisi list with a shift/kantor kind, the HR record of a User
 * (karyawan), and the Pihak roles outside the Users (klien, talent, kol,
 * pekerja_harian) next to the existing vendor role.
 *
 * Every Karyawan is a User; module crew lists get no table of their own
 * (they are copies of the Office roster). Buyers stay public accounts.
 */
return new class extends Migration
{
    private const TABLES = ['kol', 'talent', 'klien', 'pekerja_harian_divisi', 'pekerja_harian', 'karyawan'];

    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0007 technical columns; created_by/updated_by FK `user`.
        $tech = function (Blueprint $t, bool $softDelete = true): void {
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
        // A role of a Pihak: keyed by pihak_id, so at most one row per role.
        $peran = function (Blueprint $t): void {
            $t->foreignUlid('pihak_id')->primary()->constrained('pihak')->restrictOnDelete();
            $t->string('legacy_id', 64)->nullable()->unique();
        };

        // Existing rows keep their legacy codes (v1 reads them) and are shift Divisi.
        $s->table('divisi', function (Blueprint $t): void {
            $t->string('nama', 60)->default('')->after('kode');
            $t->string('jenis', 8)->default('shift')->after('nama');
            $t->boolean('aktif')->default(true)->after('jenis');
        });

        $s->table('pihak', function (Blueprint $t): void {
            $t->string('email', 190)->nullable()->after('telepon');
            $t->string('alamat', 500)->nullable()->after('email');
            $t->string('instagram', 120)->nullable()->after('alamat');
        });

        $s->create('karyawan', function (Blueprint $t) use ($tech): void {
            // RESTRICT: a User with an HR record is deactivated, never hard-deleted.
            $t->foreignUlid('user_id')->primary()->constrained('user')->restrictOnDelete();
            $t->string('legacy_id', 64)->nullable()->unique(); // hr_employees.id
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string('divisi_impor', 80)->nullable(); // legacy division that matched no Divisi
            $t->foreignUlid('atasan_id')->nullable()->constrained('user')->nullOnDelete();
            $t->string('email', 190)->nullable();
            $t->date('tanggal_lahir')->nullable();
            $t->date('akhir_kontrak')->nullable();
            $t->date('akhir_percobaan')->nullable();
            $t->string('catatan', 500)->nullable();
            $tech($t);
        });

        $s->create('pekerja_harian', function (Blueprint $t) use ($peran, $tech): void {
            $peran($t); // legacy_id = dw_pekerja.id
            $t->string('no_hp', 32)->unique(); // how a Pekerja Harian is identified
            $t->string('jenis_kelamin', 10)->nullable();
            $t->string('area', 80)->nullable();
            $t->string('posisi', 240)->nullable();
            $t->string('keahlian', 255)->nullable();
            $t->boolean('aktif')->default(true);
            $tech($t);
        });

        $s->create('pekerja_harian_divisi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pihak_id')->constrained('pekerja_harian', 'pihak_id')->cascadeOnDelete();
            $t->foreignUlid('divisi_id')->constrained('divisi')->restrictOnDelete();
            $tech($t, softDelete: false);
            $t->unique(['pihak_id', 'divisi_id']);
        });

        $s->create('klien', function (Blueprint $t) use ($peran, $tech): void {
            $peran($t); // legacy_id = marketing clients id
            $t->string('perusahaan', 190)->nullable();
            $t->string('kontak_nama', 120)->nullable(); // the person to call at a company Klien
            $t->date('tanggal_lahir')->nullable();
            $t->string('sumber', 60)->nullable();
            $t->foreignUlid('pic_marketing_id')->nullable()->constrained('user')->nullOnDelete();
            $t->string('pic_marketing_impor', 120)->nullable();
            $tech($t);
        });

        $s->create('talent', function (Blueprint $t) use ($peran, $tech): void {
            $peran($t); // legacy_id = ems talents id
            $t->string('kategori', 60)->nullable();
            $t->string('npwp', 32)->nullable();
            $t->decimal('tarif_bawaan', 15, 0)->nullable();
            $t->string('status_kontrak', 32)->nullable();
            $t->string('manajer_nama', 120)->nullable();
            $t->string('manajer_telepon', 40)->nullable();
            $tech($t);
        });

        $s->create('kol', function (Blueprint $t) use ($peran, $tech): void {
            $peran($t); // legacy_id = konten kols id
            $t->string('jenis', 32)->nullable();
            $tech($t);
        });

        foreach ([
            'divisi' => ["jenis IN ('shift','kantor')"],
            'talent' => ['tarif_bawaan >= 0'],
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
        DB::connection('core')->statement('ALTER TABLE `divisi` DROP CONSTRAINT `divisi_chk_0`');
        $s->table('divisi', fn (Blueprint $t) => $t->dropColumn(['nama', 'jenis', 'aktif']));
        $s->table('pihak', fn (Blueprint $t) => $t->dropColumn(['email', 'alamat', 'instagram']));
    }
};
