<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Proyek & PO Proyek (docs/erp/po-proyek.md), from the BD blobs:
 * projects with several PICs, weekly purchase requests with their approvers,
 * and PO lines (optionally for a project, optionally a catalogue Barang)
 * with what was really spent. A different flow from Pesanan Bahan (Q4).
 */
return new class extends Migration
{
    private const TABLES = ['po_proyek', 'pengajuan_pembelian_penyetuju', 'pengajuan_pembelian', 'proyek_pic', 'proyek'];

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
        $divisi = function (Blueprint $t): void {
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string('divisi_impor', 64)->nullable(); // legacy `div` that matched no Divisi
        };

        $s->create('proyek', function (Blueprint $t) use ($divisi, $tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nama', 190);
            $t->string('jenis', 60)->nullable();
            $t->string('tahap', 10)->default('idea');
            $t->string('kesehatan', 10)->nullable();
            $divisi($t);
            $t->date('mulai')->nullable();
            $t->date('selesai')->nullable();
            $t->decimal('anggaran', 15, 0)->default(0);
            $t->text('deskripsi')->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('proyek_pic', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->unsignedTinyInteger('urutan')->default(0); // 0 = the lead PIC
            $tech($t);
            $t->unique(['proyek_id', 'user_id']);
        });

        // The weekly PR sheet; its lines are the po_proyek rows pointing back at it.
        $s->create('pengajuan_pembelian', function (Blueprint $t) use ($divisi, $tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('pengaju_id')->nullable()->constrained('user')->restrictOnDelete();
            $t->string('pengaju_impor', 120)->nullable();
            $divisi($t);
            $t->date('tanggal');
            $t->date('minggu_mulai'); // Monday
            $t->string('status', 10)->default('draf');
            $t->timestamp('diajukan_at')->nullable();
            $t->timestamp('selesai_at')->nullable();
            $t->string('catatan', 500)->nullable();
            $tech($t);
        });

        $s->create('pengajuan_pembelian_penyetuju', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pengajuan_pembelian_id')->constrained('pengajuan_pembelian')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('user')->restrictOnDelete();
            $t->string('nama_impor', 120)->nullable(); // legacy approver that matched no User
            $t->unsignedTinyInteger('urutan');
            $t->timestamp('disetujui_at')->nullable();
            $t->foreignUlid('disetujui_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
            $t->unique(['pengajuan_pembelian_id', 'user_id']);
            $t->unique(['pengajuan_pembelian_id', 'urutan']);
        });

        $s->create('po_proyek', function (Blueprint $t) use ($divisi, $tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('pengajuan_pembelian_id')->nullable()->constrained('pengajuan_pembelian')->restrictOnDelete();
            $t->foreignUlid('proyek_id')->nullable()->constrained('proyek')->restrictOnDelete();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->foreignUlid('barang_id')->nullable()->constrained('barang')->restrictOnDelete();
            $t->string('nama_barang', 190); // BD buys far beyond the ingredient catalogue
            $t->decimal('qty', 15, 4)->nullable();
            $t->foreignUlid('satuan_id')->nullable()->constrained('satuan')->restrictOnDelete();
            $t->string('satuan_impor', 32)->nullable();
            $t->decimal('nominal', 15, 0)->default(0);      // requested
            $t->decimal('realisasi', 15, 0)->nullable();    // really spent; NULL = not bought yet, 0 is a price
            $t->foreignUlid('vendor_id')->nullable()->constrained('pihak')->restrictOnDelete();
            $t->string('vendor_impor', 190)->nullable();
            $divisi($t);
            $t->foreignUlid('pic_id')->nullable()->constrained('user')->restrictOnDelete();
            $t->string('pic_impor', 120)->nullable();
            $t->date('butuh_tanggal')->nullable();
            $t->string('status', 10)->default('draf');
            $t->string('sumber', 10)->default('bd');
            $t->timestamp('disetujui_at')->nullable();
            $t->timestamp('dibeli_at')->nullable();
            $t->timestamp('diproses_at')->nullable();
            $t->foreignUlid('diproses_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('catatan', 500)->nullable();
            $t->timestamp('dibatalkan_at')->nullable();
            $t->foreignUlid('dibatalkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
            $t->index(['status', 'butuh_tanggal']);
        });

        foreach ([
            'proyek' => [
                "tahap IN ('idea','planning','running','review','completed')",
                "kesehatan IS NULL OR kesehatan IN ('on_track','at_risk','off_track')",
                'selesai IS NULL OR mulai IS NULL OR selesai >= mulai',
                'anggaran >= 0',
            ],
            'pengajuan_pembelian' => [
                "status IN ('draf','diajukan','disetujui','selesai')",
                'DAYOFWEEK(minggu_mulai) = 2',
                "(status <> 'draf') = (diajukan_at IS NOT NULL)",
            ],
            'po_proyek' => [
                "status IN ('draf','diajukan','disetujui','dibeli','diterima')",
                "sumber IN ('bd','marketing')",
                'nominal >= 0',
                'realisasi IS NULL OR realisasi >= 0',
                'qty IS NULL OR qty > 0',
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
