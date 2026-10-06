<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Reservasi (docs/erp/reservasi.md), from the reservasi blob: one row
 * per booking with its staged arrivals, follow-ups and DP instalments,
 * tables with their floor-plan position, the walk-in waiting list, and the
 * category / info-source lists.
 */
return new class extends Migration
{
    private const TABLES = [
        'daftar_tunggu', 'reservasi_dp', 'reservasi_tindak_lanjut', 'reservasi_kedatangan',
        'reservasi', 'sumber_info', 'kategori_reservasi', 'meja',
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
        $daftar = function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 120)->unique();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        };
        // Who did it: a User, or the legacy name when it matched none.
        $oleh = function (Blueprint $t): void {
            $t->foreignUlid('oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('oleh_impor', 120)->nullable();
        };

        $s->create('meja', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->string('kode', 16);
            $t->unsignedSmallInteger('kapasitas');
            $t->string('zona', 32)->nullable();
            $t->json('tata_letak')->nullable(); // {x, y, w, h, bentuk}: drawing only
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
            $t->unique(['lokasi_id', 'kode']);
        });

        $s->create('kategori_reservasi', $daftar);
        $s->create('sumber_info', $daftar);

        $s->create('reservasi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->time('jam')->nullable();
            $t->string('nama_tamu', 120);
            $t->string('telepon', 32)->nullable()->index(); // normalised; groups a guest's visits
            $t->unsignedSmallInteger('pax');
            $t->foreignUlid('meja_id')->nullable()->constrained('meja')->restrictOnDelete();
            $t->boolean('berbagi_meja')->default(false);
            $t->foreignUlid('kategori_reservasi_id')->nullable()->constrained('kategori_reservasi')->restrictOnDelete();
            $t->foreignUlid('sumber_info_id')->nullable()->constrained('sumber_info')->restrictOnDelete();
            $t->string('pic_tipe', 32)->nullable(); // Marketing, Host/Captain, …
            $t->foreignUlid('pic_id')->nullable()->constrained('user')->restrictOnDelete();
            $t->string('pic_impor', 120)->nullable();
            $t->boolean('member')->default(false);
            $t->string('nomor_member', 40)->nullable();
            $t->boolean('vip')->default(false);
            $t->string('permintaan_makanan', 500)->nullable();
            $t->string('permintaan_minuman', 500)->nullable();
            $t->text('catatan')->nullable();
            $t->string('status', 10)->default('pending');
            $t->string('alasan_batal', 255)->nullable();
            $t->unsignedSmallInteger('pax_aktual')->default(0);
            $t->timestamp('checkin_at')->nullable();
            $t->timestamp('pulang_at')->nullable();
            $t->boolean('ditutup_otomatis')->default(false);
            $t->string('dokumen_key', 190)->nullable();
            $t->string('dicatat_oleh_impor', 120)->nullable();
            $tech($t);
            $t->index(['lokasi_id', 'tanggal_bisnis']);
        });

        $s->create('reservasi_kedatangan', function (Blueprint $t) use ($oleh, $tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('reservasi_id')->constrained('reservasi')->cascadeOnDelete();
            $t->timestamp('datang_at');
            $t->unsignedSmallInteger('pax');
            $oleh($t);
            $tech($t);
        });

        $s->create('reservasi_tindak_lanjut', function (Blueprint $t) use ($oleh, $tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('reservasi_id')->constrained('reservasi')->cascadeOnDelete();
            $t->timestamp('dicatat_at');
            $t->text('catatan');
            $oleh($t);
            $tech($t);
        });

        $s->create('reservasi_dp', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('reservasi_id')->constrained('reservasi')->cascadeOnDelete();
            $t->unsignedTinyInteger('urutan'); // cicilan ke-
            $t->decimal('nominal', 15, 0)->default(0);
            $t->foreignUlid('metode_bayar_id')->nullable()->constrained('metode_bayar')->restrictOnDelete();
            $t->string('metode_impor', 60)->nullable();
            $t->string('bukti_key', 190)->nullable();
            // What the transfer proof says (typed or read by OCR).
            $t->date('transfer_tanggal')->nullable();
            $t->time('transfer_jam')->nullable();
            $t->string('transfer_bank', 60)->nullable();
            $t->string('transfer_nama', 120)->nullable();
            $t->decimal('transfer_nominal', 15, 0)->nullable();
            $t->timestamp('ocr_at')->nullable();
            $t->string('verifikasi', 14)->default('belum');
            $t->timestamp('diverifikasi_at')->nullable();
            $t->foreignUlid('diverifikasi_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
            $t->unique(['reservasi_id', 'urutan']);
        });

        $s->create('daftar_tunggu', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->foreignUlid('lokasi_id')->constrained('lokasi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->string('nama_tamu', 120);
            $t->string('telepon', 32)->nullable();
            $t->unsignedSmallInteger('pax');
            $t->string('catatan', 255)->nullable();
            $t->string('status', 8)->default('menunggu');
            $t->timestamp('masuk_at');
            $t->timestamp('duduk_at')->nullable();
            $t->foreignUlid('reservasi_id')->nullable()->unique()->constrained('reservasi')->restrictOnDelete();
            $tech($t);
            $t->index(['lokasi_id', 'tanggal_bisnis']);
        });

        foreach ([
            'meja' => ['kapasitas > 0'],
            'reservasi' => [
                "status IN ('pending','confirmed','datang','cancelled','no_show')",
                'pax > 0',
            ],
            'reservasi_kedatangan' => ['pax > 0'],
            'reservasi_dp' => [
                'nominal >= 0',
                'transfer_nominal IS NULL OR transfer_nominal >= 0',
                "verifikasi IN ('belum','terverifikasi','ditolak')",
                "(verifikasi = 'belum') = (diverifikasi_at IS NULL)",
            ],
            'daftar_tunggu' => [
                "status IN ('menunggu','duduk','batal')",
                'pax > 0',
                "(status = 'duduk') = (reservasi_id IS NOT NULL)",
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
