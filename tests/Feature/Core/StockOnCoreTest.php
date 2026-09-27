<?php

use App\Modules\Stock\Services\StockOrders;
use Illuminate\Support\Facades\DB;

/*
 * #73: stock served from core. The Stock folder's own SQL is core-aware, so
 * this file only pins the strict-core coercions (#97) directly.
 */

it('truncates an over-long tim on strict core like non-strict production (#97)', function () {
    $this->artisan('core:import', ['module' => 'stock'])->assertSuccessful();
    config(['laksamana.modules.stock.connection' => 'core']);

    app(StockOrders::class)->batchOrder(
        [(object) ['item' => 'Tim Panjang Core', 'qty' => 1, 'unit' => 'Kg']],
        (object) ['tim' => str_repeat('T', 30)],
    );

    expect(DB::connection('core')->table('stock_orders')->where('item', 'Tim Panjang Core')->value('tim'))
        ->toBe(str_repeat('T', 20));
});
