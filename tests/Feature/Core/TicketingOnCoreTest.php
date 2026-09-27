<?php

use Illuminate\Support\Facades\DB;

/*
 * #61: the public shop served from core. The Ticketing folder's own SQL uses
 * legacy table names, so the core write paths are covered here, on the
 * ticketing_* tables (#97).
 */

it('truncates an over-long buyer name on strict core like non-strict production (#97)', function () {
    config(['laksamana.modules.ticketing.data_dir' => storage_path('framework/testing/event-db')]);
    $this->artisan('core:import', ['module' => 'ticketing'])->assertSuccessful();
    config(['laksamana.modules.ticketing.connection' => 'core']);

    $long = str_repeat('N', 300);
    $this->postJson('/api/v1/tickets/buyers',
        ['name' => $long, 'email' => 'core-coerce@example.test', 'password' => 'rahasia123'])->assertCreated();

    $u = DB::connection('core')->table('ticketing_buyers')->where('email', 'core-coerce@example.test')->first();
    expect($u->name)->toBe(str_repeat('N', 255));
});
