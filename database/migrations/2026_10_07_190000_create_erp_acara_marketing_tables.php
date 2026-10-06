<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Acara Marketing (docs/erp/acara-marketing.md), from the marketing
 * blobs: a Klien's booking of the venue and F&B moving through the sales
 * pipeline, its quotation lines, payments, approvals and tasks, and the
 * follow-ups on Klien.
 */
return new class extends Migration
{
    private const TABLES = [
        'tindak_lanjut_klien', 'acara_tugas', 'template_tugas_acara', 'acara_persetujuan',
        'acara_pembayaran', 'acara_rincian', 'acara',
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
        $orang = function (Blueprint $t, string $kolom): void {
            $t->foreignUlid("{$kolom}_id")->nullable()->constrained('user')->restrictOnDelete();
            $t->string("{$kolom}_impor", 120)->nullable();
        };

        $s->create('acara', function (Blueprint $t) use ($legacy, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('klien_id')->nullable()->constrained('klien', 'pihak_id')->restrictOnDelete();
            $t->string('klien_impor', 190)->nullable();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->string('nama', 190);
            $t->string('jenis', 60)->nullable();
            $t->date('tanggal')->nullable();
            $t->date('tanggal_selesai')->nullable(); // multi-day
            $t->unsignedInteger('pax')->default(0);
            $t->decimal('budget_per_pax', 15, 0)->nullable();
            $t->decimal('sewa_venue', 15, 0)->nullable();
            $t->decimal('deposit_nominal', 15, 0)->nullable();
            $t->boolean('pajak_termasuk')->default(false);
            $t->boolean('service_berlaku')->default(true);
            $t->string('status', 10)->default('lead');
            $orang($t, 'pic_marketing');
            $t->timestamp('invoice_terkirim_at')->nullable();
            $t->json('brief')->nullable(); // read-only brief: area, layout, images, highlight, decor, menu
            $tech($t, softDelete: true);
            $t->index(['status', 'tanggal']);
        });

        $s->create('acara_rincian', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('acara_id')->constrained('acara')->cascadeOnDelete();
            $t->unsignedSmallInteger('urutan');
            $t->string('deskripsi', 255);
            $t->decimal('jumlah', 15, 4)->default(0);
            $t->decimal('harga', 15, 0)->default(0);
            $t->string('jenis', 8)->default('bayar'); // bayar | gratis | tbc
            $tech($t);
            $t->unique(['acara_id', 'urutan']);
        });

        $s->create('acara_pembayaran', function (Blueprint $t) use ($legacy, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('acara_id')->constrained('acara')->restrictOnDelete();
            $t->string('nomor_kwitansi', 40)->unique();
            $t->string('jenis', 20)->default('DP'); // DP, pelunasan, … (legacy free list)
            $t->decimal('nominal', 15, 0);
            $t->foreignUlid('metode_bayar_id')->nullable()->constrained('metode_bayar')->restrictOnDelete();
            $t->string('metode_impor', 60)->nullable();
            $t->date('tanggal');
            $t->string('bukti_key', 190)->nullable();
            $t->boolean('terverifikasi')->default(false);
            $orang($t, 'dicatat_oleh');
            $t->timestamp('dibatalkan_at')->nullable();
            $t->foreignUlid('dibatalkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
        });

        $s->create('acara_persetujuan', function (Blueprint $t) use ($legacy, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('acara_id')->constrained('acara')->restrictOnDelete();
            $t->string('status', 10)->default('menunggu');
            $orang($t, 'diajukan_oleh');
            $orang($t, 'penyetuju');
            $orang($t, 'penyetuju_kedua');
            $t->string('alasan', 500)->nullable();
            $t->string('catatan', 500)->nullable();
            $t->timestamp('diputuskan_at')->nullable();
            $t->boolean('otomatis')->default(false);
            $tech($t);
        });

        $s->create('template_tugas_acara', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string('tugas', 255);
            $t->string('sub', 255)->nullable();
            $t->smallInteger('h_minus')->default(0); // days before the event (negative = after)
            $t->string('catatan', 500)->nullable();
            $t->integer('urutan')->default(0);
            $tech($t, softDelete: true);
        });

        $s->create('acara_tugas', function (Blueprint $t) use ($orang, $tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('acara_id')->constrained('acara')->cascadeOnDelete();
            $t->foreignUlid('template_tugas_acara_id')->nullable()->constrained('template_tugas_acara')->nullOnDelete();
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string('tugas', 255);
            $t->string('sub', 255)->nullable();
            $orang($t, 'pic');
            $t->date('tenggat')->nullable();
            $t->string('status', 12)->default('pending');
            $tech($t);
        });

        $s->create('tindak_lanjut_klien', function (Blueprint $t) use ($legacy, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('klien_id')->nullable()->constrained('klien', 'pihak_id')->restrictOnDelete();
            $t->foreignUlid('acara_id')->nullable()->constrained('acara')->restrictOnDelete();
            $t->string('aksi', 60)->nullable();
            $t->string('hasil', 255)->nullable();
            $t->text('catatan')->nullable();
            $t->date('berikutnya')->nullable(); // next follow-up date
            $orang($t, 'oleh');
            $t->timestamp('dicatat_at');
            $tech($t);
            $t->index('berikutnya');
        });

        foreach ([
            'acara' => [
                "status IN ('lead','prospect','approval','quotation','deal','selesai','lost')",
                'tanggal_selesai IS NULL OR tanggal IS NULL OR tanggal_selesai >= tanggal',
                'budget_per_pax IS NULL OR budget_per_pax >= 0',
                'sewa_venue IS NULL OR sewa_venue >= 0',
                'deposit_nominal IS NULL OR deposit_nominal >= 0',
            ],
            'acara_rincian' => [
                "jenis IN ('bayar','gratis','tbc')",
                'jumlah >= 0', 'harga >= 0',
                "jenis = 'bayar' OR harga = 0",
            ],
            'acara_pembayaran' => ['nominal > 0'],
            'acara_persetujuan' => [
                "status IN ('menunggu','disetujui','ditolak')",
                "(status = 'menunggu') = (diputuskan_at IS NULL)",
            ],
            'acara_tugas' => ["status IN ('pending','on_progress','done')"],
            'tindak_lanjut_klien' => ['klien_id IS NOT NULL OR acara_id IS NOT NULL'],
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
