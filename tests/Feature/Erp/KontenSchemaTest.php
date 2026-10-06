<?php

use App\Erp\Konten\Models\BrandKonten;
use App\Erp\Konten\Models\Iklan;
use App\Erp\Konten\Models\IklanBiaya;
use App\Erp\Konten\Models\KolTarif;
use App\Erp\Konten\Models\Konten;
use App\Erp\Konten\Models\KontenPersetujuan;
use App\Erp\Konten\Models\KontenTayang;
use App\Erp\Konten\Models\KunjunganKol;
use App\Erp\Konten\Models\TugasProduksi;
use App\Erp\Master\Models\Kol;
use App\Erp\Master\Models\Pihak;
use Illuminate\Database\QueryException;

/*
 * Konten (docs/erp/konten.md): the rules the database enforces on its own.
 */

function kontenFixture(): array
{
    $brand = BrandKonten::create(['nama' => 'Laksamana Muda', 'kpi_reach' => 100000, 'profil' => ['tone' => 'santai']]);
    $reel = Konten::create(['brand_konten_id' => $brand->id, 'judul' => 'Halloween teaser', 'status' => 'posted', 'jenis_konten' => 'Reel']);

    return compact('brand', 'reel');
}

it('measures a post per platform and drops one platform without the others', function () {
    $f = kontenFixture();
    $ig = KontenTayang::create(['konten_id' => $f['reel']->id, 'platform' => 'ig', 'reach' => 12000, 'likes' => 800, 'er' => 6.7]);
    KontenTayang::create(['konten_id' => $f['reel']->id, 'platform' => 'tt', 'views' => 30000]);
    KontenPersetujuan::create(['konten_id' => $f['reel']->id, 'tahap' => 'content_director', 'status' => 'disetujui', 'diputuskan_at' => now()]);

    $ig->delete(); // G-01: one platform's figures only

    expect(KontenTayang::where('konten_id', $f['reel']->id)->pluck('platform')->all())->toBe(['tt'])
        ->and($f['brand']->fresh()->profil['tone'])->toBe('santai')
        ->and(fn () => KontenTayang::create(['konten_id' => $f['reel']->id, 'platform' => 'tt']))->toThrow(QueryException::class);
});

it('keeps ad spend with the VAT of its day, and KOL rates by date', function () {
    $f = kontenFixture();
    $ads = Iklan::create(['brand_konten_id' => $f['brand']->id, 'nama' => 'Halloween Meta', 'platform' => 'Meta Ads', 'status' => 'active', 'budget' => 3000000]);
    IklanBiaya::create(['iklan_id' => $ads->id, 'tanggal' => '2026-10-05', 'nominal' => 500000, 'ppn' => 55000]);
    $kol = Kol::create(['pihak_id' => Pihak::create(['nama' => 'KOL Uji'])->id, 'jenis' => 'KOL']);
    KolTarif::create(['kol_id' => $kol->pihak_id, 'tarif' => 1500000, 'berlaku_dari' => '2026-01-01']);
    KunjunganKol::create(['kol_id' => $kol->pihak_id, 'brand_konten_id' => $f['brand']->id, 'judul' => 'Visit', 'tanggal' => '2026-10-20', 'status' => 'confirmed']);

    expect(fn () => KolTarif::create(['kol_id' => $kol->pihak_id, 'tarif' => 1, 'berlaku_dari' => '2026-01-01']))->toThrow(QueryException::class)
        ->and(fn () => $f['brand']->forceDelete())->toThrow(QueryException::class);
});

it('refuses values outside the agreed lists', function (Closure $write) {
    expect(fn () => $write(kontenFixture()))->toThrow(QueryException::class);
})->with([
    'unknown content status' => [fn (array $f) => $f['reel']->update(['status' => 'published'])],
    'unknown platform' => [fn (array $f) => KontenTayang::create(['konten_id' => $f['reel']->id, 'platform' => 'snapchat'])],
    'engagement over 100%' => [fn (array $f) => KontenTayang::create(['konten_id' => $f['reel']->id, 'platform' => 'fb', 'er' => 150])],
    'unknown approval stage' => [fn (array $f) => KontenPersetujuan::create(['konten_id' => $f['reel']->id, 'tahap' => 'owner'])],
    'ad status in legacy label' => [fn (array $f) => Iklan::create(['brand_konten_id' => $f['brand']->id, 'nama' => 'X', 'platform' => 'Meta Ads', 'status' => 'aktif'])],
    'unknown task kind' => [fn (array $f) => TugasProduksi::create(['judul' => 'X', 'jenis' => 'photo'])],
    'unknown visit state' => [fn (array $f) => KunjunganKol::create(['judul' => 'X', 'tanggal' => '2026-10-20', 'status' => 'batal'])],
]);
