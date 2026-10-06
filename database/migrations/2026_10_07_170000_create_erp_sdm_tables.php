<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 SDM (docs/erp/sdm.md): crew schedules and their requests, attendance
 * punches of Users and Pekerja Harian, and daily-worker requests, assignments
 * with the wage copied at the time, and weekly transfers.
 *
 * A punch or a face belongs to exactly one subject (a User or a Pekerja
 * Harian), replacing the legacy text pair subjek_tipe/subjek_id.
 */
return new class extends Migration
{
    private const TABLES = [
        'penugasan_dw', 'pembayaran_dw', 'permintaan_dw', 'tarif_posisi_dw', 'posisi_dw', 'pengaturan_dw',
        'ketukan_absen', 'wajah_terdaftar', 'lokasi_absen', 'pengaturan_absen',
        'pengajuan_jadwal', 'shift_bawaan', 'jadwal_kru', 'shift', 'pengaturan_jadwal',
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
        $legacy = fn (Blueprint $t) => $t->string('legacy_id', 72)->nullable()->unique();
        // Exactly one subject (CHECK below). RESTRICT: a CHECK may not sit on a SET NULL/CASCADE FK.
        $subjek = function (Blueprint $t): void {
            $t->foreignUlid('user_id')->nullable()->constrained('user')->restrictOnDelete();
            $t->foreignUlid('pekerja_harian_id')->nullable()->constrained('pekerja_harian', 'pihak_id')->restrictOnDelete();
        };
        $putus = function (Blueprint $t): void {
            $t->timestamp('diputuskan_at')->nullable();
            $t->foreignUlid('diputuskan_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('diputuskan_oleh_impor', 120)->nullable();
            $t->string('nota_putusan', 255)->nullable();
        };
        $berlaku = fn (Blueprint $t) => $t->date('berlaku_dari')->unique();

        // ---- Jadwal ----
        $s->create('pengaturan_jadwal', function (Blueprint $t) use ($berlaku, $tech): void {
            $t->ulid('id')->primary();
            $berlaku($t);
            $t->unsignedTinyInteger('maks_hari_beruntun')->nullable();
            $t->unsignedSmallInteger('jeda_min_menit')->nullable();
            $tech($t);
        });

        $s->create('shift', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('kode', 16)->unique();
            $t->string('nama', 32)->nullable();
            $t->time('jam_mulai')->nullable();
            $t->time('jam_selesai')->nullable(); // may be earlier than jam_mulai: past midnight
            $t->string('warna', 16)->nullable();
            $t->boolean('libur')->default(false);
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        $s->create('jadwal_kru', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->date('tanggal');
            $t->foreignUlid('shift_id')->nullable()->constrained('shift')->restrictOnDelete();
            $t->string('shift_impor', 16)->nullable(); // legacy code no longer defined
            $t->time('jam_mulai')->nullable();   // overrides the shift's hours
            $t->time('jam_selesai')->nullable();
            $t->string('catatan', 120)->nullable();
            $tech($t);
            $t->unique(['user_id', 'tanggal']);
            $t->index('tanggal');
        });

        $s->create('shift_bawaan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->unique()->constrained('user')->cascadeOnDelete();
            $t->foreignUlid('shift_id')->constrained('shift')->restrictOnDelete();
            $tech($t);
        });

        $s->create('pengajuan_jadwal', function (Blueprint $t) use ($legacy, $putus, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('user_id')->constrained('user')->restrictOnDelete();
            $t->string('jenis', 8);
            $t->date('tanggal_mulai');
            $t->date('tanggal_selesai');
            $t->foreignUlid('shift_id')->nullable()->constrained('shift')->restrictOnDelete(); // TUKAR: the shift asked for
            $t->time('jam_mulai')->nullable();
            $t->time('jam_selesai')->nullable();
            $t->text('alasan')->nullable();
            $t->string('status', 14)->default('menunggu');
            $t->timestamp('head_disetujui_at')->nullable();
            $t->foreignUlid('head_disetujui_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('head_disetujui_oleh_impor', 120)->nullable();
            $putus($t);
            $tech($t);
            $t->index(['status', 'tanggal_mulai']);
        });

        // ---- Absensi ----
        $s->create('pengaturan_absen', function (Blueprint $t) use ($berlaku, $tech): void {
            $t->ulid('id')->primary();
            $berlaku($t);
            $t->unsignedSmallInteger('awal_menit')->default(0);  // how early a punch may come before the shift
            $t->unsignedSmallInteger('akhir_menit')->default(0); // how late after it
            $t->boolean('wajah_wajib')->default(false);
            $t->decimal('wajah_ambang', 5, 4)->default(0.5);
            $t->unsignedSmallInteger('lembur_min_menit')->default(0);
            $t->unsignedSmallInteger('toleransi_telat_menit')->default(0);
            $t->boolean('tanpa_shift_boleh')->default(false);
            $tech($t);
        });

        $s->create('lokasi_absen', function (Blueprint $t) use ($legacy, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('lokasi_id')->nullable()->constrained('lokasi')->restrictOnDelete();
            $t->string('nama', 120);
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedInteger('radius_m')->default(120);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        $s->create('wajah_terdaftar', function (Blueprint $t) use ($legacy, $subjek, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $subjek($t);
            $t->json('descriptor'); // face vector, compared whole by the matcher
            $t->string('foto_key', 190)->nullable();
            $t->boolean('aktif')->default(true);
            $t->timestamp('didaftar_at');
            $t->foreignUlid('didaftar_oleh')->nullable()->constrained('user')->nullOnDelete();
            $tech($t);
        });

        $s->create('ketukan_absen', function (Blueprint $t) use ($legacy, $subjek, $putus, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $subjek($t);
            $t->string('nama_impor', 120)->nullable();
            $t->date('tanggal_bisnis');
            $t->foreignUlid('hari_operasional_id')->nullable()->constrained('hari_operasional')->restrictOnDelete();
            $t->string('arah', 6);
            $t->timestamp('waktu');
            // GPS at the moment of the punch.
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('akurasi_m')->nullable();
            $t->foreignUlid('lokasi_absen_id')->nullable()->constrained('lokasi_absen')->restrictOnDelete();
            $t->unsignedInteger('jarak_m')->nullable();
            $t->boolean('dalam_area')->default(false);
            // Face and shift as they were then.
            $t->decimal('wajah_skor', 5, 4)->nullable();
            $t->boolean('wajah_ok')->default(false);
            $t->foreignUlid('shift_id')->nullable()->constrained('shift')->restrictOnDelete();
            $t->time('shift_mulai')->nullable();
            $t->time('shift_selesai')->nullable();
            $t->string('shift_sumber', 8)->default('none'); // jadwal | bawaan | dw | none
            $t->boolean('dalam_shift')->default(false);
            $t->string('foto_key', 190)->nullable();
            $t->string('status', 8)->default('valid');
            $t->string('sebab', 32)->nullable();
            $t->string('alasan', 255)->nullable();
            $putus($t);
            $tech($t);
            $t->unique(['user_id', 'tanggal_bisnis', 'arah']);
            $t->unique(['pekerja_harian_id', 'tanggal_bisnis', 'arah']);
            $t->index(['tanggal_bisnis', 'status']);
        });

        // ---- Pekerja Harian ----
        $s->create('pengaturan_dw', function (Blueprint $t) use ($berlaku, $tech): void {
            $t->ulid('id')->primary();
            $berlaku($t);
            $t->decimal('jam_dasar', 4, 2)->default(6);
            $t->decimal('jam_batas', 4, 2)->default(12);
            $t->decimal('tambahan_panjang', 15, 0)->default(20000);
            $tech($t);
        });

        $s->create('posisi_dw', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('nama', 60)->unique();
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t, softDelete: true);
        });

        $s->create('tarif_posisi_dw', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('posisi_dw_id')->constrained('posisi_dw')->restrictOnDelete();
            $t->decimal('tarif', 15, 0); // one base shift
            $t->date('berlaku_dari');
            $tech($t);
            $t->unique(['posisi_dw_id', 'berlaku_dari']);
        });

        $s->create('permintaan_dw', function (Blueprint $t) use ($legacy, $putus, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('divisi_id')->constrained('divisi')->restrictOnDelete();
            $t->date('tanggal_bisnis');
            $t->time('jam_mulai')->nullable();
            $t->time('jam_selesai')->nullable();
            $t->foreignUlid('posisi_dw_id')->nullable()->constrained('posisi_dw')->restrictOnDelete();
            $t->unsignedSmallInteger('jumlah')->default(1);
            $t->string('catatan', 255)->nullable();
            $t->string('status', 10)->default('menunggu');
            $t->foreignUlid('diminta_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('diminta_oleh_impor', 120)->nullable();
            $putus($t);
            $tech($t);
            $t->index(['tanggal_bisnis', 'status']);
        });

        // One transfer: a week (Monday) to one destination account.
        $s->create('pembayaran_dw', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->date('minggu_mulai');
            $t->string('jenis_tujuan', 16)->default('bank');
            $t->string('nomor_tujuan', 60); // digits only, so "1234 5678" = "1234-5678"
            $t->string('nama_tujuan', 120)->nullable();
            $t->decimal('nominal', 15, 0);
            $t->timestamp('dibayar_at')->nullable();
            $t->foreignUlid('dibayar_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->string('dibayar_oleh_impor', 120)->nullable();
            $tech($t);
            $t->unique(['minggu_mulai', 'nomor_tujuan']);
        });

        $s->create('penugasan_dw', function (Blueprint $t) use ($legacy, $putus, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('pekerja_harian_id')->constrained('pekerja_harian', 'pihak_id')->restrictOnDelete();
            // SET NULL: deleting a request only releases its assignments (legacy rule).
            $t->foreignUlid('permintaan_dw_id')->nullable()->constrained('permintaan_dw')->nullOnDelete();
            $t->date('tanggal_bisnis');
            $t->time('jam_mulai')->nullable();
            $t->time('jam_selesai')->nullable();
            $t->foreignUlid('divisi_id')->nullable()->constrained('divisi')->restrictOnDelete();
            $t->foreignUlid('posisi_dw_id')->nullable()->constrained('posisi_dw')->restrictOnDelete();
            $t->decimal('upah_dasar', 15, 0)->default(0); // copied from the rate in force
            $t->decimal('tambahan', 15, 0)->default(0);   // long-shift extra, copied
            $t->string('catatan', 255)->nullable();
            $t->string('status', 10)->default('menunggu');
            $putus($t);
            $t->string('kehadiran', 6)->nullable(); // NULL = not recorded yet
            $t->string('kehadiran_nota', 255)->nullable();
            $t->timestamp('kehadiran_at')->nullable();
            $t->foreignUlid('kehadiran_oleh')->nullable()->constrained('user')->nullOnDelete();
            $t->foreignUlid('pembayaran_dw_id')->nullable()->constrained('pembayaran_dw')->restrictOnDelete();
            $tech($t);
            $t->index(['tanggal_bisnis', 'status']);
        });

        foreach ([
            'pengaturan_jadwal' => ['maks_hari_beruntun IS NULL OR maks_hari_beruntun > 0'],
            'pengajuan_jadwal' => [
                "jenis IN ('off','izin','cuti','tukar')",
                "status IN ('menunggu','menunggu_hrd','disetujui','ditolak')",
                'tanggal_selesai >= tanggal_mulai',
                "(status IN ('disetujui','ditolak')) = (diputuskan_at IS NOT NULL)",
            ],
            'pengaturan_absen' => ['wajah_ambang BETWEEN 0 AND 1'],
            'lokasi_absen' => ['radius_m > 0', 'lat BETWEEN -90 AND 90', 'lng BETWEEN -180 AND 180'],
            'wajah_terdaftar' => ['(user_id IS NOT NULL) + (pekerja_harian_id IS NOT NULL) = 1'],
            'ketukan_absen' => [
                '(user_id IS NOT NULL) + (pekerja_harian_id IS NOT NULL) = 1',
                "arah IN ('masuk','pulang')",
                "status IN ('valid','menunggu','ditolak')",
                "shift_sumber IN ('jadwal','bawaan','dw','none')",
                'wajah_skor IS NULL OR wajah_skor BETWEEN 0 AND 1',
            ],
            'pengaturan_dw' => ['jam_dasar > 0', 'jam_batas >= jam_dasar', 'tambahan_panjang >= 0'],
            'tarif_posisi_dw' => ['tarif >= 0'],
            'permintaan_dw' => ['jumlah > 0', "status IN ('menunggu','disetujui','ditolak')"],
            'pembayaran_dw' => ['DAYOFWEEK(minggu_mulai) = 2', 'nominal >= 0', "jenis_tujuan IN ('bank','ewallet','tunai')"],
            'penugasan_dw' => [
                "status IN ('menunggu','disetujui','ditolak')",
                "kehadiran IS NULL OR kehadiran IN ('hadir','telat','alfa')",
                'upah_dasar >= 0', 'tambahan >= 0',
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
