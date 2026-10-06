<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fields the production Kompas data carries that the first Penjualan Harian
 * design missed (found by core:import erp-penjualan; penjualan-harian.md):
 *
 * - a breakdown line's position, shift, the cashier's day off, and the
 *   Acara / Reservasi VIP / Event it was taken from (legacy srcId);
 * - a bon's kind (tamu, staff, owner), POS bill number, method and PIC;
 * - a compliment's subtotal/tax/service and who granted it;
 * - when and by whom the Report Daily was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');
        $db = DB::connection('core');

        $s->table('omset_porsi', function (Blueprint $t): void {
            $t->unsignedSmallInteger('urutan')->default(0)->after('omset_harian_id');
            $t->string('shift', 16)->nullable()->after('keterangan');
            $t->boolean('libur')->default(false)->after('shift'); // cashier marked OFF that day: counts 0
            $t->foreignUlid('sumber_acara_id')->nullable()->after('libur')->constrained('acara')->restrictOnDelete();
            $t->foreignUlid('sumber_reservasi_id')->nullable()->after('sumber_acara_id')->constrained('reservasi')->restrictOnDelete();
            $t->foreignUlid('sumber_event_id')->nullable()->after('sumber_reservasi_id')->constrained('event')->restrictOnDelete();
            $t->string('sumber_impor', 64)->nullable()->after('sumber_event_id'); // legacy srcId that matched nothing
            $t->unique(['omset_harian_id', 'urutan']);
        });
        $db->statement('ALTER TABLE `omset_porsi` ADD CONSTRAINT `omset_porsi_chk_9` CHECK '
            .'((sumber_acara_id IS NOT NULL) + (sumber_reservasi_id IS NOT NULL) + (sumber_event_id IS NOT NULL) <= 1)');

        $s->table('bon', function (Blueprint $t): void {
            $t->string('tipe', 8)->nullable()->after('nama_tamu');
            $t->string('nomor_bill', 60)->nullable()->after('tipe');
            $t->foreignUlid('metode_bayar_id')->nullable()->after('nominal')->constrained('metode_bayar')->restrictOnDelete();
            $t->foreignUlid('pic_id')->nullable()->after('metode_bayar_id')->constrained('user')->restrictOnDelete();
            $t->string('pic_impor', 120)->nullable()->after('pic_id');
        });
        $db->statement("ALTER TABLE `bon` ADD CONSTRAINT `bon_chk_4` CHECK (tipe IS NULL OR tipe IN ('tamu','staff','owner'))");

        $s->table('compliment', function (Blueprint $t): void {
            $t->decimal('subtotal', 15, 0)->nullable()->after('nominal');
            $t->decimal('pajak', 15, 0)->nullable()->after('subtotal');
            $t->decimal('service', 15, 0)->nullable()->after('pajak');
            $t->foreignUlid('pemberi_id')->nullable()->after('divisi_id')->constrained('user')->restrictOnDelete();
            $t->string('pemberi_impor', 120)->nullable()->after('pemberi_id');
        });

        $s->table('laporan_kasir', function (Blueprint $t): void {
            $t->timestamp('dikirim_at')->nullable()->after('catatan');
            $t->foreignUlid('dikirim_oleh')->nullable()->after('dikirim_at')->constrained('user')->nullOnDelete();
            $t->string('dikirim_oleh_impor', 120)->nullable()->after('dikirim_oleh');
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        $db = DB::connection('core');
        $db->statement('ALTER TABLE `omset_porsi` DROP CONSTRAINT `omset_porsi_chk_9`');
        $db->statement('ALTER TABLE `bon` DROP CONSTRAINT `bon_chk_4`');
        $s->table('omset_porsi', function (Blueprint $t): void {
            $t->dropUnique(['omset_harian_id', 'urutan']);
            $t->dropConstrainedForeignId('sumber_acara_id');
            $t->dropConstrainedForeignId('sumber_reservasi_id');
            $t->dropConstrainedForeignId('sumber_event_id');
            $t->dropColumn(['urutan', 'shift', 'libur', 'sumber_impor']);
        });
        $s->table('bon', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('metode_bayar_id');
            $t->dropConstrainedForeignId('pic_id');
            $t->dropColumn(['tipe', 'nomor_bill', 'pic_impor']);
        });
        $s->table('compliment', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('pemberi_id');
            $t->dropColumn(['subtotal', 'pajak', 'service', 'pemberi_impor']);
        });
        $s->table('laporan_kasir', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('dikirim_oleh');
            $t->dropColumn(['dikirim_at', 'dikirim_oleh_impor']);
        });
    }
};
