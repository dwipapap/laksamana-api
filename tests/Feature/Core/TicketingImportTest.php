<?php

use Illuminate\Support\Facades\DB;

/*
 * #61: ticketing's OWN tables in core, imported from the shared EMS database:
 * the Buyers (legacy tix_users), their sessions and password resets (keyed by
 * `token` in legacy), the seat holds and the rate-limit rows. The EMS tables
 * the shop reads/writes belong to the event import (EventImportTest).
 */

const TIX_TABLES = [
    'seat_holds' => 'ticketing_seat_holds',
    'tix_users' => 'ticketing_buyers',
    'tix_sessions' => 'ticketing_sessions',
    'tix_reset' => 'ticketing_resets',
    'tix_gagal' => 'ticketing_gagal',
];

function tixKey(string $legacy): string
{
    return $legacy === 'tix_sessions' || $legacy === 'tix_reset' ? 'token' : 'id';
}

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'ticketing'])->assertSuccessful();
});

it('copies the shop tables 1:1 and is idempotent', function () {
    foreach (TIX_TABLES as $legacy => $core) {
        $key = tixKey($legacy);
        $rows = DB::connection('legacy_ems')->table($legacy)->get();
        $copied = DB::connection('core')->table($core)->get()->keyBy('legacy_id');

        expect($copied->keys()->all())->toBe($rows->pluck($key)->map(fn ($v) => (string) $v)->sort()->values()->all(), $legacy);
        foreach ($rows as $r) {
            expect($copied[(string) $r->{$key}]->legacy_id)->toBe((string) $r->{$key});
        }
    }

    $buyer = DB::connection('legacy_ems')->table('tix_users')->orderBy('id')->first();
    $row = DB::connection('core')->table('ticketing_buyers')->where('legacy_id', $buyer->id)->first();
    expect($row->email)->toBe($buyer->email)->and($row->pass_hash)->toBe($buyer->pass_hash)
        ->and($row->name)->toBe($buyer->name)->and((int) $row->version)->toBe(1);

    $before = DB::connection('core')->table('ticketing_buyers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $this->artisan('core:import', ['module' => 'ticketing'])->assertSuccessful();
    expect(DB::connection('core')->table('ticketing_buyers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});

it('links every session and reset to its Buyer', function () {
    foreach (['tix_sessions' => 'ticketing_sessions', 'tix_reset' => 'ticketing_resets'] as $legacy => $core) {
        $rows = DB::connection('legacy_ems')->table($legacy)->whereNotNull('user_id')->where('user_id', '<>', '')->get();
        if ($rows->isEmpty()) {
            $this->markTestSkipped("no $legacy rows with a user_id in this dump");
        }
        foreach ($rows as $r) {
            $buyer = DB::connection('core')->table('ticketing_buyers')->where('legacy_id', $r->user_id)->value('id');
            $link = DB::connection('core')->table($core)->where('legacy_id', $r->token)->value('buyer_id');
            expect($link)->toBe($buyer, $legacy);
        }
    }
});
