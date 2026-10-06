<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Event & Tiket (docs/erp/event-tiket.md), from the EMS blobs and the
 * public ticket shop: events with their approval trail, vendors and sponsors,
 * ticket classes and seats, buyer accounts, orders, tickets, check-ins,
 * refunds, and talent schedules and monthly talent payments.
 */
return new class extends Migration
{
    private const TABLES = [
        'jadwal_talent', 'pembayaran_talent', 'aturan_jadwal_talent',
        'refund_tiket', 'checkin_tiket', 'kursi_tahan', 'tiket', 'pesanan_tiket_baris', 'pesanan_tiket',
        'buyer_reset', 'buyer_sesi', 'buyer', 'kursi', 'kelas_tiket',
        'event_sponsor', 'event_vendor', 'event', 'event_ide',
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

        $s->create('event_ide', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nama', 190);
            $t->string('kategori', 60)->nullable();
            $t->string('frekuensi', 32)->nullable();
            $t->string('kesulitan', 32)->nullable();
            $t->string('target_pasar', 190)->nullable();
            $t->decimal('anggaran', 15, 0)->nullable();
            $t->decimal('potensi_pendapatan', 15, 0)->nullable();
            $t->boolean('pernah_dijalankan')->default(false);
            $t->text('deskripsi')->nullable();
            $t->json('evaluasi')->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('event', function (Blueprint $t) use ($legacy, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nama', 190);
            $t->string('tema', 190)->nullable();
            $t->string('kategori', 60)->nullable();
            $t->string('status', 10)->default('planning');
            $t->foreignUlid('lokasi_id')->nullable()->constrained('lokasi')->restrictOnDelete();
            $t->string('venue_impor', 120)->nullable();
            $t->foreignUlid('event_ide_id')->nullable()->constrained('event_ide')->restrictOnDelete();
            $orang($t, 'pic');
            $orang($t, 'co_pic');
            $t->timestamp('mulai_at')->nullable();
            $t->timestamp('selesai_at')->nullable();
            $t->unsignedInteger('kapasitas')->nullable();
            $t->boolean('bertiket')->default(false);
            $t->string('poster_key', 190)->nullable();
            $t->text('deskripsi')->nullable();
            // Approval trail (legacy `pengajuan`).
            $t->timestamp('diajukan_at')->nullable();
            $t->foreignUlid('diajukan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->timestamp('diputuskan_at')->nullable();
            $t->foreignUlid('diputuskan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('keputusan', 10)->nullable(); // disetujui | ditolak
            $t->json('rencana')->nullable(); // rundown, timeline, layout, tasks: read-only planning notes
            $tech($t, softDelete: true);
            $t->index(['status', 'mulai_at']);
        });

        foreach (['event_vendor', 'event_sponsor'] as $name) {
            $s->create($name, function (Blueprint $t) use ($tech): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('event_id')->constrained('event')->cascadeOnDelete();
                $t->foreignUlid('pihak_id')->nullable()->constrained('pihak')->restrictOnDelete();
                $t->string('nama_impor', 190)->nullable();
                $t->string('keterangan', 255)->nullable();
                $t->decimal('nominal', 15, 0)->default(0);
                $t->string('status', 10)->default('pending');
                $tech($t);
            });
        }

        $s->create('kelas_tiket', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('event_id')->constrained('event')->restrictOnDelete();
            $t->string('nama', 120);
            $t->decimal('harga', 15, 0);
            $t->unsignedInteger('kuota')->nullable();
            $t->string('manfaat', 500)->nullable();
            $t->text('deskripsi')->nullable();
            $t->boolean('bertempat')->default(false); // seated: sold per seat
            $t->timestamp('jual_mulai')->nullable();
            $t->timestamp('jual_selesai')->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('kursi', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('event_id')->constrained('event')->restrictOnDelete();
            $t->foreignUlid('kelas_tiket_id')->nullable()->constrained('kelas_tiket')->restrictOnDelete();
            $t->string('kode', 32); // seat or table label
            $t->string('jenis', 8)->default('seat'); // seat | table | area
            $t->string('zona', 32)->nullable();
            $t->string('tier', 32)->nullable();
            $t->string('lantai', 16)->nullable();
            $t->unsignedSmallInteger('kapasitas')->default(1);
            $t->string('status', 10)->default('tersedia');
            $t->json('tata_letak')->nullable();
            $tech($t);
            $t->unique(['event_id', 'kode']);
        });

        $s->create('buyer', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('email', 191)->unique();
            $t->string('pass_hash', 255)->nullable();
            $t->string('nama', 120)->nullable();
            $t->string('telepon', 32)->nullable();
            $t->date('tanggal_lahir')->nullable();
            $tech($t);
        });

        $s->create('buyer_sesi', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('buyer_id')->constrained('buyer')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('berakhir_at')->index();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $s->create('buyer_reset', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('buyer_id')->constrained('buyer')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('berakhir_at');
            $t->timestamp('dipakai_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $s->create('pesanan_tiket', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('event_id')->constrained('event')->restrictOnDelete();
            $t->foreignUlid('buyer_id')->nullable()->constrained('buyer')->restrictOnDelete();
            $t->string('nama_pembeli', 120);
            $t->string('email', 191)->nullable();
            $t->string('telepon', 32)->nullable();
            $t->date('tanggal_lahir')->nullable();
            $t->string('kanal', 20)->nullable(); // online, on-site, …
            $t->decimal('subtotal', 15, 0);
            $t->decimal('biaya', 15, 0)->default(0);
            $t->decimal('total', 15, 0);
            $t->string('status_bayar', 10)->default('pending');
            $t->string('xendit_ref', 64)->nullable()->unique();
            $t->string('akses_token_hash', 64)->nullable()->unique();
            $t->timestamp('berakhir_at')->nullable(); // unpaid orders expire
            $t->timestamp('dibayar_at')->nullable();
            $t->foreignUlid('dicatat_oleh')->nullable()->constrained('user')->nullOnDelete(); // on-site sales
            $t->string('catatan', 500)->nullable();
            $tech($t);
        });

        $s->create('pesanan_tiket_baris', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->nullable()->unique();
            $t->foreignUlid('pesanan_tiket_id')->constrained('pesanan_tiket')->cascadeOnDelete();
            $t->foreignUlid('kelas_tiket_id')->constrained('kelas_tiket')->restrictOnDelete();
            $t->unsignedSmallInteger('qty');
            $t->decimal('harga', 15, 0); // copied from the class when ordered
            $tech($t);
        });

        $s->create('kursi_tahan', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('kursi_id')->unique()->constrained('kursi')->cascadeOnDelete();
            $t->string('token', 64)->index();
            $t->foreignUlid('pesanan_tiket_id')->nullable()->constrained('pesanan_tiket')->cascadeOnDelete();
            $t->timestamp('berakhir_at')->index();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $s->create('tiket', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nomor', 40)->unique();
            $t->foreignUlid('pesanan_tiket_baris_id')->constrained('pesanan_tiket_baris')->restrictOnDelete();
            $t->foreignUlid('kelas_tiket_id')->constrained('kelas_tiket')->restrictOnDelete();
            $t->foreignUlid('kursi_id')->nullable()->constrained('kursi')->restrictOnDelete();
            $t->string('label_kursi', 60)->nullable();
            $t->unsignedSmallInteger('pax_ke')->default(1);
            $t->unsignedSmallInteger('pax_total')->default(1);
            $t->string('nama_pemegang', 120)->nullable();
            $t->string('qr_token', 64)->unique();
            $t->string('status', 10)->default('valid');
            $t->timestamp('diterbitkan_at')->nullable();
            $t->string('pdf_key', 190)->nullable();
            $tech($t);
        });

        $s->create('checkin_tiket', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('tiket_id')->constrained('tiket')->restrictOnDelete();
            $t->string('jenis', 8)->default('checkin'); // a cancelled check-in is a koreksi row
            $t->string('gerbang', 32)->nullable();
            $t->foreignUlid('petugas_id')->nullable()->constrained('user')->nullOnDelete();
            $t->string('petugas_impor', 120)->nullable();
            $t->timestamp('dipindai_at');
            $t->string('alasan', 255)->nullable();
            $tech($t);
        });

        $s->create('refund_tiket', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('pesanan_tiket_id')->constrained('pesanan_tiket')->restrictOnDelete();
            $t->string('alasan', 500)->nullable();
            $t->string('status', 10)->default('diminta');
            $t->timestamp('diminta_at');
            $t->foreignUlid('diminta_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->timestamp('diputuskan_at')->nullable();
            $t->foreignUlid('diputuskan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('penyetuju_impor', 120)->nullable();
            $tech($t);
        });

        $s->create('aturan_jadwal_talent', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('talent_id')->constrained('talent', 'pihak_id')->restrictOnDelete();
            $t->unsignedTinyInteger('hari'); // bitmask: 1 = Minggu … 64 = Sabtu
            $t->time('mulai')->nullable();
            $t->time('selesai')->nullable();
            $t->string('jenis_tampil', 60)->nullable();
            $t->decimal('tarif', 15, 0)->nullable();
            $t->date('berlaku_dari');
            $t->date('berlaku_sampai')->nullable();
            $tech($t);
        });

        $s->create('pembayaran_talent', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('talent_id')->constrained('talent', 'pihak_id')->restrictOnDelete();
            $t->date('bulan'); // first day of the month
            $t->unsignedSmallInteger('jumlah_tampil')->default(0);
            $t->decimal('total', 15, 0);
            $t->string('status', 10)->default('pending');
            $t->timestamp('dibayar_at')->nullable();
            $t->foreignUlid('pic_finance_id')->nullable()->constrained('user')->restrictOnDelete();
            $t->string('pic_finance_impor', 120)->nullable();
            $t->string('bukti_key', 190)->nullable();
            $tech($t);
            $t->unique(['talent_id', 'bulan']);
        });

        $s->create('jadwal_talent', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('talent_id')->constrained('talent', 'pihak_id')->restrictOnDelete();
            $t->foreignUlid('event_id')->nullable()->constrained('event')->restrictOnDelete();
            $t->foreignUlid('aturan_jadwal_talent_id')->nullable()->constrained('aturan_jadwal_talent')->restrictOnDelete();
            $t->foreignUlid('pembayaran_talent_id')->nullable()->constrained('pembayaran_talent')->restrictOnDelete();
            $t->date('tanggal');
            $t->time('mulai')->nullable();
            $t->time('selesai')->nullable();
            $t->string('jenis_tampil', 60)->nullable();
            $t->decimal('tarif', 15, 0)->default(0); // copied when scheduled
            $t->string('status', 10)->default('scheduled');
            $t->string('sumber', 16)->nullable();
            $tech($t);
            $t->index(['talent_id', 'tanggal']);
        });

        $nonNeg = fn (string $c) => "{$c} >= 0";
        foreach ([
            'event' => [
                "status IN ('planning','prospect','approval','upcoming','selesai')",
                'selesai_at IS NULL OR mulai_at IS NULL OR selesai_at >= mulai_at',
                "keputusan IS NULL OR keputusan IN ('disetujui','ditolak')",
                '(keputusan IS NULL) = (diputuskan_at IS NULL)',
            ],
            'event_vendor' => [$nonNeg('nominal'), "status IN ('pending','confirmed','paid')"],
            'event_sponsor' => [$nonNeg('nominal'), "status IN ('pending','confirmed','paid')"],
            'kelas_tiket' => [
                $nonNeg('harga'),
                'kuota IS NULL OR kuota > 0',
                'jual_selesai IS NULL OR jual_mulai IS NULL OR jual_selesai >= jual_mulai',
            ],
            'kursi' => ["jenis IN ('seat','table','area')", "status IN ('tersedia','terkunci','terjual')", 'kapasitas > 0'],
            'pesanan_tiket' => [
                $nonNeg('subtotal'), $nonNeg('biaya'),
                'total = subtotal + biaya',
                "status_bayar IN ('pending','paid','expired','cancelled','refunded')",
                "(status_bayar IN ('paid','refunded')) = (dibayar_at IS NOT NULL)",
            ],
            'pesanan_tiket_baris' => ['qty > 0', $nonNeg('harga')],
            'tiket' => ["status IN ('valid','digunakan','batal','refund')", 'pax_ke BETWEEN 1 AND pax_total'],
            'checkin_tiket' => ["jenis IN ('checkin','koreksi')"],
            'refund_tiket' => [
                "status IN ('diminta','disetujui','ditolak')",
                "(status = 'diminta') = (diputuskan_at IS NULL)",
            ],
            'aturan_jadwal_talent' => [
                'hari BETWEEN 1 AND 127',
                'tarif IS NULL OR tarif >= 0',
                'berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_dari',
            ],
            'pembayaran_talent' => [
                'DAYOFMONTH(bulan) = 1',
                $nonNeg('total'),
                "status IN ('pending','confirmed','paid')",
                "(status = 'paid') = (dibayar_at IS NOT NULL)",
            ],
            'jadwal_talent' => [
                "status IN ('scheduled','confirmed','done','cancelled')",
                $nonNeg('tarif'),
                // no `selesai > mulai`: night shows run past midnight (22:00-02:00)
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
