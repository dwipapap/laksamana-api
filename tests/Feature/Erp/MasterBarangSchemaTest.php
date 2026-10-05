<?php

use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\BarangHarga;
use App\Erp\Master\Models\BarangLokasi;
use App\Erp\Master\Models\BarangSatuan;
use App\Erp\Master\Models\BarangVendor;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Satuan;
use App\Erp\Master\Models\Vendor;
use App\Erp\Master\Models\VendorHariTutup;
use Illuminate\Database\QueryException;

/*
 * ERP v2 master data (ADR-0007, docs/erp/pembelian-persediaan.md): the
 * database itself refuses what the rules forbid, so a buggy service or a
 * phpMyAdmin edit cannot write it either.
 */

function masterBarang(): array
{
    $gram = Satuan::create(['nama' => 'Gram']);
    $kg = Satuan::create(['nama' => 'Kg']);
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $pihak = Pihak::create(['nama' => 'Vendor Uji']);
    $vendor = Vendor::create(['pihak_id' => $pihak->id, 'perlu_jadwal_jemput' => true]);
    $barang = Barang::create(['nama' => 'Almond Slice Uji', 'satuan_dasar_id' => $gram->id]);

    return compact('gram', 'kg', 'outlet', 'pihak', 'vendor', 'barang');
}

it('stores a Barang with its units, vendor, location and price history', function () {
    ['gram' => $gram, 'kg' => $kg, 'outlet' => $outlet, 'vendor' => $vendor, 'barang' => $barang] = masterBarang();

    BarangSatuan::create(['barang_id' => $barang->id, 'satuan_id' => $gram->id, 'ukuran' => 1, 'berlaku_dari' => '2026-01-01']);
    BarangSatuan::create(['barang_id' => $barang->id, 'satuan_id' => $kg->id, 'ukuran' => 1000, 'berlaku_dari' => '2026-01-01']);
    BarangVendor::create(['barang_id' => $barang->id, 'pihak_id' => $vendor->pihak_id, 'urutan' => 0]);
    BarangLokasi::create(['barang_id' => $barang->id, 'lokasi_id' => $outlet->id]);
    VendorHariTutup::create(['pihak_id' => $vendor->pihak_id, 'hari' => 0]);
    $harga = BarangHarga::create([
        'barang_id' => $barang->id, 'pihak_id' => $vendor->pihak_id,
        'qty_beli' => 1000, 'harga_beli' => 125000, 'berlaku_dari' => '2026-01-01',
    ]);

    $barang->refresh();
    expect($barang->satuanDasar->nama)->toBe('Gram')
        ->and($barang->satuan)->toHaveCount(2)
        ->and($barang->vendor->first()->vendor->pihak->nama)->toBe('Vendor Uji')
        ->and($barang->lokasi->first()->lokasi->kode)->toBe('outlet')
        ->and($vendor->hariTutup->pluck('hari')->all())->toBe([0])
        // computed by the database, never written by a client
        ->and($harga->fresh()->harga_per_dasar)->toBe('125.0000');
});

it('bumps version on every write and keeps soft-deleted master rows', function () {
    ['barang' => $barang] = masterBarang();

    $barang->update(['aktif' => false]);
    expect($barang->fresh()->version)->toBe(2);

    $barang->delete();
    expect(Barang::find($barang->id))->toBeNull()
        ->and(Barang::withTrashed()->find($barang->id)?->version)->toBe(3);
});

it('treats unit names that differ only in letter case as one unit', function () {
    Satuan::create(['nama' => 'Ml']);

    expect(fn () => Satuan::create(['nama' => 'ML']))->toThrow(QueryException::class);
});

it('refuses values outside the CHECK constraints', function (Closure $write) {
    ['gram' => $gram, 'barang' => $barang] = masterBarang();

    expect(fn () => $write($gram, $barang))->toThrow(QueryException::class);
})->with([
    'unknown lokasi jenis' => [fn () => Lokasi::create(['kode' => 'gudang', 'nama' => 'Gudang', 'jenis' => 'gudang'])],
    'unknown barang sumber' => [fn () => Barang::create(['nama' => 'X', 'sumber' => 'pasar'])],
    'zero unit size' => [fn ($gram, $barang) => BarangSatuan::create(['barang_id' => $barang->id, 'satuan_id' => $gram->id, 'ukuran' => 0, 'berlaku_dari' => '2026-01-01'])],
    'zero purchase qty' => [fn ($gram, $barang) => BarangHarga::create(['barang_id' => $barang->id, 'qty_beli' => 0, 'harga_beli' => 1000, 'berlaku_dari' => '2026-01-01'])],
    'weekday 7' => [fn () => VendorHariTutup::create(['pihak_id' => Vendor::query()->value('pihak_id'), 'hari' => 7])],
]);

it('refuses a hard delete of a master row that is still referenced', function () {
    ['gram' => $gram] = masterBarang();

    expect(fn () => $gram->forceDelete())->toThrow(QueryException::class);
});

it('allows one vendor utama per Barang', function () {
    ['barang' => $barang, 'vendor' => $vendor] = masterBarang();
    $lain = Vendor::create(['pihak_id' => Pihak::create(['nama' => 'Vendor Lain'])->id]);

    BarangVendor::create(['barang_id' => $barang->id, 'pihak_id' => $vendor->pihak_id, 'urutan' => 0]);

    expect(fn () => BarangVendor::create(['barang_id' => $barang->id, 'pihak_id' => $lain->pihak_id, 'urutan' => 0]))
        ->toThrow(QueryException::class);
});
