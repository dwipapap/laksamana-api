<?php

use Illuminate\Support\Facades\DB;

/*
 * #61: Event Planner served from core. The Event folder's own SQL still uses
 * legacy table names, so it only runs on the legacy connection; the core write
 * paths are covered here, on the event_* tables (#97).
 */

beforeEach(function () {
    config(['laksamana.modules.event.data_dir' => storage_path('framework/testing/event-db')]);
    $this->artisan('core:import', ['module' => 'event'])->assertSuccessful();
    config(['laksamana.modules.event.connection' => 'core']);
});

it('truncates an over-long indexed string on strict core like non-strict production (#97)', function () {
    $long = str_repeat('9', 40);
    $this->legacyPost('/event-api-mysql/api.php', ['action' => 'saveAll', 'data' => ['talents' => [
        ['id' => 'tl_long', 'phone' => $long, 'updatedAt' => 1],
    ]]])->assertOk()->assertJsonPath('ok', true);

    $row = DB::connection('core')->table('event_talents')->where('legacy_id', 'tl_long')->first();
    expect($row->phone)->toBe(str_repeat('9', 32))
        ->and(json_decode($row->data, true)['phone'])->toBe($long);
});
