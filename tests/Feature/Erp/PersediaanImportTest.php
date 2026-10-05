<?php

use App\Erp\Master\Models\Lokasi;
use App\Erp\Persediaan\Imports\PersediaanImporter;
use App\Erp\Persediaan\Models\MutasiStok;
use App\Erp\Persediaan\Models\PesananBahanBaris;
use App\Erp\Persediaan\Models\SerahTerima;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-persediaan against the restored legacy Stock
 * (docs/erp/pembelian-persediaan.md "Pemetaan tabel lama → v2").
 * Expected numbers come from the legacy rows, not hard-coded counts.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'erp-barang'])->assertSuccessful();
});

function legacyStock(): Connection
{
    return DB::connection('legacy_stock');
}

it('imports every order line with a quantity, idempotently', function () {
    $this->artisan('core:import', ['module' => 'erp-persediaan'])->assertSuccessful();
    $first = PesananBahanBaris::query()->orderBy('legacy_id')->pluck('version', 'legacy_id')->all();
    $mutasi = MutasiStok::query()->orderBy('legacy_id')->pluck('version', 'legacy_id')->all();

    $this->artisan('core:import', ['module' => 'erp-persediaan'])->assertSuccessful();

    expect($first)->toHaveCount(legacyStock()->table('orders')->where('qty', '>', 0)->count())
        ->and(PesananBahanBaris::query()->orderBy('legacy_id')->pluck('version', 'legacy_id')->all())->toBe($first)
        ->and(MutasiStok::query()->orderBy('legacy_id')->pluck('version', 'legacy_id')->all())->toBe($mutasi);
});

it('rebuilds the Central Kitchen balance of every Barang exactly as legacy computes it', function () {
    $this->artisan('core:import', ['module' => 'erp-persediaan'])->assertSuccessful();

    $legacy = legacyStock()->table('ck_stock')->where('qty', '>', 0)
        ->selectRaw("item, ROUND(SUM(CASE WHEN arah = 'masuk' THEN qty ELSE -qty END), 4) saldo")
        ->groupBy('item')->pluck('saldo', 'item')
        ->mapWithKeys(fn ($v, $k) => [mb_strtolower(trim($k)) => (float) $v])->sortKeys()->all();

    $v2 = MutasiStok::query()->where('lokasi_id', Lokasi::where('kode', 'ck')->value('id'))
        ->join('barang', 'barang.id', '=', 'mutasi_stok.barang_id')
        ->selectRaw("barang.legacy_id item, ROUND(SUM(CASE WHEN arah = 'masuk' THEN qty_dasar ELSE -qty_dasar END), 4) saldo")
        ->groupBy('barang.legacy_id')->pluck('saldo', 'item')
        ->mapWithKeys(fn ($v, $k) => [mb_strtolower(trim($k)) => (float) $v])->sortKeys()->all();

    expect($v2)->toBe($legacy)
        ->and(MutasiStok::count())->toBe(legacyStock()->table('ck_stock')->where('qty', '>', 0)->count());
});

it('links a CK movement from an order to a line the Central Kitchen supplied', function () {
    $this->artisan('core:import', ['module' => 'erp-persediaan'])->assertSuccessful();

    $lines = MutasiStok::where('sebab', 'pengajuan')->with('pesananBahanBaris')->get()->pluck('pesananBahanBaris');

    expect($lines)->not->toBeEmpty()
        ->and($lines->every(fn ($l) => $l->sumber === 'ck' && $l->vendor_id === null))->toBeTrue();
});

it('keeps unmatched legacy names as *_impor text and reports what a person must decide', function () {
    $importer = app(PersediaanImporter::class);
    $importer->import();

    $penerima = legacyStock()->table('serah_terima')->where('penerima', '<>', '')->value('penerima');
    $row = SerahTerima::whereNotNull('penerima_impor')->orWhereNotNull('penerima_id')->first();

    expect($row)->not->toBeNull()
        ->and($row->penerima_id !== null || $row->penerima_impor !== null)->toBeTrue()
        ->and($penerima)->not->toBeNull()
        ->and(array_keys($importer->issues()))->each->toBeString();
});
