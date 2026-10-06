<?php

use App\Erp\Kas\Models\ArusKas;
use App\Erp\Kas\Models\Dompet;
use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Kas\Models\Setoran;
use App\Erp\Kas\Models\SetoranHari;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Penjualan\Models\Bon;
use App\Erp\Penjualan\Models\LaporanKasir;
use App\Erp\Penjualan\Models\LaporanKasirBayar;
use App\Erp\Penjualan\Models\OmsetHarian;
use App\Erp\Penjualan\Models\OmsetPorsi;
use App\Erp\Penjualan\Models\VoidItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Penjualan Harian (docs/erp/penjualan-harian.md): the rules the database
 * enforces on its own.
 */

function penjualanFixture(): array
{
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $cash = Dompet::create(['kode' => 'cash', 'nama' => 'Cash', 'jenis' => 'tunai', 'lokasi_id' => $outlet->id]);
    $bri = Dompet::create(['kode' => 'bri', 'nama' => 'BRI', 'jenis' => 'bank', 'lokasi_id' => $outlet->id]);
    $tunai = MetodeBayar::create(['kode' => 'cash', 'nama' => 'Cash', 'dompet_id' => $cash->id]);
    $qris = MetodeBayar::create(['kode' => 'qris_bri', 'nama' => 'QRIS BRI', 'dompet_id' => $bri->id]);

    return compact('outlet', 'cash', 'bri', 'tunai', 'qris');
}

function hari(array $f, array $over = []): OmsetHarian
{
    return OmsetHarian::create($over + [
        'lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-08-23',
        'food' => 12000000, 'bev' => 8000000, 'diskon' => 500000, 'service' => 975000, 'pajak' => 2514400,
        'jumlah_bill' => 120, 'traffic' => 180, 'jejak' => [['nama' => 'Admin Uji', 'at' => '2026-08-24T01:00:00Z']],
    ]);
}

it('keeps one sales day per Lokasi with sane amounts, and its breakdown per PIC', function () {
    $f = penjualanFixture();
    $h = hari($f);
    OmsetPorsi::create(['omset_harian_id' => $h->id, 'sumber' => 'marketing', 'pic_impor' => 'PIC Lama', 'nominal' => 3000000, 'open_bill' => false, 'open_bill_nominal' => 250000]);

    expect($h->fresh()->jejak[0]['nama'])->toBe('Admin Uji')
        ->and(fn () => hari($f))->toThrow(QueryException::class)
        ->and(fn () => hari($f, ['tanggal_bisnis' => '2026-08-24', 'food' => 0, 'bev' => 0]))->toThrow(QueryException::class)
        ->and(fn () => hari($f, ['tanggal_bisnis' => '2026-08-25', 'diskon' => 30000000]))->toThrow(QueryException::class)
        ->and(fn () => hari($f, ['tanggal_bisnis' => '2026-08-26', 'traffic' => 10]))->toThrow(QueryException::class)
        ->and(fn () => OmsetPorsi::create(['omset_harian_id' => $h->id, 'sumber' => 'ojol', 'nominal' => 1]))->toThrow(QueryException::class);
});

it('lands Report Daily takings in a dompet through the book, once', function () {
    $f = penjualanFixture();
    $lap = LaporanKasir::create(['lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-08-23']);
    $qris = LaporanKasirBayar::create(['laporan_kasir_id' => $lap->id, 'metode_bayar_id' => $f['qris']->id, 'nominal_pos' => 5000000, 'nominal_aktual' => 5000000, 'aktual_masuk' => 4965000]);
    $book = ['dompet_id' => $f['bri']->id, 'arah' => 'masuk', 'nominal' => 4965000, 'tanggal_bisnis' => '2026-08-23', 'sebab' => 'omset', 'laporan_kasir_bayar_id' => $qris->id];
    ArusKas::create($book);

    expect($qris->fresh()->mdr_manual)->toBeNull()
        ->and(fn () => LaporanKasirBayar::create(['laporan_kasir_id' => $lap->id, 'metode_bayar_id' => $f['qris']->id]))->toThrow(QueryException::class)
        ->and(fn () => ArusKas::create($book))->toThrow(QueryException::class)
        ->and(fn () => ArusKas::create(['arah' => 'keluar', 'laporan_kasir_bayar_id' => LaporanKasirBayar::create([
            'laporan_kasir_id' => $lap->id, 'metode_bayar_id' => $f['tunai']->id, 'nominal_aktual' => 100,
        ])->id] + $book))->toThrow(QueryException::class)
        ->and(fn () => ArusKas::create(['sebab' => 'setoran'] + $book))->toThrow(QueryException::class)
        ->and(fn () => LaporanKasirBayar::create(['laporan_kasir_id' => $lap->id, 'metode_bayar_id' => MetodeBayar::create(['kode' => 'edc_bca', 'nama' => 'EDC BCA'])->id, 'mdr_manual' => -1]))
        ->toThrow(QueryException::class);
});

it('ties a deposited day to its Report Daily', function () {
    $f = penjualanFixture();
    $lap = LaporanKasir::create(['lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-08-23']);
    $st = Setoran::create(['nomor' => 'ST-1', 'lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-08-25', 'dari_dompet_id' => $f['cash']->id, 'ke_dompet_id' => $f['bri']->id, 'nominal' => 700000]);
    SetoranHari::create(['setoran_id' => $st->id, 'tanggal_bisnis' => '2026-08-23', 'laporan_kasir_id' => $lap->id, 'nominal' => 700000]);

    expect(fn () => $lap->delete())->toThrow(QueryException::class);
});

it('settles a bon only with a date and a payment method', function () {
    $f = penjualanFixture();
    $bon = Bon::create(['nomor' => 'BN-'.Str::random(6), 'lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-08-23', 'nama_tamu' => 'Pak Budi', 'nominal' => 850000]);

    expect(fn () => $bon->update(['status' => 'lunas', 'lunas_tanggal' => '2026-08-30']))->toThrow(QueryException::class);

    $bon->update(['status' => 'lunas', 'lunas_tanggal' => '2026-08-30', 'lunas_metode_bayar_id' => $f['tunai']->id]);
    expect($bon->fresh()->status)->toBe('lunas');
});

it('keeps a void\'s detail all or nothing', function () {
    $f = penjualanFixture();
    $base = ['lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-09-18', 'item' => 'Nasi Goreng', 'total' => 63250];

    VoidItem::create($base + ['nomor' => 'V-1', 'subtotal' => 55000, 'service' => 2750, 'pajak' => 5500]);
    VoidItem::create($base + ['nomor' => 'V-2']); // before 17 Sep 2026: not recorded

    expect(fn () => VoidItem::create($base + ['nomor' => 'V-3', 'subtotal' => 55000]))->toThrow(QueryException::class)
        ->and(fn () => VoidItem::create(['nomor' => 'V-4', 'total' => 0] + $base))->toThrow(QueryException::class);
});
