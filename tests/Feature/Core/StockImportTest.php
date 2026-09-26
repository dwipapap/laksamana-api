<?php

use App\Modules\Stock\Services\StockSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #73: every stock table in core, imported from the restored stock DB with
 * its legacy columns verbatim and the legacy `id` in `legacy_id`; a repeat
 * import changes nothing, and an INSERT without an id gets a ULID from the
 * column default (the stock services keep their legacy statements).
 */

it('copies every stock table 1:1, idempotently', function () {
    $this->artisan('core:import', ['module' => 'stock'])->assertSuccessful();
    $core = DB::connection('core');
    $before = [];

    foreach (StockSupport::COLUMNS as $legacy => $cols) {
        $target = StockSupport::CORE_TABLES[$legacy];
        $select = array_map(fn ($c) => $c === 'id' ? 'legacy_id as id' : $c, $cols);
        $want = DB::connection('legacy_stock')->table($legacy)->orderBy($cols[0])->get()->map(fn ($r) => array_map('strval', (array) $r))->all();
        $got = $core->table($target)->select($select)->orderBy($cols[0] === 'id' ? 'legacy_id' : $cols[0])->get()->map(fn ($r) => array_map('strval', (array) $r))->all();
        expect($got)->toEqual($want, $legacy);
        $before[$target] = $core->table($target)->pluck('id')->sort()->values()->all();
    }

    $this->artisan('core:import', ['module' => 'stock'])->assertSuccessful();
    foreach ($before as $target => $ids) {
        expect($core->table($target)->pluck('id')->sort()->values()->all())->toBe($ids, $target);
    }
});

it('mints a ULID in the database for a legacy-shaped insert', function () {
    DB::connection('core')->insert("INSERT INTO stock_vendors (nama, whatsapp, data) VALUES ('Tes ULID', '', '{}')");

    expect(Str::isUlid(DB::connection('core')->table('stock_vendors')->where('nama', 'Tes ULID')->value('id')))->toBeTrue();
});
