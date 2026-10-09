<?php

use App\Modules\Event\Services\EventRecords;
use App\Modules\Marketing\Services\MarketingRecords;
use App\Modules\Reservasi\Services\ReservasiState;

/*
 * GET /api/v1/dw/guests — daily guest pax per source for the DW Kalender
 * Tamu, read in-process from the marketing/event/reservasi modules so a
 * `dw` holder needs none of them.
 *
 * ONE login per test: the Sanctum guard memoizes its user for the whole
 * test. Seeding goes through the source services directly (no auth needed).
 */

/** Seed source rows around 2026-11-10 and return nothing; assertions compare before/after deltas. */
function seedDwGuests(): void
{
    $mkt = app(MarketingRecords::class);
    $mkt->create('events', ['nama' => 'G Deal', 'tanggal' => '2026-11-10', 'status' => 'Deal', 'pax' => 120], 'Uji');
    $mkt->create('events', ['nama' => 'G Lead', 'tanggal' => '2026-11-10', 'status' => 'Lead', 'pax' => 500], 'Uji');
    $mkt->create('events', ['nama' => 'G Done', 'tanggal' => '2026-11-11', 'status' => 'Event Done', 'pax' => 40], 'Uji');
    $mkt->create('events', ['nama' => 'G Confirmed', 'tanggal' => '2026-11-11', 'status' => 'confirmed', 'pax' => 10], 'Uji');
    $mkt->create('events', ['nama' => 'G Luar', 'tanggal' => '2026-11-20', 'status' => 'Deal', 'pax' => 70], 'Uji');
    // VIP counts its upper bound; a cancelled one never counts, whatever its kind.
    $mkt->create('vip', ['nama' => 'VIP Besar', 'tanggal' => '2026-11-10', 'paxMin' => 80, 'paxMax' => 100], 'Uji');
    $mkt->create('vip', ['nama' => 'VIP Min Saja', 'tanggal' => '2026-11-11', 'paxMin' => 30], 'Uji');
    $mkt->create('vip', ['nama' => 'VIP Regular', 'tanggal' => '2026-11-11', 'jenis' => 'Regular', 'paxMin' => 7, 'paxMax' => 12], 'Uji');
    $mkt->create('vip', ['nama' => 'VIP Batal', 'tanggal' => '2026-11-10', 'paxMin' => 5, 'paxMax' => 9, 'batalAt' => 1762742400000], 'Uji');

    $evt = app(EventRecords::class);
    $by = ['id' => 'u-uji-tamu', 'name' => 'Uji'];
    // 12:30Z = 19:30 WIB the same day.
    $evt->create('events', ['title' => 'E Malam', 'start_datetime' => '2026-11-10T12:30:00.000Z', 'status' => 'upcoming', 'capacity' => 50], $by);
    // 18:30Z Nov 9 = 01:30 WIB Nov 10: the WIB date decides, not the UTC one.
    $evt->create('events', ['title' => 'E Lewat Tengah Malam', 'start_datetime' => '2026-11-09T18:30:00.000Z', 'status' => 'today', 'capacity' => 20], $by);
    $evt->create('events', ['title' => 'E Draft', 'start_datetime' => '2026-11-10T10:00:00.000Z', 'status' => 'draft', 'capacity' => 999], $by);

    $rsv = app(ReservasiState::class);
    $rsv->upsertRow(['id' => 'gj-rsv-1', 'date' => '2026-11-10', 'time' => '19:00', 'name' => 'Tamu A', 'pax' => 6, 'status' => 'Confirmed']);
    $rsv->upsertRow(['id' => 'gj-rsv-2', 'date' => '2026-11-10', 'time' => '20:00', 'name' => 'Tamu Batal', 'pax' => 4, 'status' => 'Cancelled']);
    $rsv->upsertRow(['id' => 'gj-rsv-3', 'date' => '2026-11-10', 'time' => '21:00', 'name' => 'Tamu Hilang', 'pax' => 3, 'status' => 'No-show']);
    $rsv->upsertRow(['id' => 'gj-rsv-4', 'date' => '2026-11-11', 'time' => '19:00', 'name' => 'Tamu B', 'pax' => 2, 'status' => 'Pending']);
}

function guestPaxMap(array $rows): array
{
    $m = [];
    foreach ($rows as $r) {
        $m[$r['tgl'].'|'.$r['sumber']] = (int) $r['pax'];
    }

    return $m;
}

it('requires login', function () {
    $this->getJson('/api/v1/dw/guests?from=2026-11-09&to=2026-11-12')->assertStatus(401);
});

it('requires the dw module', function () {
    $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->getJson('/api/v1/dw/guests?from=2026-11-09&to=2026-11-12')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('sums daily pax per source with the old calendar rules', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $url = '/api/v1/dw/guests?from=2026-11-09&to=2026-11-12';
    $before = guestPaxMap($this->withToken($token)->getJson($url)->assertOk()->json('data.rows'));

    seedDwGuests();

    $res = $this->withToken($token)->getJson($url)->assertOk()->json();
    expect($res['data'])->toHaveKeys(['rows', 'dari', 'sampai'])
        ->and($res['data']['dari'])->toBe('2026-11-09')
        ->and($res['data']['sampai'])->toBe('2026-11-12');
    $rows = $res['data']['rows'];
    foreach ($rows as $r) {
        expect($r)->toHaveKeys(['tgl', 'sumber', 'pax'])
            ->and(array_keys($r))->toBe(['tgl', 'sumber', 'pax'])
            ->and($r['sumber'])->toBeIn(['marketing', 'event', 'reservasi']);
    }
    // Ordered by day then source.
    $keys = array_map(fn ($r) => $r['tgl'].'|'.$r['sumber'], $rows);
    expect($keys)->toBe(collect($keys)->sort()->values()->all());

    $after = guestPaxMap($rows);
    $delta = function (string $k) use ($after, $before): int {
        return ($after[$k] ?? 0) - ($before[$k] ?? 0);
    };
    // Marketing: Deal counts, Lead does not; Event Done + lowercase confirmed count.
    // VIP folds into marketing at its upper bound; the cancelled one never counts.
    expect($delta('2026-11-10|marketing'))->toBe(120 + 100)
        ->and($delta('2026-11-11|marketing'))->toBe(40 + 10 + 30 + 12)
        // Event: upcoming + today count on their WIB date (the 18:30Z Nov 9
        // show lands on Nov 10); draft does not.
        ->and($delta('2026-11-10|event'))->toBe(50 + 20)
        ->and($delta('2026-11-09|event'))->toBe(0)
        ->and($delta('2026-11-11|event'))->toBe(0)
        // Reservasi: Confirmed + Pending count, Cancelled does not, No-show is 0.
        ->and($delta('2026-11-10|reservasi'))->toBe(6)
        ->and($delta('2026-11-11|reservasi'))->toBe(2);
});

it('validates its dates and orders a swapped range', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $this->withToken($token)->getJson('/api/v1/dw/guests?from=2026-13-01&to=2026-11-12')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/dw/guests?from=2026-11-12&to=2026-11-09')->assertOk()
        ->assertJsonPath('data.dari', '2026-11-09')
        ->assertJsonPath('data.sampai', '2026-11-12');
});
