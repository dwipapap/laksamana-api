<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Kas (docs/erp/kas.md): every place company money sits is a
 * `dompet` (bank account, brankas cash, Kas Kecil pos), and every amount in
 * or out of one is an `arus_kas` row pointing at exactly one document, as
 * mutasi_stok does for Barang. Balances are never stored.
 *
 * Documents: Kas Kecil, Mutasi Wallet (mutasi_dompet), setoran, Planning
 * Pembayaran (rencana_bayar + pembayaran) and pengembalian modal to an
 * investor. Documents are cancelled (dibatalkan_at), never hard-deleted.
 */
return new class extends Migration
{
    private const TABLES = [
        'arus_kas', 'pengembalian_modal', 'investor', 'pembayaran_pesanan', 'pembayaran', 'rencana_bayar',
        'setoran_hari', 'setoran', 'mutasi_dompet', 'kas_kecil', 'kategori_kas', 'metode_bayar', 'dompet',
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
        // Header of a money document at one Lokasi on one business date.
        $dokumen = function (Blueprint $t, bool $hari = true): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            if ($hari) {
                $t->foreignUlid('hari_operasional_id')->nullable()->constrained('hari_operasional')->restrictOnDelete();
            }
            $t->timestamp('dibatalkan_at')->nullable();
            $t->foreignUlid('dibatalkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('dicatat_oleh_impor', 120)->nullable(); // legacy writer name that matched no User
            $t->index(['lokasi_id', 'tanggal_bisnis']);
        };

        $s->create('dompet', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique(); // kk_pos.id; bank keys are the kode
            $t->string('kode', 32)->unique();
            $t->string('nama', 120);
            $t->string('jenis', 10);
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->string('bank', 60)->nullable();
            $t->string('nomor_rekening', 40)->nullable();
            $t->string('atas_nama', 120)->nullable();
            $t->decimal('saldo_awal', 15, 0)->default(0);
            $t->date('saldo_awal_tanggal')->nullable();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        // Where the takings of one payment method land (legacy RK_GRUP -> wadah).
        $s->create('metode_bayar', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('kode', 32)->unique(); // cash, qr_order, edc_bri, qris_bri, …
            $t->string('nama', 60);
            $t->foreignUlid('dompet_id')->nullable()->constrained('dompet')->restrictOnDelete(); // NULL = not mapped yet
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        $s->create('kategori_kas', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique(); // kk_kategori.id
            $t->string('nama', 120)->unique();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        $s->create('kas_kecil', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t);
            $t->string('keterangan', 255);
            $t->foreignUlid('kategori_kas_id')->nullable()->constrained('kategori_kas')->restrictOnDelete();
            $t->boolean('sudah_dibukukan')->default(false); // legacy `input`
            $t->boolean('ada_bon')->default(false);         // legacy `bon`
            $tech($t);
        });

        $s->create('mutasi_dompet', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t, hari: false);
            $t->string('jenis', 8);
            $t->foreignUlid('dari_dompet_id')->nullable()->constrained('dompet')->restrictOnDelete();
            $t->foreignUlid('ke_dompet_id')->nullable()->constrained('dompet')->restrictOnDelete();
            $t->decimal('nominal', 15, 0);
            $t->string('keterangan', 255)->nullable();
            $tech($t);
        });

        $s->create('setoran', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t);
            $t->foreignUlid('dari_dompet_id')->constrained('dompet')->restrictOnDelete();
            $t->foreignUlid('ke_dompet_id')->nullable()->constrained('dompet')->restrictOnDelete();
            $t->string('tujuan_impor', 80)->nullable(); // legacy free-text bank that matched no dompet
            $t->decimal('nominal', 15, 0);
            $t->string('catatan', 200)->nullable();
            $tech($t);
        });

        $s->create('setoran_hari', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('setoran_id')->constrained('setoran')->cascadeOnDelete();
            $t->date('tanggal_bisnis'); // the day whose cash is deposited
            $t->decimal('nominal', 15, 0);
            $tech($t);
            $t->unique(['setoran_id', 'tanggal_bisnis']);
        });

        // Planning Pembayaran: one sheet per payment date.
        $s->create('rencana_bayar', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bayar');
            $t->string('catatan', 255)->nullable();
            $tech($t);
            $t->unique(['lokasi_id', 'tanggal_bayar']);
        });

        $s->create('pembayaran', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique(); // bk_state.bayar[].id
            $t->foreignUlid('rencana_bayar_id')->constrained('rencana_bayar')->cascadeOnDelete();
            $t->string('keterangan', 255);
            $t->foreignUlid('kategori_kas_id')->nullable()->constrained('kategori_kas')->restrictOnDelete();
            $t->foreignUlid('pihak_id')->nullable()->constrained('pihak')->restrictOnDelete();
            $t->string('pihak_impor', 190)->nullable(); // vendor typed in legacy that matched no Pihak
            $t->foreignUlid('dompet_id')->constrained('dompet')->restrictOnDelete(); // dibayar dari
            $t->decimal('nominal', 15, 0);
            $t->date('jatuh_tempo')->nullable();
            $t->string('catatan', 255)->nullable();
            $t->string('status', 12)->default('dijadwalkan');
            $t->timestamp('dibayar_at')->nullable();
            $t->foreignUlid('dibayar_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->timestamp('bukti_at')->nullable(); // transfer proof: who and when, apart from the status
            $t->foreignUlid('bukti_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('bukti_oleh_impor', 120)->nullable();
            $t->timestamp('dibatalkan_at')->nullable();
            $t->foreignUlid('dibatalkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
        });

        // A Tagihan Vendor may cover several Pesanan Bahan (P4).
        $s->create('pembayaran_pesanan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pembayaran_id')->constrained('pembayaran')->cascadeOnDelete();
            $t->foreignUlid('pesanan_bahan_id')->constrained('pesanan_bahan')->restrictOnDelete();
            $tech($t);
            $t->unique(['pembayaran_id', 'pesanan_bahan_id']);
        });

        $s->create('investor', function (Blueprint $t) use ($tech): void {
            $t->foreignUlid('pihak_id')->primary()->constrained('pihak')->restrictOnDelete();
            $t->string('legacy_id', 64)->nullable()->unique(); // bk_state.investor[].id
            $t->decimal('modal', 15, 0)->default(0);
            $t->decimal('kepemilikan', 5, 2)->nullable(); // percent
            $t->date('target_kembali')->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('pengembalian_modal', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t, hari: false);
            $t->foreignUlid('investor_id')->constrained('investor', 'pihak_id')->restrictOnDelete();
            // NULL = legacy row without a source; it moves no money and is reported.
            $t->foreignUlid('dompet_id')->nullable()->constrained('dompet')->restrictOnDelete();
            $t->decimal('nominal', 15, 0);
            $tech($t);
        });

        $s->create('arus_kas', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->foreignUlid('dompet_id')->constrained('dompet')->restrictOnDelete();
            $t->string('arah', 8);
            $t->decimal('nominal', 15, 0);
            $t->date('tanggal_bisnis');
            $t->foreignUlid('hari_operasional_id')->nullable()->constrained('hari_operasional')->restrictOnDelete();
            $t->string('sebab', 20);
            // Exactly one origin, matching sebab (CHECK below). RESTRICT: a document
            // that moved money is cancelled, and its rows removed by the service first.
            $t->foreignUlid('kas_kecil_id')->nullable()->constrained('kas_kecil')->restrictOnDelete();
            $t->foreignUlid('mutasi_dompet_id')->nullable()->constrained('mutasi_dompet')->restrictOnDelete();
            $t->foreignUlid('setoran_id')->nullable()->constrained('setoran')->restrictOnDelete();
            $t->foreignUlid('pembayaran_id')->nullable()->unique()->constrained('pembayaran')->restrictOnDelete();
            $t->foreignUlid('pengembalian_modal_id')->nullable()->unique()->constrained('pengembalian_modal')->restrictOnDelete();
            $tech($t);
            $t->index(['dompet_id', 'tanggal_bisnis']);
            $t->unique(['kas_kecil_id', 'dompet_id', 'arah']);
            $t->unique(['mutasi_dompet_id', 'arah']);
            $t->unique(['setoran_id', 'arah']);
        });

        foreach ([
            'dompet' => ["jenis IN ('bank','tunai','kas_kecil')"],
            'mutasi_dompet' => [
                "jenis IN ('pindah','masuk','keluar')",
                'nominal > 0',
                "(dari_dompet_id IS NOT NULL) = (jenis IN ('pindah','keluar'))",
                "(ke_dompet_id IS NOT NULL) = (jenis IN ('pindah','masuk'))",
                "jenis <> 'pindah' OR dari_dompet_id <> ke_dompet_id",
            ],
            'setoran' => ['nominal > 0', 'ke_dompet_id IS NULL OR ke_dompet_id <> dari_dompet_id'],
            'setoran_hari' => ['nominal > 0'],
            'pembayaran' => [
                'nominal > 0',
                "status IN ('dijadwalkan','dibayar')",
                "(status = 'dibayar') = (dibayar_at IS NOT NULL)",
            ],
            'investor' => ['modal >= 0', 'kepemilikan IS NULL OR kepemilikan BETWEEN 0 AND 100'],
            'pengembalian_modal' => ['nominal > 0'],
            'arus_kas' => [
                "arah IN ('masuk','keluar')",
                'nominal > 0',
                "sebab IN ('kas_kecil','mutasi_dompet','setoran','pembayaran','pengembalian_modal')",
                '(kas_kecil_id IS NOT NULL) + (mutasi_dompet_id IS NOT NULL) + (setoran_id IS NOT NULL)'
                    .' + (pembayaran_id IS NOT NULL) + (pengembalian_modal_id IS NOT NULL) = 1',
                "(sebab = 'kas_kecil') = (kas_kecil_id IS NOT NULL)",
                "(sebab = 'mutasi_dompet') = (mutasi_dompet_id IS NOT NULL)",
                "(sebab = 'setoran') = (setoran_id IS NOT NULL)",
                "(sebab = 'pembayaran') = (pembayaran_id IS NOT NULL)",
                "(sebab = 'pengembalian_modal') = (pengembalian_modal_id IS NOT NULL)",
                // money paid out never comes back in through the same document
                "sebab NOT IN ('pembayaran','pengembalian_modal') OR arah = 'keluar'",
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
