<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS in core (B5: manpower requests + CV screening). Greenfield —
 * ADR-0009, docs/modules/hris.md (Recruitment R1–R5).
 *
 *   hris_permintaan_tenaga  head request → HR → Owner → vacancy
 *   hris_lowongan           vacancy with AI criteria
 *   hris_pelamar            applicants: encrypted contact, CV bytes private,
 *                           extracted + redacted text, stage, auto-delete date
 *   hris_screening          every AI result with its prompt + model version
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };

        $s->create('hris_permintaan_tenaga', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('divisi', 64);
            $t->string('posisi', 120);
            $t->integer('jumlah')->default(1);
            $t->string('jenis', 16)->default('TAMBAHAN'); // PENGGANTI | TAMBAHAN
            $t->string('alasan', 500)->default('');
            $t->date('dibutuhkan_tgl')->nullable();
            $t->text('kualifikasi')->nullable();
            // MENUNGGU_HR → MENUNGGU_OWNER → DISETUJUI / DITOLAK / DIBATALKAN
            $t->string('status', 16)->default('MENUNGGU_HR');
            $t->string('pemohon_id', 64)->default('');
            $t->string('hr_oleh', 120)->default('');
            $t->bigInteger('hr_at')->default(0);
            $t->string('hr_nota', 255)->default('');
            $t->string('owner_oleh', 120)->default('');
            $t->bigInteger('owner_at')->default(0);
            $t->string('owner_nota', 255)->default('');
            $tech($t);
            $t->index(['status']);
            $t->index(['divisi']);
        });

        $s->create('hris_lowongan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->ulid('permintaan_id')->nullable()->unique();
            $t->string('posisi', 120);
            $t->text('kriteria')->nullable(); // AI screening criteria
            $t->string('status', 16)->default('BUKA'); // BUKA | TUTUP
            $tech($t);
        });

        $s->create('hris_pelamar', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('lowongan_id')->constrained('hris_lowongan')->cascadeOnDelete();
            $t->string('nama', 120);
            $t->text('kontak')->nullable(); // encrypted JSON (telepon/email/alamat)
            $t->string('cv_path', 190)->nullable(); // storage/app/hris-cv
            $t->string('cv_nama', 190)->nullable();
            $t->longText('teks_cv')->nullable();
            $t->longText('teks_redaksi')->nullable();
            // BARU → DISARING → INTERVIEW → DITAWARI → DITERIMA, DITOLAK anytime
            $t->string('tahap', 16)->default('BARU');
            $t->string('catatan', 500)->default('');
            $t->string('draft_user_id', 64)->default('');
            $t->date('hapus_setelah'); // +12 months (R4)
            $tech($t);
            $t->index(['lowongan_id', 'tahap']);
            $t->index(['hapus_setelah']);
        });

        $s->create('hris_screening', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pelamar_id')->constrained('hris_pelamar')->cascadeOnDelete();
            $t->string('provider', 32)->default('');
            $t->string('model', 64)->default('');
            $t->string('versi_prompt', 32)->default('');
            $t->json('hasil')->nullable(); // {skor, alasan, ringkasan}
            $t->bigInteger('waktu')->default(0);
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['pelamar_id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_screening', 'hris_pelamar', 'hris_lowongan', 'hris_permintaan_tenaga'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
