<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 HR (docs/erp/hr.md): the inputs of the People Score (KPI per
 * Divisi, monthly manual inputs, layered reviews, guest feedback,
 * violations, Talenta attendance), its effective-dated settings, and the
 * score copied when a month is closed. The score itself is computed by a
 * service, as the legacy screen did in the browser.
 */
return new class extends Migration
{
    private const TABLES = [
        'skor_kinerja', 'kehadiran_talenta', 'impor_kehadiran', 'pelanggaran', 'feedback_kinerja',
        'review_kinerja_nilai', 'review_kinerja', 'input_bulanan_hr', 'kpi_realisasi', 'kpi_item', 'kpi_template',
        'pengaturan_hr_potongan', 'pengaturan_hr_grade', 'pengaturan_hr',
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
        // A Karyawan is keyed by user_id; RESTRICT so a person's HR history stays.
        $karyawan = fn (Blueprint $t) => $t->foreignUlid('user_id')->constrained('karyawan', 'user_id')->restrictOnDelete();
        $skor = fn (Blueprint $t, string $c) => $t->decimal($c, 5, 2)->nullable();

        $s->create('pengaturan_hr', function (Blueprint $t) use ($skor, $tech): void {
            $t->ulid('id')->primary();
            $t->date('berlaku_dari')->unique();
            foreach (['kehadiran', 'kpi', 'pelatihan', 'review', 'disiplin', 'teamwork', 'inisiatif'] as $k) {
                $t->decimal("bobot_{$k}", 6, 2)->default(0);
            }
            $skor($t, 'nilai_bawaan');
            $t->unsignedTinyInteger('ambang_kelengkapan')->default(40);
            foreach (['self', 'manager', 'hr', 'ceo'] as $l) {
                $t->decimal("bobot_review_{$l}", 6, 2)->default(0);
            }
            $t->decimal('bobot_kehadiran_hadir', 6, 2)->default(70);
            $t->decimal('bobot_kehadiran_tepat', 6, 2)->default(30);
            $tech($t);
        });

        $s->create('pengaturan_hr_grade', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pengaturan_hr_id')->constrained('pengaturan_hr')->cascadeOnDelete();
            $t->string('grade', 4);
            $t->decimal('skor_min', 5, 2);
            $tech($t);
            $t->unique(['pengaturan_hr_id', 'grade']);
        });

        $s->create('pengaturan_hr_potongan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pengaturan_hr_id')->constrained('pengaturan_hr')->cascadeOnDelete();
            $t->string('tingkat', 32); // violation severity
            $t->decimal('potongan', 5, 2);
            $tech($t);
            $t->unique(['pengaturan_hr_id', 'tingkat']);
        });

        $s->create('kpi_template', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('divisi_id')->constrained('divisi')->restrictOnDelete();
            $t->date('berlaku_dari');
            $tech($t);
            $t->unique(['divisi_id', 'berlaku_dari']);
        });

        $s->create('kpi_item', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('kpi_template_id')->constrained('kpi_template')->restrictOnDelete();
            $t->string('nama', 190);
            $t->string('satuan', 32)->nullable();
            $t->decimal('target', 15, 4);
            $t->string('arah', 5)->default('naik'); // turun = lower is better
            $t->decimal('bobot', 6, 2)->default(1);
            $t->integer('urutan')->default(0);
            $tech($t);
        });

        $s->create('kpi_realisasi', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('kpi_item_id')->constrained('kpi_item')->restrictOnDelete();
            $t->date('bulan');
            $t->decimal('nilai', 15, 4);
            $tech($t);
            $t->unique(['kpi_item_id', 'bulan']);
        });

        $s->create('input_bulanan_hr', function (Blueprint $t) use ($legacy, $karyawan, $skor, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $karyawan($t);
            $t->date('bulan');
            foreach (['kehadiran', 'review', 'teamwork', 'inisiatif'] as $k) {
                $skor($t, $k); // NULL = not entered, the primary source wins anyway
            }
            $t->decimal('kpi_override', 5, 2)->nullable(); // 0..120
            $t->string('catatan', 500)->nullable();
            $tech($t);
            $t->unique(['user_id', 'bulan']);
        });

        $s->create('review_kinerja', function (Blueprint $t) use ($legacy, $karyawan, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $karyawan($t);
            $t->date('bulan');
            $tech($t);
            $t->unique(['user_id', 'bulan']);
        });

        $s->create('review_kinerja_nilai', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('review_kinerja_id')->constrained('review_kinerja')->cascadeOnDelete();
            $t->string('lapisan', 8);
            $t->string('aspek', 40);
            $t->unsignedTinyInteger('nilai'); // 1..10
            $t->foreignUlid('penilai_id')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
            $t->unique(['review_kinerja_id', 'lapisan', 'aspek']);
        });

        $s->create('feedback_kinerja', function (Blueprint $t) use ($legacy, $karyawan, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $karyawan($t);
            $t->date('tanggal');
            $t->string('jenis', 8);
            $t->string('catatan', 500)->nullable();
            $tech($t);
            $t->index(['user_id', 'tanggal']);
        });

        $s->create('pelanggaran', function (Blueprint $t) use ($legacy, $karyawan, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $karyawan($t);
            $t->date('tanggal');
            $t->string('jenis', 60)->nullable();
            $t->string('tingkat', 32);
            $t->string('sp', 8)->nullable(); // surat peringatan level, if any
            $t->string('status', 10)->default('aktif');
            $t->string('catatan', 500)->nullable();
            $tech($t);
            $t->index(['user_id', 'tanggal']);
        });

        $s->create('impor_kehadiran', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->date('bulan')->unique();
            $t->timestamp('diimpor_at');
            $t->foreignUlid('diimpor_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('diimpor_oleh_impor', 120)->nullable();
            $t->string('berkas_key', 190)->nullable();
            $tech($t);
        });

        $s->create('kehadiran_talenta', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('impor_kehadiran_id')->constrained('impor_kehadiran')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('karyawan', 'user_id')->restrictOnDelete();
            $t->string('talenta_id', 32);
            $t->date('tanggal');
            $t->string('status', 40)->nullable(); // Talenta's own code
            $t->time('jam_masuk')->nullable();
            $t->time('jam_pulang')->nullable();
            $t->unsignedSmallInteger('telat_menit')->default(0);
            $t->boolean('hari_kerja')->default(true);
            $t->decimal('kredit', 4, 2)->default(0); // 1 = present, 0.5 = half day, …
            $t->json('baris_asli')->nullable();
            $tech($t);
            $t->unique(['impor_kehadiran_id', 'talenta_id', 'tanggal']);
        });

        $s->create('skor_kinerja', function (Blueprint $t) use ($karyawan, $skor, $tech): void {
            $t->ulid('id')->primary();
            $karyawan($t);
            $t->date('bulan');
            $t->decimal('skor', 5, 2);
            $t->string('grade', 4)->nullable(); // NULL = data kurang
            $t->unsignedTinyInteger('kelengkapan');
            foreach (['kehadiran', 'kpi', 'pelatihan', 'review', 'disiplin', 'teamwork', 'inisiatif'] as $k) {
                $skor($t, $k); // NULL = no data, the default was used
            }
            $t->timestamp('ditutup_at');
            $t->foreignUlid('ditutup_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
            $t->unique(['user_id', 'bulan']);
        });

        $bulan = 'DAYOFMONTH(bulan) = 1';
        $komponen = fn (array $cols) => array_map(fn ($c) => "{$c} IS NULL OR {$c} BETWEEN 0 AND 100", $cols);
        foreach ([
            'pengaturan_hr' => [
                'ambang_kelengkapan BETWEEN 0 AND 100',
                'nilai_bawaan IS NULL OR nilai_bawaan BETWEEN 0 AND 100',
            ],
            'pengaturan_hr_grade' => ['skor_min BETWEEN 0 AND 120'],
            'pengaturan_hr_potongan' => ['potongan BETWEEN 0 AND 100'],
            'kpi_item' => ["arah IN ('naik','turun')", 'bobot >= 0'],
            'kpi_realisasi' => [$bulan],
            'input_bulanan_hr' => [$bulan, ...$komponen(['kehadiran', 'review', 'teamwork', 'inisiatif']), 'kpi_override IS NULL OR kpi_override BETWEEN 0 AND 120'],
            'review_kinerja' => [$bulan],
            'review_kinerja_nilai' => ["lapisan IN ('self','manager','hr','ceo')", 'nilai BETWEEN 1 AND 10'],
            'feedback_kinerja' => ["jenis IN ('positif','negatif')"],
            'pelanggaran' => ["status IN ('aktif','dibatalkan')"],
            'impor_kehadiran' => [$bulan],
            'kehadiran_talenta' => ['kredit BETWEEN 0 AND 1'],
            'skor_kinerja' => [$bulan, 'skor BETWEEN 0 AND 120', 'kelengkapan BETWEEN 0 AND 100',
                ...$komponen(['kehadiran', 'kpi', 'pelatihan', 'review', 'disiplin', 'teamwork', 'inisiatif'])],
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
