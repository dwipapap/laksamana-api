<?php

use App\Erp\Resep\Models\Resep;
use App\Erp\Resep\Models\ResepBaris;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-resep against the restored legacy HPP tables
 * (docs/erp/resep-hpp.md "Pemetaan lama → v2"). Barang comes first.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'erp-barang'])->assertSuccessful();
});

it('imports every recipe and every ingredient line, idempotently', function () {
    $this->artisan('core:import', ['module' => 'erp-resep'])->assertSuccessful();
    $first = ResepBaris::query()->orderBy('id')->pluck('version', 'id')->all();
    $this->artisan('core:import', ['module' => 'erp-resep'])->assertSuccessful();

    $legacy = DB::connection('legacy_stock')->table('hpp_resep');
    expect(Resep::count())->toBe($legacy->count())
        ->and(ResepBaris::count())->toBe((int) DB::connection('legacy_stock')->table('hpp_resep')->sum(DB::raw('json_length(bahan)')))
        ->and(ResepBaris::query()->orderBy('id')->pluck('version', 'id')->all())->toBe($first);
});

it('turns the PRASMANAN prefix of seksi into a kategori', function () {
    $this->artisan('core:import', ['module' => 'erp-resep'])->assertSuccessful();
    $r = DB::connection('legacy_stock')->table('hpp_resep')->where('seksi', 'like', 'PRASMANAN%')->first();
    if (! $r) {
        $this->markTestSkipped('no prasmanan recipe in this dump');
    }
    $v2 = Resep::where('legacy_id', $r->id)->firstOrFail();

    expect($v2->kategori)->toBe('prasmanan')
        ->and((string) $v2->seksi)->not->toStartWith('PRASMANAN');
});

it('points a recipe line at its sub-recipe or Barang, never at itself', function () {
    $this->artisan('core:import', ['module' => 'erp-resep'])->assertSuccessful();

    expect(ResepBaris::whereColumn('sub_resep_id', 'resep_id')->count())->toBe(0)
        ->and(ResepBaris::whereNotNull('sub_resep_id')->count())->toBeGreaterThan(0)
        ->and(ResepBaris::whereNotNull('barang_id')->count())->toBeGreaterThan(0);
});

it('converts within a unit family: kilograms in a gram recipe', function () {
    $this->artisan('core:import', ['module' => 'erp-resep'])->assertSuccessful();
    $row = ResepBaris::query()
        ->join('satuan as s', 's.id', '=', 'resep_baris.satuan_input_id')
        ->join('barang as b', 'b.id', '=', 'resep_baris.barang_id')
        ->join('satuan as d', 'd.id', '=', 'b.satuan_dasar_id')
        ->whereRaw('LOWER(s.nama) = ?', ['kg'])->whereRaw('LOWER(d.nama) IN (?, ?)', ['gram', 'gr'])
        ->first(['resep_baris.qty_input', 'resep_baris.qty_dasar']);
    if (! $row) {
        $this->markTestSkipped('no Kg line on a gram Barang in this dump');
    }

    expect((float) $row->qty_dasar)->toBe((float) $row->qty_input * 1000);
});
