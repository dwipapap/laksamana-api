<?php

use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Satuan;
use App\Erp\Resep\Models\KontrolBahan;
use App\Erp\Resep\Models\KontrolBahanBaris;
use App\Erp\Resep\Models\PengaturanHpp;
use App\Erp\Resep\Models\Resep;
use App\Erp\Resep\Models\ResepBaris;
use App\Erp\Resep\Models\ResepHarga;
use Illuminate\Database\QueryException;

/*
 * Resep & HPP (docs/erp/resep-hpp.md): the rules the database enforces on
 * its own. Costs are computed by services, so none are stored here.
 */

function resepFixture(): array
{
    $gram = Satuan::create(['nama' => 'Gram', 'keluarga' => 'massa', 'faktor' => 1]);
    $porsi = Satuan::create(['nama' => 'Porsi']);
    $ayam = Barang::create(['nama' => 'Ayam Uji', 'satuan_dasar_id' => $gram->id]);
    $base = Resep::create(['nama' => 'Saus Uji', 'jenis' => 'food', 'kategori' => 'base', 'yield_qty' => 1000, 'yield_satuan_id' => $gram->id]);
    $menu = Resep::create(['nama' => 'Ayam Saus Uji', 'jenis' => 'food', 'kategori' => 'menu', 'yield_satuan_id' => $porsi->id]);

    return compact('gram', 'porsi', 'ayam', 'base', 'menu');
}

it('stores a menu made of a Barang, a sub-recipe and a cooking-step note', function () {
    ['gram' => $gram, 'ayam' => $ayam, 'base' => $base, 'menu' => $menu] = resepFixture();

    ResepBaris::create(['resep_id' => $menu->id, 'urutan' => 1, 'barang_id' => $ayam->id, 'qty_input' => 150, 'satuan_input_id' => $gram->id, 'qty_dasar' => 150]);
    ResepBaris::create(['resep_id' => $menu->id, 'urutan' => 2, 'catatan' => 'bumbu blender saring']);
    ResepBaris::create(['resep_id' => $menu->id, 'urutan' => 3, 'sub_resep_id' => $base->id, 'qty_input' => 80, 'satuan_input_id' => $gram->id, 'qty_dasar' => 80]);
    ResepHarga::create(['resep_id' => $menu->id, 'harga_jual' => 38000, 'berlaku_dari' => '2026-10-01']);

    expect($menu->baris()->pluck('urutan')->all())->toBe([1, 2, 3])
        ->and($menu->harga()->value('harga_jual'))->toBe('38000');
});

it('refuses recipe values outside the CHECK constraints', function (Closure $write) {
    expect(fn () => $write(resepFixture()))->toThrow(QueryException::class);
})->with([
    'unknown jenis' => [fn (array $f) => Resep::create(['nama' => 'X', 'jenis' => 'snack', 'kategori' => 'menu'])],
    'unknown kategori' => [fn (array $f) => Resep::create(['nama' => 'X', 'jenis' => 'food', 'kategori' => 'dish'])],
    'zero yield' => [fn (array $f) => Resep::create(['nama' => 'X', 'jenis' => 'food', 'kategori' => 'menu', 'yield_qty' => 0])],
    'same name in one jenis' => [fn (array $f) => Resep::create(['nama' => 'Saus Uji', 'jenis' => 'food', 'kategori' => 'base'])],
    'line with Barang and sub-recipe' => [fn (array $f) => ResepBaris::create(['resep_id' => $f['menu']->id, 'urutan' => 1, 'barang_id' => $f['ayam']->id, 'sub_resep_id' => $f['base']->id, 'qty_input' => 1, 'satuan_input_id' => $f['gram']->id])],
    'ingredient without quantity' => [fn (array $f) => ResepBaris::create(['resep_id' => $f['menu']->id, 'urutan' => 1, 'barang_id' => $f['ayam']->id])],
    'note with a quantity' => [fn (array $f) => ResepBaris::create(['resep_id' => $f['menu']->id, 'urutan' => 1, 'catatan' => 'x', 'qty_input' => 1, 'satuan_input_id' => $f['gram']->id])],
    'empty line' => [fn (array $f) => ResepBaris::create(['resep_id' => $f['menu']->id, 'urutan' => 1])],
    'zero quantity' => [fn (array $f) => ResepBaris::create(['resep_id' => $f['menu']->id, 'urutan' => 1, 'barang_id' => $f['ayam']->id, 'qty_input' => 0, 'satuan_input_id' => $f['gram']->id])],
    'negative price' => [fn (array $f) => ResepHarga::create(['resep_id' => $f['menu']->id, 'harga_jual' => -1, 'berlaku_dari' => '2026-10-01'])],
    'unit family without factor' => [fn (array $f) => Satuan::create(['nama' => 'Ons', 'keluarga' => 'massa'])],
]);

