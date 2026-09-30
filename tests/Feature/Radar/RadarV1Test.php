<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Radar\Services\RadarRules;

/*
 * /api/v1/radar against the restored dumps. Radar is built in for every
 * account, so the users here hold no source module at all: the board must
 * still answer, in the narrow Radar shapes, with money only for a Head.
 */

function rdUser(string $id, string $ket = ''): array
{
    app(AccountRepository::class)->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => $ket]);
    app(OfficeAccess::class)->forgetUser($id);
    app('auth')->forgetGuards();

    return officeUser($id);
}

it('opens the board to an account holding no source module, in the narrow shapes', function () {
    $token = loginAs(rdUser('u-radar-kru'));
    $res = $this->withToken($token)->getJson('/api/v1/radar/board?from=2026-01-01&to=2026-12-31')->assertOk();

    expect($res->json('data'))->toHaveKeys(['agenda', 'reservations', 'fbFormats', 'sumber', 'bolehUang'])
        ->and($res->json('data.bolehUang'))->toBeFalse()
        ->and(collect($res->json('data.sumber'))->pluck('id')->all())->toBe(['mkt', 'evt', 'rsv']);
    foreach ($res->json('data.reservations') as $r) {
        expect($r)->not->toHaveKeys(['dpAmount', 'dpStatus', 'dps', 'dpProofData', 'tfName', 'log']);
    }
    foreach ($res->json('data.agenda') as $a) {
        expect($a['sumber'])->toBeIn(['mkt', 'evt', 'vip'])
            ->and($a['tgl'] >= '2026-01-01' && $a['tgl'] <= '2026-12-31')->toBeTrue();
    }
    // The source modules themselves stay closed to this account.
    $this->withToken($token)->getJson('/api/v1/marketing/state')->assertStatus(403);
});

it('shows money to a Head', function () {
    $res = $this->withToken(loginAs(rdUser('u-radar-head', 'Head Floor')))
        ->getJson('/api/v1/radar/board?from=2026-01-01&to=2026-12-31')->assertOk();
    expect($res->json('data.bolehUang'))->toBeTrue();
});

it('opens a detail from the board and hides money fields from a non-Head', function () {
    $token = loginAs(rdUser('u-radar-det'));
    $agenda = $this->withToken($token)->getJson('/api/v1/radar/board?from=2025-01-01&to=2026-12-31')->assertOk()->json('data.agenda');
    $mkt = collect($agenda)->firstWhere('sumber', 'mkt');
    if (! $mkt) {
        $this->markTestSkipped('No Marketing event that will happen in the restored dump for that range.');
    }
    $d = $this->withToken($token)->getJson("/api/v1/radar/agenda/mkt/{$mkt['id']}")->assertOk()->json('data');
    expect($d['id'])->toBe($mkt['id'])
        ->and($d['mkt']['pembayaran'])->toBeNull();
    foreach (array_keys((array) $d['mkt']['detail']) as $k) {
        expect(RadarRules::isMoneyKey($k))->toBeFalse();
    }
});

it('answers 404 for what is not on the board, and for a file key that is not an attachment', function () {
    $token = loginAs(rdUser('u-radar-404'));
    $this->withToken($token)->getJson('/api/v1/radar/agenda/mkt/tidak-ada')->assertStatus(404);
    $this->withToken($token)->getJson('/api/v1/radar/files/rc_tebakan.png')->assertStatus(404);
    $this->withToken($token)->getJson('/api/v1/radar/board?from=2020-01-01&to=2026-12-31')->assertStatus(422);
});

it('lists running and upcoming promos only', function () {
    $res = $this->withToken(loginAs(rdUser('u-radar-promo')))->getJson('/api/v1/radar/promos')->assertOk();
    foreach ($res->json('data.promos') as $p) {
        expect($p['status'])->toBeIn(['running', 'upcoming']);
    }
    expect($res->json('data.arsip'))->toBeInt();
});

it('stays closed when radar is explicitly denied', function () {
    $u = rdUser('u-radar-deny');
    app(AccountRepository::class)->upsertGrant($u['id'], 'radar', false, 'test');
    app(OfficeAccess::class)->forgetUser($u['id']);
    $this->withToken(loginAs($u))->getJson('/api/v1/radar/board')->assertStatus(403);
});
