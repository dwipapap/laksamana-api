<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Resep & HPP (docs/erp/resep-hpp.md): recipes whose lines point at a
 * Barang or another recipe by FK (legacy: names in a JSON blob), selling-price
 * and HPP-settings history, and the monthly Kontrol Bahan Baku per Lokasi.
 * Costs are computed by services from these rows, never stored.
 */
return new class extends Migration
{
    private const TABLES = ['kontrol_bahan_baris', 'kontrol_bahan', 'pengaturan_hpp', 'resep_harga', 'resep_baris', 'resep'];

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

        // Fixed ratios inside a family (Kg = 1000 Gram) win over per-Barang sizes.
        $s->table('satuan', function (Blueprint $t): void {
            $t->string('keluarga', 8)->nullable()->after('nama');
            $t->decimal('faktor', 15, 4)->nullable()->after('keluarga'); // in the family's smallest unit
        });

        // Legacy "Perlu ada di Purchasing?": water or ice is a recipe Barang never ordered.
        $s->table('barang', function (Blueprint $t): void {
            $t->boolean('dipesan')->default(true)->after('sumber');
        });

        $s->create('resep', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 48)->nullable()->unique(); // hpp_resep.id
            $t->string('nama', 190);
            $t->string('jenis', 8);
            $t->string('kategori', 10);
            $t->string('seksi', 96)->nullable();
            $t->string('kode_pos', 64)->nullable()->unique(); // menu code in the POS export
            $t->decimal('yield_qty', 15, 4)->default(1);
            $t->foreignUlid('yield_satuan_id')->nullable()->constrained('satuan')->restrictOnDelete();
            // The Barang this recipe produces (a Central Kitchen base ordered through Purchasing).
            $t->foreignUlid('barang_id')->nullable()->unique()->constrained('barang')->restrictOnDelete();
            $t->decimal('modal_manual', 15, 0)->nullable(); // only for a recipe without ingredient lines
            $t->string('catatan', 2000)->nullable();
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
            $t->unique(['jenis', 'nama']);
        });

        $s->create('resep_baris', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('resep_id')->constrained('resep')->cascadeOnDelete();
            $t->unsignedSmallInteger('urutan');
            $t->foreignUlid('barang_id')->nullable()->constrained('barang')->restrictOnDelete();
            $t->foreignUlid('sub_resep_id')->nullable()->constrained('resep')->restrictOnDelete();
            $t->string('catatan', 190)->nullable(); // a cooking-step line keeps only this
            $t->decimal('qty_input', 15, 4)->nullable();
            $t->foreignUlid('satuan_input_id')->nullable()->constrained('satuan')->restrictOnDelete();
            // In the Barang's Satuan Dasar or the sub-recipe's yield unit; NULL = cannot convert.
            $t->decimal('qty_dasar', 15, 4)->nullable();
            $tech($t);
            $t->unique(['resep_id', 'urutan']);
        });

        $s->create('resep_harga', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('resep_id')->constrained('resep')->restrictOnDelete();
            $t->decimal('harga_jual', 15, 0);
            $t->decimal('harga_upsize', 15, 0)->nullable();
            $t->date('berlaku_dari');
            $tech($t);
            $t->unique(['resep_id', 'berlaku_dari']);
        });

        $s->create('pengaturan_hpp', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->date('berlaku_dari')->unique();
            $t->decimal('target_food', 5, 4);
            $t->decimal('target_drink', 5, 4);
            $t->decimal('spare', 5, 4);
            $t->decimal('lampu_kuning', 7, 2); // % selisih in Kontrol Bahan Baku
            $t->decimal('lampu_merah', 7, 2);
            $tech($t);
        });

        $s->create('kontrol_bahan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 16)->nullable()->unique(); // hpp_bulan.bulan
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('bulan'); // first day of the month
            $t->decimal('penjualan', 15, 0)->default(0);
            $t->string('catatan', 255)->nullable();
            $tech($t);
            $t->unique(['lokasi_id', 'bulan']);
        });

        $s->create('kontrol_bahan_baris', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('kontrol_bahan_id')->constrained('kontrol_bahan')->cascadeOnDelete();
            $t->foreignUlid('barang_id')->constrained('barang')->restrictOnDelete();
            // Quantities in the Barang's Satuan Dasar; legacy accepts negatives, so no sign check.
            foreach (['stok_awal', 'belanja', 'pakai_resep', 'spoil', 'team', 'rnd', 'compliment', 'stok_akhir'] as $c) {
                $t->decimal($c, 15, 4)->default(0);
            }
            $tech($t);
            $t->unique(['kontrol_bahan_id', 'barang_id']);
        });

        foreach ([
            'satuan' => [
                "keluarga IN ('massa','volume')",
                '(keluarga IS NULL) = (faktor IS NULL)',
                'faktor IS NULL OR faktor > 0',
            ],
            'resep' => [
                "jenis IN ('food','drink')",
                "kategori IN ('base','menu','prasmanan')",
                'yield_qty > 0',
                'modal_manual IS NULL OR modal_manual >= 0',
            ],
            'resep_baris' => [
                '(barang_id IS NOT NULL) + (sub_resep_id IS NOT NULL) <= 1',
                // a note line has no quantity; an ingredient line must have one
                '(barang_id IS NULL AND sub_resep_id IS NULL) = (qty_input IS NULL)',
                '(barang_id IS NOT NULL OR sub_resep_id IS NOT NULL OR catatan IS NOT NULL)',
                '(qty_input IS NULL) = (satuan_input_id IS NULL)',
                'qty_input IS NULL OR qty_input > 0',
            ],
            'resep_harga' => ['harga_jual >= 0', 'harga_upsize IS NULL OR harga_upsize >= 0'],
            'pengaturan_hpp' => [
                'target_food BETWEEN 0 AND 1', 'target_drink BETWEEN 0 AND 1', 'spare BETWEEN 0 AND 1',
                'lampu_kuning >= 0', 'lampu_kuning <= lampu_merah',
            ],
            'kontrol_bahan' => ['DAYOFMONTH(bulan) = 1'],
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
        foreach ([0, 1, 2] as $i) {
            DB::connection('core')->statement("ALTER TABLE `satuan` DROP CONSTRAINT `satuan_chk_{$i}`");
        }
        $s->table('satuan', fn (Blueprint $t) => $t->dropColumn(['keluarga', 'faktor']));
        $s->table('barang', fn (Blueprint $t) => $t->dropColumn('dipesan'));
    }
};
