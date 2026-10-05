<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 documents for Pembelian & Persediaan, following the legacy flow
 * (docs/erp/pembelian-persediaan.md "Alur lama yang diikuti" and "ERD
 * dokumen"): Pesanan Bahan, the stock ledger (today only the Central Kitchen
 * keeps one), CK kiriman/produksi, Serah Terima, Pemakaian, Waste, Opname and
 * Penyesuaian Stok, plus Hari Operasional (docs/erp/hari-operasional.md).
 * Vendor payments (rencana_bayar, tagihan_vendor) belong to the Kas area.
 *
 * Documents are never hard-deleted: cancellation is dibatalkan_at/_oleh.
 * CASCADE only from a document to its own lines (ADR-0007).
 * `*_impor` columns hold legacy free text that matched no row; v2 never writes them.
 */
return new class extends Migration
{
    private const TABLES = [
        'mutasi_stok', 'penyesuaian_stok_baris', 'penyesuaian_stok', 'opname_baris', 'opname',
        'waste', 'pemakaian_baris', 'pemakaian', 'serah_terima_baris', 'serah_terima',
        'produksi_ck_baris', 'produksi_ck', 'kiriman_ck_baris', 'kiriman_ck',
        'pesanan_bahan_baris', 'pesanan_bahan', 'hari_operasional',
    ];

    public function up(): void
    {
        $s = Schema::connection('core');

        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        // Header of a stock document at one Lokasi on one business date.
        $dokumen = function (Blueprint $t, bool $batal = true): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->foreignUlid('hari_operasional_id')->nullable()->constrained('hari_operasional')->restrictOnDelete();
            $t->string('catatan', 500)->nullable();
            if ($batal) {
                $t->timestamp('dibatalkan_at')->nullable();
                $t->foreignUlid('dibatalkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            }
            $t->index(['lokasi_id', 'tanggal_bisnis']);
        };
        // A quantity of one Barang as entered, and in its Satuan Dasar (NULL = size unknown).
        $qty = function (Blueprint $t): void {
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->decimal('qty_input', 15, 4);
            $t->foreignUlid('satuan_input_id')->constrained('satuan')->restrictOnDelete();
            $t->decimal('qty_dasar', 15, 4)->nullable();
        };
        $baris = function (string $parent) use ($s, $tech, $qty): void {
            $s->create("{$parent}_baris", function (Blueprint $t) use ($parent, $tech, $qty): void {
                $t->ulid('id')->primary();
                $t->foreignUlid("{$parent}_id")->constrained($parent)->cascadeOnDelete();
                $qty($t);
                $tech($t);
            });
        };

        $s->create('hari_operasional', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->timestamp('dibuka_at');
            $t->foreignUlid('dibuka_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->timestamp('ditutup_at')->nullable();
            $t->foreignUlid('ditutup_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('status', 8)->default('buka');
            $t->boolean('ditutup_otomatis')->default(false);
            $t->string('catatan', 500)->nullable();
            // At most one open day per Lokasi: unique, NULL once closed.
            $t->ulid('lokasi_buka')->nullable()->storedAs("CASE WHEN status = 'buka' THEN lokasi_id END")->unique();
            $tech($t);
            $t->unique(['lokasi_id', 'tanggal_bisnis']);
        });

        $s->create('pesanan_bahan', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t, batal: false); // lines are cancelled one by one, as in legacy
            $t->string('nama', 120)->nullable(); // legacy batch_name
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string('divisi_impor', 40)->nullable(); // legacy `tim` that matched no Divisi
            $t->foreignUlid('diajukan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('diajukan_oleh_impor', 120)->nullable(); // legacy `pic`
            $tech($t);
        });

        $s->create('pesanan_bahan_baris', function (Blueprint $t) use ($qty, $tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique(); // stock.orders.nomor_order
            $t->foreignUlid('pesanan_bahan_id')->constrained('pesanan_bahan')->cascadeOnDelete();
            $qty($t);
            $t->string('sumber', 8);
            // Written when processed, so changing the vendor utama never rewrites history.
            $t->foreignUlid('vendor_id')->nullable()->constrained('vendor', 'pihak_id')->restrictOnDelete();
            $t->string('status', 10)->default('diajukan');
            $t->date('tanggal_butuh')->nullable();
            $t->date('tanggal_jemput')->nullable();
            $t->timestamp('diterima_at')->nullable();
            $t->foreignUlid('diterima_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('catatan', 500)->nullable();
            $t->string('catatan_terima', 500)->nullable();
            $t->timestamp('diarsipkan_at')->nullable();
            $tech($t);
            $t->index(['status', 'tanggal_butuh']);
        });

        $s->create('kiriman_ck', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t); // lokasi_id = the sender (an outlet); it lands at ke_lokasi_id
            $t->foreignUlid('ke_lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->string('pengirim_impor', 120)->nullable();
            $tech($t);
        });
        $baris('kiriman_ck');

        $s->create('produksi_ck', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t);
            $tech($t);
        });
        $baris('produksi_ck');

        $s->create('serah_terima', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t);
            $t->foreignUlid('tujuan_divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->string('tujuan_impor', 40)->nullable();
            $t->foreignUlid('penerima_id')->nullable()->constrained('user')->nullOnDelete();
            $t->string('penerima_impor', 120)->nullable();
            $t->string('foto_key', 190)->nullable();
            $tech($t);
        });
        $baris('serah_terima');

        $s->create('pemakaian', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t);
            $t->string('jenis', 16);
            $t->string('nama_acara', 190)->nullable();
            $t->string('status', 8)->default('draf');
            $tech($t);
        });
        $baris('pemakaian');

        $s->create('waste', function (Blueprint $t) use ($dokumen, $qty, $tech): void {
            $dokumen($t);
            $qty($t);
            $t->string('sebab', 16);
            $t->string('foto_key', 190)->nullable();
            $tech($t);
        });

        $s->create('opname', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t, batal: false);
            $t->string('status', 8)->default('draf');
            $t->foreignUlid('dihitung_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
        });
        $s->create('opname_baris', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('opname_id')->constrained('opname')->cascadeOnDelete();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->decimal('qty_sistem', 15, 4)->nullable(); // NULL = not counted, unlike 0
            $t->decimal('qty_fisik', 15, 4)->nullable();
            $t->string('catatan', 500)->nullable();
            $tech($t);
            $t->unique(['opname_id', 'barang_id']);
        });

        $s->create('penyesuaian_stok', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t, batal: false); // a ratified correction is corrected by a new one
            $t->foreignUlid('opname_id')->nullable()->constrained('opname')->restrictOnDelete();
            $t->string('status', 10)->default('draf');
            $t->timestamp('disahkan_at')->nullable();
            $t->foreignUlid('disahkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
        });
        $s->create('penyesuaian_stok_baris', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('penyesuaian_stok_id')->constrained('penyesuaian_stok')->cascadeOnDelete();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->decimal('qty_dasar', 15, 4); // + adds stock, - removes it
            $t->string('alasan', 16);
            $t->string('catatan', 500)->nullable();
            $tech($t);
        });

        // The stock ledger. Balance = SUM(masuk) - SUM(keluar), never stored.
        $s->create('mutasi_stok', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique(); // stock.ck_stock.id
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            $t->string('arah', 6);
            $t->decimal('qty_dasar', 15, 4);
            $t->date('tanggal_bisnis');
            $t->string('sebab', 12);
            // Exactly one origin, matching `sebab`; each origin moves stock once.
            $t->foreignUlid('pesanan_bahan_baris_id')->nullable()->unique()->constrained('pesanan_bahan_baris')->restrictOnDelete();
            $t->foreignUlid('kiriman_ck_baris_id')->nullable()->unique()->constrained('kiriman_ck_baris')->restrictOnDelete();
            $t->foreignUlid('produksi_ck_baris_id')->nullable()->unique()->constrained('produksi_ck_baris')->restrictOnDelete();
            $t->foreignUlid('penyesuaian_stok_baris_id')->nullable()->unique()->constrained('penyesuaian_stok_baris')->restrictOnDelete();
            $t->foreignUlid('waste_id')->nullable()->unique()->constrained('waste')->restrictOnDelete();
            $tech($t);
            $t->index(['lokasi_id', 'barang_id', 'tanggal_bisnis']);
        });

        foreach ([
            'hari_operasional' => [
                "status IN ('buka','tutup')",
                "(status = 'buka') = (ditutup_at IS NULL)",
                'ditutup_at IS NULL OR ditutup_at >= dibuka_at',
            ],
            'pesanan_bahan_baris' => [
                "sumber IN ('vendor','ck')",
                "status IN ('diajukan','datang','batal')",
                "sumber = 'vendor' OR vendor_id IS NULL",
                'qty_input > 0',
            ],
            'pemakaian' => ["jenis IN ('rnd','prasmanan')", "status IN ('draf','selesai')"],
            'waste' => ["sebab IN ('kadaluarsa','rusak','sisa_produksi','lainnya')", 'qty_input > 0'],
            'opname' => ["status IN ('draf','selesai')"],
            'penyesuaian_stok' => [
                "status IN ('draf','disahkan')",
                "(status = 'disahkan') = (disahkan_at IS NOT NULL)",
            ],
            'penyesuaian_stok_baris' => [
                "alasan IN ('rusak','kadaluarsa','salah_catat','hilang','lainnya','impor')",
                'qty_dasar <> 0',
            ],
            'mutasi_stok' => [
                "arah IN ('masuk','keluar')",
                'qty_dasar > 0',
                "sebab IN ('pengajuan','kiriman','produksi','penyesuaian','waste')",
                '(pesanan_bahan_baris_id IS NOT NULL) + (kiriman_ck_baris_id IS NOT NULL) + (produksi_ck_baris_id IS NOT NULL)'
                    .' + (penyesuaian_stok_baris_id IS NOT NULL) + (waste_id IS NOT NULL) = 1',
                "(sebab = 'pengajuan') = (pesanan_bahan_baris_id IS NOT NULL)",
                "(sebab = 'kiriman') = (kiriman_ck_baris_id IS NOT NULL)",
                "(sebab = 'produksi') = (produksi_ck_baris_id IS NOT NULL)",
                "(sebab = 'penyesuaian') = (penyesuaian_stok_baris_id IS NOT NULL)",
                "(sebab = 'waste') = (waste_id IS NOT NULL)",
            ],
        ] as $table => $checks) {
            foreach ($checks as $i => $expr) {
                DB::connection('core')->statement(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_chk_{$i}` CHECK ({$expr})"
                );
            }
        }
        foreach (['kiriman_ck_baris', 'produksi_ck_baris', 'serah_terima_baris', 'pemakaian_baris'] as $table) {
            DB::connection('core')->statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_chk_0` CHECK (qty_input > 0)");
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
