<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 master data for Pembelian & Persediaan (ADR-0006, ADR-0007):
 * Lokasi, Satuan, Pihak + vendor details, Barang with its units, vendors,
 * locations and purchase-price history. Design: docs/erp/pembelian-persediaan.md.
 *
 * No Backend prefix (v2 is one domain model). Every reference is an FK with
 * RESTRICT; master rows are soft-deleted (deleted_at), never hard-deleted.
 * CHECK constraints are added with raw SQL (Blueprint has no check()); MySQL 8
 * and MariaDB 10.11 both enforce them.
 */
return new class extends Migration
{
    private const TABLES = [
        'barang_harga', 'barang_lokasi', 'barang_vendor', 'barang_satuan', 'barang',
        'kategori_barang', 'vendor_hari_tutup', 'vendor', 'pihak_rekening', 'pihak',
        'satuan', 'lokasi',
    ];

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

        $s->create('lokasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('kode', 32)->unique();
            $t->string('nama', 120);
            $t->string('jenis', 32);
            // Fallback business-day cut-off (docs/erp/hari-operasional.md).
            $t->time('jam_batas')->default('05:00:00');
            $t->boolean('aktif')->default(true);
            $tech($t);
        });

        $s->create('satuan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            // utf8mb4_unicode_ci: "ML" and "Ml" are the same unit, as in legacy.
            $t->string('nama', 32)->unique();
            $tech($t);
        });

        $s->create('pihak', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 190);
            $t->string('telepon', 40)->nullable();
            $t->string('catatan', 255)->nullable();
            $tech($t);
            $t->index('nama');
        });

        $s->create('pihak_rekening', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pihak_id')->constrained('pihak')->restrictOnDelete();
            $t->string('bank', 60);
            $t->string('nomor', 40);
            $t->string('atas_nama', 120);
            $t->boolean('utama')->default(false);
            $tech($t);
            $t->unique(['pihak_id', 'bank', 'nomor']);
        });

        // A Pihak that supplies Barang; one row per vendor (1:1 with pihak).
        $s->create('vendor', function (Blueprint $t) use ($tech): void {
            $t->foreignUlid('pihak_id')->primary()->constrained('pihak')->restrictOnDelete();
            $t->string('legacy_id', 190)->nullable()->unique(); // stock.vendors.nama
            $t->boolean('perlu_jadwal_jemput')->default(false);
            $tech($t);
        });

        $s->create('vendor_hari_tutup', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pihak_id')->constrained('vendor', 'pihak_id')->restrictOnDelete();
            $t->unsignedTinyInteger('hari'); // 0 = Minggu … 6 = Sabtu, as legacy tutupHari
            $tech($t, softDelete: false);
            $t->unique(['pihak_id', 'hari']);
        });

        $s->create('kategori_barang', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 64)->unique(); // DRY ITEM, FRESH, CHILLER ITEM, …
            $t->integer('urutan')->default(0);
            $tech($t);
        });

        $s->create('barang', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 190)->nullable()->unique(); // stock.products.nama = hpp_bahan.nama
            $t->string('nama', 190)->unique();
            // Nullable only so the import can land rows still waiting for a decision.
            $t->foreignUlid('satuan_dasar_id')->nullable()->constrained('satuan')->restrictOnDelete();
            $t->foreignUlid('kategori_id')->nullable()->constrained('kategori_barang')->restrictOnDelete();
            $t->string('sumber', 16)->default('vendor');
            $t->boolean('aktif')->default(true); // no longer offered when ordering; data kept
            $tech($t);
        });

        // Valid units of a Barang and their size in its Satuan Dasar, effective-dated.
        $s->create('barang_satuan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->foreignUlid('satuan_id')->constrained('satuan')->restrictOnDelete();
            $t->decimal('ukuran', 15, 4);
            $t->date('berlaku_dari');
            $tech($t, softDelete: false);
            $t->unique(['barang_id', 'satuan_id', 'berlaku_dari']);
        });

        $s->create('barang_vendor', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->foreignUlid('pihak_id')->constrained('vendor', 'pihak_id')->restrictOnDelete();
            $t->unsignedTinyInteger('urutan'); // 0 = vendor utama, 1.. = cadangan
            $tech($t, softDelete: false);
            $t->unique(['barang_id', 'pihak_id']);
            $t->unique(['barang_id', 'urutan']);
        });

        $s->create('barang_lokasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $tech($t, softDelete: false);
            $t->unique(['barang_id', 'lokasi_id']);
        });

        // Purchase price history (HPP "harga beli"): price of qty_beli Satuan Dasar.
        $s->create('barang_harga', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->foreignUlid('pihak_id')->nullable()->constrained('vendor', 'pihak_id')->restrictOnDelete();
            $t->decimal('qty_beli', 15, 4);
            $t->decimal('harga_beli', 15, 0);
            $t->decimal('harga_per_dasar', 15, 4)->storedAs('harga_beli / qty_beli');
            $t->date('berlaku_dari');
            $tech($t, softDelete: false);
            $t->unique(['barang_id', 'berlaku_dari']);
        });

        foreach ([
            'lokasi' => ["jenis IN ('outlet','central_kitchen')"],
            'vendor_hari_tutup' => ['hari BETWEEN 0 AND 6'],
            'barang' => ["sumber IN ('vendor','ck','keduanya')"],
            'barang_satuan' => ['ukuran > 0'],
            'barang_harga' => ['qty_beli > 0', 'harga_beli >= 0'],
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
