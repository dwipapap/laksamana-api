<?php

require_once __DIR__.'/helpers.php';

use App\Modules\Kompas\Services\KompasState;
use Illuminate\Testing\TestResponse;

/* u-novi holds finance; u-aurel holds marketing (not event, not finance); u-adit none of them. */

function kpPost(array $body): TestResponse
{
    return test()->call('POST', '/kompas-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

it('kp_num reads formatted money like the app', function () {
    expect(KompasState::num('3.855.000'))->toBe(3855000)
        ->and(KompasState::num(12.6))->toBe(13)
        ->and(KompasState::num('-'))->toBe(0)
        ->and(KompasState::num(['x']))->toBe(0);
});

it('getAll carries the blob version at the top level, and a stale saveAll is refused', function () {
    $ts = kpTs();
    $this->get('/kompas-api-mysql/api.php')->assertOk()->assertJsonPath('ts', $ts)->assertJsonPath('ok', true);
    kpPost(['action' => 'saveAll', 'baseTs' => $ts - 1, 'data' => ['daily' => []]])
        ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('konflik', true)->assertJsonPath('ts', $ts);
    expect(count(kpBlob()['daily']))->toBeGreaterThan(0); // untouched
});

it('simpanRekap caps a deposit at the day\'s remaining cash and recomputes the setor flag', function () {
    $s = kpBlob();
    $day = '2026-09-10';
    $cash = KompasState::cashDay($s, $day);
    $already = KompasState::depositedDay($s, $day);
    $out = kpPost(['action' => 'simpanRekap', 'data' => ['by' => 'Kasir', 'hari' => [],
        'setoran' => ['tambah' => [['tgl' => '2026-09-20', 'hari' => [$day], 'jumlah' => [$day => '999.999.999']]]]]]);
    if ($cash - $already <= 0) {
        $out->assertJsonPath('error', 'Setoran bernilai nol — tidak ada uang yang berpindah');

        return;
    }
    $row = collect($out->assertOk()->json('data.setoran'))->last();
    expect($row['jumlah'][$day])->toBe($cash - $already)->and($row['nominal'])->toBe($cash - $already)
        ->and(kpBlob()['reports'][$day]['setor'])->toBeTrue();
});

it('simpanTarget touches only the targets and reports unknown PICs', function () {
    $before = kpBlob();
    $out = kpPost(['action' => 'simpanTarget', 'data' => ['workingDaysPerMonth' => 0, 'target' => ['ghost' => 1]]])->assertOk()->json('data');
    $after = kpBlob();
    expect($out['hilang'])->toBe(['ghost'])->and($after['settings']['workingDaysPerMonth'])->toBe(26)
        ->and($after['daily'])->toBe($before['daily'])->and($after['reports'])->toBe($before['reports']);
});

it('performaDivisi needs a session and the division\'s module', function () {
    kpPost(['action' => 'performaDivisi', 'dari' => '2026-08-01', 'sampai' => '2026-08-31'])->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'sesi_tidak_sah:'));
});

it('performaDivisi refuses a session without the Event module for divi=event', function () {
    $sesi = legacySesi(officeUser('u-aurel'));
    kpPost(['action' => 'performaDivisi', 'sesi' => $sesi, 'divi' => 'event', 'dari' => '2026-08-01', 'sampai' => '2026-08-31'])
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'tanpa_modul:'));
});

it('v1: finance reads the blob with its version; a stale whole-blob PUT is refused', function () {
    $token = loginAs(officeUser('u-novi'));
    $v = $this->withToken($token)->getJson('/api/v1/kompas/state')->assertOk()->json('meta.version');
    $this->withToken($token)->putJson('/api/v1/kompas/state', ['data' => []])->assertStatus(428);
    $this->withToken($token)->putJson('/api/v1/kompas/state?version='.($v - 1), ['data' => ['daily' => []]])->assertStatus(409);
    $this->withToken($token)->getJson('/api/v1/kompas/daily?from=2026-08-01&to=2026-08-31')->assertOk()
        ->assertJsonStructure(['data' => [['date', 'net', 'tagihan', 'netSales']]]);
});

it('v1: a narrow target write records the session user and returns the new version', function () {
    $token = loginAs(officeUser('u-novi'));
    $res = $this->withToken($token)->putJson('/api/v1/kompas/targets', ['companyMonthlyTarget' => '2.000.000.000', 'by' => 'spoof'])
        ->assertOk()->assertJsonPath('data.saved', true);
    expect($res->json('meta.version'))->toBe(kpTs())
        ->and(kpBy())->toBe(officeUser('u-novi')['name'])
        ->and(kpBlob()['settings']['companyMonthlyTarget'])->toBe(2000000000);
    $this->withToken($token)->putJson('/api/v1/kompas/rekap?version=1', ['hari' => []])->assertStatus(409);
});

it('v1: omset-pic and performa follow the module gates', function () {
    $token = loginAs(officeUser('u-aurel'));
    $this->withToken($token)->getJson('/api/v1/kompas/omset-pic?from=2026-08-01&to=2026-09-30')->assertOk()->assertJsonStructure(['data' => ['pic', 'total', 'hariAda']]);
    $this->withToken($token)->getJson('/api/v1/kompas/performa/marketing?from=2026-08-01&to=2026-09-30')->assertOk()->assertJsonStructure(['data' => ['pic', 'days', 'comps']]);
    $this->withToken($token)->getJson('/api/v1/kompas/performa/event?from=2026-08-01&to=2026-09-30')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/kompas/state')->assertStatus(403);
});

it('v1: granular parts are versioned by their own content, so edits to other parts never conflict', function () {
    $token = loginAs(officeUser('u-novi'));
    $day = $this->withToken($token)->getJson('/api/v1/kompas/days/2026-09-10')->assertOk();
    $comp = $this->withToken($token)->getJson('/api/v1/kompas/sections/compliments')->assertOk();

    // write the day …
    $rows = $day->json('data');
    $rows[] = ['food' => 1000, 'kasir' => 'Test'];
    $this->withToken($token)->putJson('/api/v1/kompas/days/2026-09-10?version='.$day->json('meta.version'), ['rows' => $rows])
        ->assertOk()->assertJsonPath('data.'.(count($rows) - 1).'.date', '2026-09-10');
    // … the compliments version read BEFORE is still valid (a different part)
    $list = $comp->json('data');
    $this->withToken($token)->putJson('/api/v1/kompas/sections/compliments?version='.$comp->json('meta.version'), ['value' => $list])->assertOk();
    // but the stale day version is refused
    $this->withToken($token)->putJson('/api/v1/kompas/days/2026-09-10?version='.$day->json('meta.version'), ['rows' => []])->assertStatus(409);
    $this->withToken($token)->putJson('/api/v1/kompas/reports/2026-09-10', ['value' => []])->assertStatus(428);
    $this->withToken($token)->getJson('/api/v1/kompas/reports/bad-date')->assertStatus(422);
    expect(kpBy())->toBe(officeUser('u-novi')['name']);
});
