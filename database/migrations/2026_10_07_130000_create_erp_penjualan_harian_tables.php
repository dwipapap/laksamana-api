<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Penjualan Harian (docs/erp/penjualan-harian.md), from the Kompas
 * blob: Input Omset Harian with its breakdown per source and PIC, the
 * cashier's Report Daily per Metode Bayar, compliments, guest bons, voids,
 * sales targets and void tax/service settings.
 *
 * Extends Kas: arus_kas gets sebab `omset` (takings landing in a dompet,
 * from a Report Daily line), and setoran_hari points at the Report Daily
 * whose cash it deposits.
 */
return new class extends Migration
{
    private const TABLES = [
        'target_omset_pic', 'target_omset', 'pengaturan_penjualan', 'void_item', 'bon', 'compliment',
        'laporan_kasir_bayar', 'laporan_kasir', 'omset_porsi', 'omset_harian',
    ];

    public function up(): void
    {
        $s = Schema::connection('core');
        $db = DB::connection('core');

        // ADR-0007 technical columns; created_by/updated_by FK `user`.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        // One record per Lokasi per business date (Input Omset Harian, Report Daily).
        $harian = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 16)->nullable()->unique(); // the legacy date key
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->foreignUlid('hari_operasional_id')->nullable()->constrained('hari_operasional')->restrictOnDelete();
            $t->json('jejak')->nullable(); // [{user_id, nama, at}] appended on every save
            $t->unique(['lokasi_id', 'tanggal_bisnis']);
        };
        // A document with its own number, cancelled rather than deleted.
        $dokumen = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->foreignUlid('hari_operasional_id')->nullable()->constrained('hari_operasional')->restrictOnDelete();
            $t->timestamp('dibatalkan_at')->nullable();
            $t->foreignUlid('dibatalkan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('dicatat_oleh_impor', 120)->nullable();
            $t->index(['lokasi_id', 'tanggal_bisnis']);
        };
        // A person credited or blamed by a document; the legacy name stays when it matched no User.
        $orang = function (Blueprint $t, string $kolom): void {
            $t->foreignUlid("{$kolom}_id")->nullable()->constrained('user')->restrictOnDelete();
            $t->string("{$kolom}_impor", 120)->nullable();
        };

        $s->create('omset_harian', function (Blueprint $t) use ($harian, $tech): void {
            $harian($t);
            foreach (['food', 'bev', 'lainnya', 'diskon', 'service', 'pajak'] as $c) {
                $t->decimal($c, 15, 0)->default(0);
            }
            foreach (['jumlah_bill', 'traffic', 'qty_food', 'qty_bev', 'qty_lainnya'] as $c) {
                $t->unsignedInteger($c)->default(0);
            }
            $t->boolean('breakdown_valid')->default(false);
            $tech($t);
        });

        $s->create('omset_porsi', function (Blueprint $t) use ($orang, $tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('omset_harian_id')->constrained('omset_harian')->cascadeOnDelete();
            $t->string('sumber', 10);
            $orang($t, 'pic');
            $t->string('keterangan', 190)->nullable(); // event or guest name on the line
            foreach (['nominal', 'pajak', 'service', 'diskon'] as $c) {
                $t->decimal($c, 15, 0)->default(0);
            }
            // Counted only while open_bill is on; the amounts survive switching it off (legacy bindOb).
            $t->boolean('open_bill')->default(false);
            foreach (['open_bill_nominal', 'open_bill_pajak', 'open_bill_service'] as $c) {
                $t->decimal($c, 15, 0)->default(0);
            }
            $tech($t);
        });

        $s->create('laporan_kasir', function (Blueprint $t) use ($harian, $tech): void {
            $harian($t);
            $t->string('catatan', 500)->nullable();
            $tech($t);
        });

        $s->create('laporan_kasir_bayar', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('laporan_kasir_id')->constrained('laporan_kasir')->cascadeOnDelete();
            $t->foreignUlid('metode_bayar_id')->constrained('metode_bayar')->restrictOnDelete();
            $t->decimal('nominal_pos', 15, 0)->default(0);    // what the POS says
            $t->decimal('nominal_aktual', 15, 0)->default(0); // what the cashier has
            $t->decimal('aktual_masuk', 15, 0)->nullable();   // what reached the bank; NULL = not checked
            $t->decimal('mdr_manual', 15, 0)->nullable();     // only when aktual_masuk > nominal_aktual
            $t->boolean('sudah_input_pos')->default(false);   // legacy `esb` flag
            $tech($t);
            $t->unique(['laporan_kasir_id', 'metode_bayar_id']);
        });

        $s->create('compliment', function (Blueprint $t) use ($dokumen, $orang, $tech): void {
            $dokumen($t);
            $t->string('nama_tamu', 120)->nullable();
            $t->string('alasan', 255)->nullable();
            $t->decimal('nominal', 15, 0);
            $orang($t, 'pic');
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $tech($t);
        });

        $s->create('bon', function (Blueprint $t) use ($dokumen, $tech): void {
            $dokumen($t);
            $t->string('nama_tamu', 120);
            $t->decimal('nominal', 15, 0);
            $t->string('status', 8)->default('belum');
            $t->date('lunas_tanggal')->nullable();
            $t->foreignUlid('lunas_metode_bayar_id')->nullable()->constrained('metode_bayar')->restrictOnDelete();
            $t->timestamp('lunas_at')->nullable();
            $t->foreignUlid('lunas_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('lunas_oleh_impor', 120)->nullable();
            $tech($t);
        });

        $s->create('void_item', function (Blueprint $t) use ($dokumen, $orang, $tech): void {
            $dokumen($t);
            $t->string('nomor_bill', 60)->nullable(); // the POS bill number
            $t->string('item', 200);
            // NULL = not recorded (rows before 17 Sep 2026), never "zero".
            $t->decimal('subtotal', 15, 0)->nullable();
            $t->decimal('service', 15, 0)->nullable();
            $t->decimal('pajak', 15, 0)->nullable();
            $t->decimal('total', 15, 0);
            $orang($t, 'penginput');
            $orang($t, 'salah');
            $t->text('alasan')->nullable();
            $t->string('alasan_batal', 255)->nullable();
            $tech($t);
        });

        $s->create('pengaturan_penjualan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->date('berlaku_dari')->unique();
            $t->decimal('pajak_persen', 6, 3);
            $t->decimal('service_persen', 6, 3);
            $tech($t);
        });

        $s->create('target_omset', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('berlaku_dari');
            $t->decimal('nominal_bulanan', 15, 0);
            $t->boolean('pakai_hari_kerja')->default(false);
            $t->unsignedTinyInteger('hari_kerja_per_bulan')->nullable();
            $tech($t);
            $t->unique(['lokasi_id', 'berlaku_dari']);
        });

        $s->create('target_omset_pic', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('target_omset_id')->constrained('target_omset')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->string('peran', 10); // marketing | kasir | event
            $t->decimal('nominal', 15, 0);
            $tech($t);
            $t->unique(['target_omset_id', 'user_id', 'peran']);
        });

        // Kas: takings of a Report Daily line land in a dompet.
        $s->table('arus_kas', function (Blueprint $t): void {
            $t->foreignUlid('laporan_kasir_bayar_id')->nullable()->unique()->after('pengembalian_modal_id')
                ->constrained('laporan_kasir_bayar')->restrictOnDelete();
        });
        $db->statement('ALTER TABLE `arus_kas` DROP CONSTRAINT `arus_kas_chk_2`');
        $db->statement('ALTER TABLE `arus_kas` DROP CONSTRAINT `arus_kas_chk_3`');

        $s->table('setoran_hari', function (Blueprint $t): void {
            $t->foreignUlid('laporan_kasir_id')->nullable()->after('tanggal_bisnis')
                ->constrained('laporan_kasir')->restrictOnDelete();
        });

        $money = fn (array $cols) => array_map(fn ($c) => "{$c} >= 0", $cols);
        foreach ([
            'omset_harian' => [
                ...$money(['food', 'bev', 'lainnya', 'diskon', 'service', 'pajak']),
                'food + bev + lainnya > 0',
                'diskon <= food + bev + lainnya',
                'traffic >= jumlah_bill',
            ],
            'omset_porsi' => [
                "sumber IN ('marketing','event','kasir','walk_in')",
                ...$money(['nominal', 'pajak', 'service', 'diskon', 'open_bill_nominal', 'open_bill_pajak', 'open_bill_service']),
            ],
            'laporan_kasir_bayar' => [
                ...$money(['nominal_pos', 'nominal_aktual']),
                'aktual_masuk IS NULL OR aktual_masuk >= 0',
                'mdr_manual IS NULL OR mdr_manual >= 0',
            ],
            'compliment' => ['nominal > 0'],
            'bon' => [
                'nominal > 0',
                "status IN ('belum','lunas')",
                "(status = 'lunas') = (lunas_tanggal IS NOT NULL)",
                "(status = 'lunas') = (lunas_metode_bayar_id IS NOT NULL)",
            ],
            'void_item' => [
                'total > 0',
                '(subtotal IS NULL) = (service IS NULL)',
                '(subtotal IS NULL) = (pajak IS NULL)',
                'subtotal IS NULL OR (subtotal >= 0 AND service >= 0 AND pajak >= 0)',
            ],
            'pengaturan_penjualan' => ['pajak_persen BETWEEN 0 AND 100', 'service_persen BETWEEN 0 AND 100'],
            'target_omset' => ['nominal_bulanan >= 0', 'hari_kerja_per_bulan IS NULL OR hari_kerja_per_bulan BETWEEN 1 AND 31'],
            'target_omset_pic' => ["peran IN ('marketing','kasir','event')", 'nominal >= 0'],
        ] as $table => $checks) {
            foreach ($checks as $i => $expr) {
                $db->statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_chk_{$i}` CHECK ({$expr})");
            }
        }
        foreach ([
            2 => "sebab IN ('kas_kecil','mutasi_dompet','setoran','pembayaran','pengembalian_modal','omset')",
            3 => '(kas_kecil_id IS NOT NULL) + (mutasi_dompet_id IS NOT NULL) + (setoran_id IS NOT NULL)'
                .' + (pembayaran_id IS NOT NULL) + (pengembalian_modal_id IS NOT NULL) + (laporan_kasir_bayar_id IS NOT NULL) = 1',
            10 => "(sebab = 'omset') = (laporan_kasir_bayar_id IS NOT NULL)",
            11 => "sebab <> 'omset' OR arah = 'masuk'",
        ] as $i => $expr) {
            $db->statement("ALTER TABLE `arus_kas` ADD CONSTRAINT `arus_kas_chk_{$i}` CHECK ({$expr})");
        }
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        $db = DB::connection('core');
        foreach ([2, 3, 10, 11] as $i) {
            $db->statement("ALTER TABLE `arus_kas` DROP CONSTRAINT `arus_kas_chk_{$i}`");
        }
        $s->table('arus_kas', function (Blueprint $t): void {
            $t->dropForeign(['laporan_kasir_bayar_id']);
            $t->dropColumn('laporan_kasir_bayar_id');
        });
        $db->statement("ALTER TABLE `arus_kas` ADD CONSTRAINT `arus_kas_chk_2` CHECK (sebab IN ('kas_kecil','mutasi_dompet','setoran','pembayaran','pengembalian_modal'))");
        $db->statement('ALTER TABLE `arus_kas` ADD CONSTRAINT `arus_kas_chk_3` CHECK ((kas_kecil_id IS NOT NULL) + (mutasi_dompet_id IS NOT NULL) + (setoran_id IS NOT NULL)'
            .' + (pembayaran_id IS NOT NULL) + (pengembalian_modal_id IS NOT NULL) = 1)');
        $s->table('setoran_hari', function (Blueprint $t): void {
            $t->dropForeign(['laporan_kasir_id']);
            $t->dropColumn('laporan_kasir_id');
        });
        foreach (self::TABLES as $table) {
            $s->dropIfExists($table);
        }
    }
};