it('lets the same name stand in food and drink, and keeps POS codes unique', function () {
    resepFixture();
    Resep::create(['nama' => 'Saus Uji', 'jenis' => 'drink', 'kategori' => 'base', 'kode_pos' => 'MATCHA02']);

    expect(fn () => Resep::create(['nama' => 'Lain', 'jenis' => 'drink', 'kategori' => 'menu', 'kode_pos' => 'MATCHA02']))
        ->toThrow(QueryException::class);
});

it('links a Central Kitchen Barang to at most one recipe that produces it', function () {
    ['gram' => $gram, 'base' => $base] = resepFixture();
    $karage = Barang::create(['nama' => 'Ayam Karage Uji', 'satuan_dasar_id' => $gram->id, 'sumber' => 'ck', 'dipesan' => true]);
    $base->update(['barang_id' => $karage->id]);

    expect($base->fresh()->version)->toBe(2)
        ->and(fn () => Resep::create(['nama' => 'Karage Kedua', 'jenis' => 'food', 'kategori' => 'base', 'barang_id' => $karage->id]))
        ->toThrow(QueryException::class);
});

it('drops a recipe\'s lines with it, but never a recipe another one uses', function () {
    ['gram' => $gram, 'base' => $base, 'menu' => $menu] = resepFixture();
    ResepBaris::create(['resep_id' => $menu->id, 'urutan' => 1, 'sub_resep_id' => $base->id, 'qty_input' => 80, 'satuan_input_id' => $gram->id]);

    expect(fn () => $base->forceDelete())->toThrow(QueryException::class);

    $menu->forceDelete();
    expect(ResepBaris::where('resep_id', $menu->id)->count())->toBe(0);
});

it('keeps HPP settings and Kontrol Bahan Baku months sane', function () {
    ['gram' => $gram, 'ayam' => $ayam] = resepFixture();
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);

    PengaturanHpp::create(['berlaku_dari' => '2000-01-01', 'target_food' => 0.33, 'target_drink' => 0.33, 'spare' => 0.05, 'lampu_kuning' => 3, 'lampu_merah' => 8]);
    $kb = KontrolBahan::create(['lokasi_id' => $outlet->id, 'bulan' => '2026-08-01', 'penjualan' => 125000000]);
    KontrolBahanBaris::create(['kontrol_bahan_id' => $kb->id, 'barang_id' => $ayam->id, 'stok_awal' => 2000, 'belanja' => 50000, 'stok_akhir' => -10]);

    expect(fn () => PengaturanHpp::create(['berlaku_dari' => '2026-10-01', 'target_food' => 1.2, 'target_drink' => 0.3, 'spare' => 0, 'lampu_kuning' => 3, 'lampu_merah' => 8]))
        ->toThrow(QueryException::class)
        ->and(fn () => PengaturanHpp::create(['berlaku_dari' => '2026-10-01', 'target_food' => 0.3, 'target_drink' => 0.3, 'spare' => 0, 'lampu_kuning' => 9, 'lampu_merah' => 8]))
        ->toThrow(QueryException::class)
        ->and(fn () => KontrolBahan::create(['lokasi_id' => $outlet->id, 'bulan' => '2026-09-15']))->toThrow(QueryException::class)
        ->and(fn () => KontrolBahan::create(['lokasi_id' => $outlet->id, 'bulan' => '2026-08-01']))->toThrow(QueryException::class)
        ->and(fn () => KontrolBahanBaris::create(['kontrol_bahan_id' => $kb->id, 'barang_id' => $ayam->id]))->toThrow(QueryException::class);
});
