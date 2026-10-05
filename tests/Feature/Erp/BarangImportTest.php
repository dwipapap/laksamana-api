<?php

use App\Erp\Master\Imports\BarangImporter;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\BarangSatuan;
use App\Erp\Master\Models\BarangVendor;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-barang against the restored legacy Stock + HPP
 * (docs/erp/pembelian-persediaan.md "Pemetaan Barang dari data lama").
 * Counts come from the legacy rows, not hard-coded numbers.
 */

function legacyProducts(): Collection
{
    return DB::connection('legacy_stock')->table('products')->get(['nama', 'data'])
        ->map(fn ($r) => (object) ['nama' => $r->nama, 'd' => json_decode((string) $r->data) ?: (object) []]);
}

it('imports every legacy product as one Barang, idempotently', function () {
    $this->artisan('core:import', ['module' => 'erp-barang'])->assertSuccessful();
    $first = Barang::query()->orderBy('legacy_id')->get(['id', 'legacy_id', 'version', 'satuan_dasar_id'])->toArray();

    $this->artisan('core:import', ['module' => 'erp-barang'])->assertSuccessful();
    $second = Barang::query()->orderBy('legacy_id')->get(['id', 'legacy_id', 'version', 'satuan_dasar_id'])->toArray();

    expect($first)->toHaveCount(legacyProducts()->count())
        ->and($second)->toBe($first)
        ->and(collect($first)->pluck('legacy_id')->sort()->values()->all())
        ->toBe(legacyProducts()->pluck('nama')->sort()->values()->all());
});

it('keeps unit sizes from the product settings, in the Satuan Dasar', function () {
    $this->artisan('core:import', ['module' => 'erp-barang'])->assertSuccessful();

    $p = legacyProducts()->first(fn ($p) => ! empty($p->d->satuanDasar) && ! empty((array) ($p->d->isi ?? [])));
    $barang = Barang::where('legacy_id', $p->nama)->with('satuan.satuan', 'satuanDasar')->firstOrFail();
    if ($barang->satuan_dasar_id === null) {
        $this->markTestSkipped('first sampled product is a Satuan Dasar conflict');
    }
    [$unit, $size] = [array_key_first((array) $p->d->isi), reset($p->d->isi)];
    $row = $barang->satuan->first(fn (BarangSatuan $s) => strcasecmp($s->satuan->nama, (string) $unit) === 0);

    expect($row)->not->toBeNull()
        ->and((float) $row->ukuran)->toBe((float) $size)
        ->and((float) $barang->satuan->first(fn ($s) => $s->satuan_id === $barang->satuan_dasar_id)->ukuran)->toBe(1.0);
});

it('leaves a Satuan Dasar conflict empty and reports it instead of guessing', function () {
    $importer = app(BarangImporter::class);
    $importer->import();
    $conflicts = $importer->issues()['satuan_dasar_beda'] ?? [];

    if ($conflicts === []) {
        $this->markTestSkipped('no Satuan Dasar conflict in this dump');
    }
    $nama = explode(': ', $conflicts[0])[0];

    expect(Barang::where('legacy_id', $nama)->value('satuan_dasar_id'))->toBeNull()
        ->and(BarangSatuan::whereHas('barang', fn ($q) => $q->where('legacy_id', $nama))->whereNotNull('ukuran')->count())->toBe(0);
});

it('puts the vendor utama first and places CK goods at the Central Kitchen', function () {
    $this->artisan('core:import', ['module' => 'erp-barang'])->assertSuccessful();

    $withVendor = legacyProducts()->first(fn ($p) => trim((string) ($p->d->utama ?? '')) !== '');
    $utama = BarangVendor::whereHas('barang', fn ($q) => $q->where('legacy_id', $withVendor->nama))
        ->where('urutan', 0)->with('vendor')->firstOrFail();
    expect(mb_strtolower($utama->vendor->legacy_id))->toBe(mb_strtolower(trim($withVendor->d->utama)));

    $ck = legacyProducts()->first(fn ($p) => ($p->d->sumber ?? '') === 'ck' && empty($p->d->diOutlet));
    if ($ck) {
        $kode = Barang::where('legacy_id', $ck->nama)->firstOrFail()->lokasi()->with('lokasi')->get()->pluck('lokasi.kode')->all();
        expect($kode)->toBe(['ck'])
            ->and(Lokasi::where('kode', 'ck')->value('jenis'))->toBe('central_kitchen');
    }
});
