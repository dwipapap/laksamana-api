<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Dw\Services\DwService;
use App\Modules\Jadwal\Services\JadwalService;

require_once __DIR__.'/helpers.php';

// Serving jadwal from core implies identity on core too (the jadwal core tables
// FK `user` and take heads/Penempatan Divisi from identity). TestCase imports it
// once per process; the application is rebuilt per test, so re-apply the
// connection here for every test (#156).
beforeEach(function () {
    if (JadwalService::onCore() && ! AccountRepository::onCore()) {
        config(['laksamana.modules.account.connection' => 'core']);
    }
});

/*
 * GET /api/v1/jadwal/dw-schedule — approved DW shifts for the "DAILY WORKER"
 * block, served to jadwal holders without the dw module through the same
 * DwService::scheduleRange read as GET /dw/schedule. Legacy jadwalDW
 * deliberately omits phone numbers; so does this.
 *
 * ONE login per test: the Sanctum guard memoizes its user for the whole
 * test. Seeding goes through DwService directly (no auth needed).
 * u-yuzaalfarel is Bar crew: holds jadwal, not dw.
 */

function jdwUser(string $id): array
{
    app(AccountRepository::class)->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1]);
    app(OfficeAccess::class)->forgetUser($id);
    app('auth')->forgetGuards();

    return officeUser($id);
}

/** One approved shift on 2026-11-10, one still waiting, one outside the range. */
function seedJdwSchedule(): void
{
    $dw = app(DwService::class);
    $w = $dw->savePekerja(['nama' => 'DW Jadwal Uji', 'hp' => '080000000092', 'divisi' => 'bar', 'posisi' => 'Bar Helper'], 'Uji');
    $ajukan = function (string $tgl, string $m, string $s) use ($dw, $w): string {
        $r = $dw->saveAjuan(['dwId' => $w['id'], 'tgl' => $tgl, 'divisi' => 'bar', 'posisi' => 'Bar Helper', 'm' => $m, 's' => $s], 'Uji');
        expect($r['saved'])->toBeTrue();

        return $r['row']['id'];
    };
    $dw->decideAjuan($ajukan('2026-11-10', '08:00', '16:00'), 'DISETUJUI', '', 'Uji');
    $ajukan('2026-11-10', '16:00', '23:00'); // still MENUNGGU: not drawn
    $dw->decideAjuan($ajukan('2026-11-20', '08:00', '16:00'), 'DISETUJUI', '', 'Uji'); // outside the range
}

it('requires login', function () {
    $this->getJson('/api/v1/jadwal/dw-schedule?from=2026-11-09&to=2026-11-12')->assertStatus(401);
});

it('requires the jadwal module', function () {
    $this->withToken(loginAs(jdwUser('u-jdw-nomodul')))->getJson('/api/v1/jadwal/dw-schedule?from=2026-11-09&to=2026-11-12')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('serves approved DW shifts to a jadwal holder without the dw module', function () {
    seedJdwSchedule();
    $token = loginAs(officeUser('u-yuzaalfarel'));

    // The dw module itself stays closed to this account: this endpoint exists
    // so the DAILY WORKER block still draws for them.
    $this->withToken($token)->getJson('/api/v1/dw/schedule?from=2026-11-09&to=2026-11-12')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');

    $res = $this->withToken($token)->getJson('/api/v1/jadwal/dw-schedule?from=2026-11-09&to=2026-11-12')
        ->assertOk()->json();
    expect($res['data'])->toHaveKeys(['rows', 'dari', 'sampai'])
        ->and($res['data']['dari'])->toBe('2026-11-09')
        ->and($res['data']['sampai'])->toBe('2026-11-12');
    $rows = collect($res['data']['rows'])->where('nama', 'DW Jadwal Uji')->values()->all();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'nama' => 'DW Jadwal Uji', 'divisi' => 'bar', 'posisi' => 'Bar Helper',
            'tgl' => '2026-11-10', 'm' => '08:00', 's' => '16:00',
        ])
        ->and(array_keys($rows[0]))->toBe(['id', 'dwId', 'nama', 'divisi', 'posisi', 'tgl', 'm', 's', 'hadir']);
    // No phone numbers anywhere in the rows, same as legacy jadwalDW.
    foreach ($res['data']['rows'] as $row) {
        expect(array_keys($row))->not->toContain('no_hp', 'hp', 'noHp', 'phone');
    }
});

it('validates its dates', function () {
    $token = loginAs(officeUser('u-yuzaalfarel'));
    $this->withToken($token)->getJson('/api/v1/jadwal/dw-schedule?from=2026-13-01&to=2026-11-12')->assertStatus(422);
});
