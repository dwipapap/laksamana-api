<?php

use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Satuan;
use App\Erp\Master\Models\Vendor;
use App\Erp\Persediaan\Models\HariOperasional;
use App\Erp\Persediaan\Models\MutasiStok;
use App\Erp\Persediaan\Models\PenyesuaianStok;
use App\Erp\Persediaan\Models\PenyesuaianStokBaris;
use App\Erp\Persediaan\Models\PesananBahan;
use App\Erp\Persediaan\Models\PesananBahanBaris;
use App\Erp\Persediaan\Models\ProduksiCk;
use App\Erp\Persediaan\Models\ProduksiCkBaris;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Pembelian & Persediaan documents (docs/erp/pembelian-persediaan.md):
 * the rules the database enforces on its own.
 */

function persediaanFixture(): array
{
    $gram = Satuan::create(['nama' => 'Gram']);
    $ck = Lokasi::create(['kode' => 'ck', 'nama' => 'Central Kitchen', 'jenis' => 'central_kitchen']);
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $barang = Barang::create(['nama' => 'Saus Uji', 'satuan_dasar_id' => $gram->id, 'sumber' => 'ck']);
    $vendor = Vendor::create(['pihak_id' => Pihak::create(['nama' => 'Vendor Uji'])->id]);

    return compact('gram', 'ck', 'outlet', 'barang', 'vendor');
}

function pesananBaris(array $f, array $over = []): PesananBahanBaris
{
    $pesanan = PesananBahan::create([
        'nomor' => 'PB-'.Str::random(8), 'lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-10-05',
    ]);

    return PesananBahanBaris::create($over + [
        'pesanan_bahan_id' => $pesanan->id, 'barang_id' => $f['barang']->id,
        'qty_input' => 500, 'satuan_input_id' => $f['gram']->id, 'qty_dasar' => 500, 'sumber' => 'ck',
    ]);
}

function produksiBaris(array $f): ProduksiCkBaris
{
    $doc = ProduksiCk::create(['nomor' => 'PR-'.Str::random(8), 'lokasi_id' => $f['ck']->id, 'tanggal_bisnis' => '2026-10-05']);

    return ProduksiCkBaris::create([
        'produksi_ck_id' => $doc->id, 'barang_id' => $f['barang']->id,
        'qty_input' => 1000, 'satuan_input_id' => $f['gram']->id, 'qty_dasar' => 1000,
    ]);
}

it('keeps at most one open Hari Operasional per Lokasi', function () {
    ['outlet' => $outlet] = persediaanFixture();
    $hari = HariOperasional::create(['lokasi_id' => $outlet->id, 'tanggal_bisnis' => '2026-10-04', 'dibuka_at' => '2026-10-04 08:00:00']);

    expect(fn () => HariOperasional::create(['lokasi_id' => $outlet->id, 'tanggal_bisnis' => '2026-10-05', 'dibuka_at' => '2026-10-05 08:00:00']))
        ->toThrow(QueryException::class);

    $hari->update(['status' => 'tutup', 'ditutup_at' => '2026-10-05 02:30:00']);
    $next = HariOperasional::create(['lokasi_id' => $outlet->id, 'tanggal_bisnis' => '2026-10-05', 'dibuka_at' => '2026-10-05 08:00:00']);

    expect($next->exists)->toBeTrue()
        // a closed day must say when it closed
        ->and(fn () => $next->update(['status' => 'tutup']))->toThrow(QueryException::class);
});

it('writes a stock movement only with exactly one origin that matches its sebab', function () {
    $f = persediaanFixture();
    $baris = produksiBaris($f);
    $base = ['lokasi_id' => $f['ck']->id, 'barang_id' => $f['barang']->id, 'arah' => 'masuk', 'qty_dasar' => 1000, 'tanggal_bisnis' => '2026-10-05'];

    expect(fn () => MutasiStok::create($base + ['sebab' => 'produksi']))->toThrow(QueryException::class)
        ->and(fn () => MutasiStok::create($base + ['sebab' => 'kiriman', 'produksi_ck_baris_id' => $baris->id]))->toThrow(QueryException::class);

    MutasiStok::create($base + ['sebab' => 'produksi', 'produksi_ck_baris_id' => $baris->id]);

    // the same production line cannot move stock twice
    expect(fn () => MutasiStok::create($base + ['sebab' => 'produksi', 'produksi_ck_baris_id' => $baris->id]))
        ->toThrow(QueryException::class);
});

it('refuses a vendor on a line the Central Kitchen supplies', function () {
    $f = persediaanFixture();

    expect(fn () => pesananBaris($f, ['vendor_id' => $f['vendor']->pihak_id]))->toThrow(QueryException::class)
        ->and(pesananBaris($f, ['sumber' => 'vendor', 'vendor_id' => $f['vendor']->pihak_id])->exists)->toBeTrue();
});

it('protects a line that moved stock, and the Barang it names', function () {
    $f = persediaanFixture();
    $baris = pesananBaris($f, ['status' => 'datang', 'diterima_at' => '2026-10-05 10:00:00']);
    MutasiStok::create([
        'lokasi_id' => $f['ck']->id, 'barang_id' => $f['barang']->id, 'arah' => 'keluar', 'qty_dasar' => 500,
        'tanggal_bisnis' => '2026-10-05', 'sebab' => 'pengajuan', 'pesanan_bahan_baris_id' => $baris->id,
    ]);

    expect(fn () => $baris->pesananBahan->delete())->toThrow(QueryException::class)
        ->and(fn () => $f['barang']->forceDelete())->toThrow(QueryException::class);
});

it('cascades lines only from their own document when nothing references them', function () {
    $f = persediaanFixture();
    $baris = pesananBaris($f);

    $baris->pesananBahan->delete();

    expect(PesananBahanBaris::find($baris->id))->toBeNull();
});

it('requires disahkan_at on a ratified correction and a non-zero quantity', function () {
    $f = persediaanFixture();
    $doc = PenyesuaianStok::create(['nomor' => 'PS-1', 'lokasi_id' => $f['ck']->id, 'tanggal_bisnis' => '2026-10-05']);

    expect(fn () => $doc->update(['status' => 'disahkan']))->toThrow(QueryException::class)
        ->and(fn () => PenyesuaianStokBaris::create([
            'penyesuaian_stok_id' => $doc->id, 'barang_id' => $f['barang']->id, 'qty_dasar' => 0, 'alasan' => 'rusak',
        ]))->toThrow(QueryException::class);

    $doc->update(['status' => 'disahkan', 'disahkan_at' => '2026-10-05 12:00:00']);
    expect($doc->fresh()->status)->toBe('disahkan');
});
