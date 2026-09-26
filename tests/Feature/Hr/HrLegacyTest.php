<?php

use App\Modules\Hr\Services\HrState;
use App\Support\Modules;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

function hrPost(mixed $body): TestResponse
{
    return test()->call('POST', '/hr-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], is_string($body) ? $body : json_encode($body));
}

function hrRev(): int
{
    return (int) app(HrState::class)->meta()->rev;
}

/** One row of a legacy hr table by its legacy id, on whichever connection hr uses. */
function hrRow(string $table, string $id, string $cols = '*'): ?stdClass
{
    return Modules::db('hr')->selectOne("SELECT $cols FROM `".HrState::t($table).'` WHERE `'.HrState::idCol().'` = ?', [$id]);
}

it('serves getAll with nested maps, rev fields, and attendance from extra:attendance when its tables are missing', function () {
    $d = $this->get('/hr-api-mysql/api.php?action=getAll')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($d)->toHaveKeys(['employees', 'audit', 'kpiActuals', 'monthlyInputs', 'attendance', 'settings', 'version', '_rev', '_savedAt', '_savedBy'])
        ->and($d['_rev'])->toBe(hrRev())
        ->and(array_keys($d['attendance']))->toBe(array_keys(json_decode(Modules::db('hr')->selectOne("SELECT v FROM settings WHERE k='extra:attendance'")->v, true)));
    // {} maps stay objects on the wire
    expect($this->get('/hr-api-mysql/api.php?action=getAll')->getContent())->toContain('"settings":{');
    $this->get('/hr-api-mysql/api.php?action=stats')->assertOk()->assertJsonPath('data.attendance_months', 0);
})->skip(fn () => HrState::onCore(), 'legacy storage without attendance tables; core: HrOnCoreTest');

it('answers errors with real HTTP status codes', function () {
    $this->get('/hr-api-mysql/api.php')->assertStatus(400)->assertExactJson(['ok' => false, 'error' => 'action tidak dikenal']);
    hrPost('nope')->assertStatus(400)->assertJsonPath('error', 'body bukan JSON');
    hrPost(['action' => 'saveAll', 'baseRev' => hrRev(), 'data' => ['employees' => 'x']])->assertStatus(400)->assertJsonPath('error', 'payload_kosong');
    $this->delete('/hr-api-mysql/api.php')->assertStatus(405);
});

it('refuses a stale rev with a 200 conflict naming the last saver', function () {
    $m = app(HrState::class)->meta();
    hrPost(['action' => 'saveAll', 'baseRev' => 1, 'data' => ['employees' => []]])
        ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error', 'conflict')
        ->assertJsonPath('rev', (int) $m->rev)->assertJsonPath('savedBy', $m->saved_by);
});

it('saves the whole document: replace, {} preserved, anonymous suggestion NULL, audit append-only, rev bumped', function () {
    $rev = hrRev();
    hrPost(['action' => 'saveAll', 'baseRev' => $rev, 'by' => 'Tester', 'data' => json_decode('{
        "employees": [{"id":"e1","name":"Satu","pin":"9999"}],
        "reviews": [{"id":"r1","empId":"e1","layers":{}}],
        "suggestions": [{"id":"s1","empId":null}],
        "kpiActuals": {"d1": {"2026-09": {"i1": "7"}}},
        "audit": [{"id":"a_1","action":"REWRITE"}],
        "settings": {}, "extraKey": [1]
    }')])->assertOk()->assertExactJson(['ok' => true, 'data' => ['rev' => $rev + 1]]);

    $db = Modules::db('hr');
    expect((int) $db->selectOne('SELECT COUNT(*) c FROM `'.HrState::t('employees').'`')->c)->toBe(1)
        ->and(hrRow('reviews', 'r1', 'data')->data)->toBe('{"id":"r1","empId":"e1","layers":{}}')
        ->and(hrRow('suggestions', 's1', 'emp_id')->emp_id)->toBeNull()
        ->and((float) $db->selectOne('SELECT nilai FROM `'.HrState::t('kpi_actuals')."` WHERE item_id='i1'")->nilai)->toBe(7.0)
        ->and(hrRow('audit', 'a_1', 'action')->action)->toBe('LOGIN')
        ->and($db->selectOne('SELECT v FROM `'.HrState::t('settings')."` WHERE k='extra:extraKey'")->v)->toBe('[1]')
        ->and(app(HrState::class)->meta()->saved_by)->toBe('Tester');
});

it('never logs the message or payload of a failure (employee PII)', function () {
    Log::spy();
    // A value that cannot be stored in the indexed column forces a database error.
    hrPost(['action' => 'saveAll', 'baseRev' => hrRev(), 'data' => ['employees' => [['id' => str_repeat('x', 300), 'name' => 'Rahasia', 'pin' => '4321']]]])
        ->assertStatus(500)->assertExactJson(['ok' => false, 'error' => 'kesalahan server']);
    Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_starts_with($msg, '[hr-api] ') && ! str_contains($msg, '4321') && ! str_contains($msg, 'Rahasia'));
});

it('keeps the server sql_mode like the legacy PDO', function () {
    expect(config('database.connections.legacy_hr.strict'))->toBeNull();
});

it('keeps the attendance history in extra:attendance across a save when its tables are missing', function () {
    $db = Modules::db('hr');
    $before = json_decode($db->selectOne("SELECT v FROM settings WHERE k='extra:attendance'")->v);
    $state = json_decode($this->get('/hr-api-mysql/api.php?action=getAll')->getContent())->data;
    hrPost(['action' => 'saveAll', 'baseRev' => $state->_rev, 'data' => $state])->assertOk();
    expect(json_decode($db->selectOne("SELECT v FROM settings WHERE k='extra:attendance'")->v))->toEqual($before);
})->skip(fn () => HrState::onCore(), 'legacy storage without attendance tables; core: HrOnCoreTest');
