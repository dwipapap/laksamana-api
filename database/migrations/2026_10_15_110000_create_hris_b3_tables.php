<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS in core (B3: meal allowance, discipline, team KPI, People Score).
 * Greenfield — ADR-0009, docs/modules/hris.md (Payroll P1–P2, S1–S5, K1).
 * Money is whole rupiah (ADR-0007: DECIMAL(15,0)); time is WIB dates.
 *
 *   hris_tarif_uang_makan  rate per (divisi, grade), effective-from
 *   hris_periode           locked months for the meal recap
 *   hris_rekap_uang_makan  immutable snapshot rows written at lock
 *   hris_pelanggaran       discipline record (status-driven, never deleted)
 *   hris_kpi_template/item/realisasi  team KPI per Divisi (K1)
 *   hris_skor_formula      versioned People Score weights
 *   hris_penilaian_atasan  monthly 1–5 ratings per member
 *   hris_skor              monthly locked snapshots (S3)
 *
 * Payslip phase b (P3) is NOT built: the plan lives in docs/modules/hris.md.
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
        // Whole rupiah, never floats (ADR-0007).
        $rp = fn (Blueprint $t, string $col) => $t->decimal($col, 15, 0)->default(0);

        $s->create('hris_tarif_uang_makan', function (Blueprint $t) use ($tech, $rp): void {
            $t->ulid('id')->primary();
            $t->string('divisi', 64)->default('*');
            $t->string('grade', 64)->default('');
            $rp($t, 'nominal');
            $t->date('berlaku_dari');
            $tech($t);
            $t->index(['divisi', 'grade', 'berlaku_dari']);
        });

        $s->create('hris_periode', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->char('bulan', 7)->unique(); // YYYY-MM
            $t->string('status', 16)->default('TERBUKA'); // TERBUKA | TERKUNCI
            $t->string('dikunci_oleh', 120)->default('');
            $t->bigInteger('dikunci_at')->default(0);
            $tech($t);
        });

        $s->create('hris_rekap_uang_makan', function (Blueprint $t) use ($tech, $rp): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('periode_id')->constrained('hris_periode')->cascadeOnDelete();
            $t->string('user_id', 64);
            $t->string('divisi', 64)->default('');
            $t->string('grade', 64)->default('');
            $t->integer('hari_hadir')->default(0);
            $rp($t, 'nominal');
            $rp($t, 'total');
            $t->json('rincian')->nullable(); // hadir dates
            $tech($t);
            $t->unique(['periode_id', 'user_id']);
            $t->index(['user_id']);
        });

        $s->create('hris_pelanggaran', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64);
            $t->date('tanggal');
            $t->string('jenis', 64)->default('');
            $t->string('tingkat', 16)->default('RINGAN'); // RINGAN | SEDANG | BERAT
            $t->integer('sp_ke')->default(0);
            $t->string('keterangan', 255)->default('');
            $t->string('status', 16)->default('AKTIF'); // AKTIF | DIBATALKAN
            $t->string('dibuat_oleh', 120)->default('');
            $tech($t);
            $t->index(['user_id', 'status']);
            $t->index(['tanggal']);
        });

        $s->create('hris_kpi_template', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('divisi', 64);
            $t->date('berlaku_dari');
            $t->string('dibuat_oleh', 120)->default('');
            $tech($t);
            $t->index(['divisi', 'berlaku_dari']);
        });

        $s->create('hris_kpi_item', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('template_id')->constrained('hris_kpi_template')->cascadeOnDelete();
            $t->string('nama', 120);
            $t->decimal('target', 15, 4)->default(0);
            $t->string('arah', 8)->default('NAIK'); // NAIK | TURUN
            $t->decimal('bobot', 10, 4)->default(0);
            $t->string('sumber', 16)->default('MANUAL'); // MANUAL | OTOMATIS
            $t->string('kunci_sumber', 120)->nullable();
            $tech($t);
            $t->index(['template_id']);
        });

        $s->create('hris_kpi_realisasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('item_id')->constrained('hris_kpi_item')->cascadeOnDelete();
            $t->char('bulan', 7); // YYYY-MM
            $t->decimal('nilai', 15, 4)->default(0);
            $t->string('diinput_oleh', 120)->default('');
            $tech($t);
            $t->unique(['item_id', 'bulan']);
            $t->index(['bulan']);
        });

        $s->create('hris_skor_formula', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->unsignedInteger('versi')->unique();
            $t->date('berlaku_dari');
            $t->json('komponen'); // {kehadiran: 25, ...}
            $tech($t);
        });

        $s->create('hris_penilaian_atasan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->char('bulan', 7);
            $t->string('user_id', 64);
            $t->string('penilai_id', 64)->default('');
            $t->json('aspek'); // {aspek: 1..5}
            $tech($t);
            $t->unique(['bulan', 'user_id']);
            $t->index(['user_id']);
        });

        $s->create('hris_skor', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->char('bulan', 7);
            $t->string('user_id', 64);
            $t->unsignedInteger('formula_versi')->default(0);
            $t->json('komponen')->nullable(); // {key: {skor, kelengkapan, detail}}
            $t->decimal('skor', 5, 2)->default(0);
            $t->decimal('kelengkapan', 5, 2)->default(0);
            $t->boolean('terkunci')->default(false);
            $tech($t);
            $t->unique(['bulan', 'user_id']);
            $t->index(['bulan', 'terkunci']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_skor', 'hris_penilaian_atasan', 'hris_skor_formula', 'hris_kpi_realisasi',
            'hris_kpi_item', 'hris_kpi_template', 'hris_pelanggaran', 'hris_rekap_uang_makan',
            'hris_periode', 'hris_tarif_uang_makan'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
