<?php

use App\Modules\Hr\Services\HrState;
use App\Support\Modules;

/* u-andry holds module `hr`; u-rizkiarfan is also the hr module admin (manageOps); u-adit's grant is revoked. */

function hrDocRev(): int
{
    return (int) app(HrState::class)->meta()->rev;
}

it('requires login and the hr module', function () {
    $this->getJson('/api/v1/hr/employees')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/hr/employees')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('lists a collection with the document rev as version', function () {
    $n = (int) Modules::db('hr')->selectOne('SELECT COUNT(*) c FROM `'.HrState::t('employees').'`')->c;
    $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/hr/employees')
        ->assertOk()->assertJsonPath('meta.total', $n)->assertJsonPath('meta.version', hrDocRev());
});

it('creates, patches and deletes a record, each write bumping the rev as the session user', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $rev = hrDocRev();

    $this->withToken($token)->postJson('/api/v1/hr/calendar', ['title' => 'Rapat'])->assertStatus(428);
    $this->withToken($token)->postJson('/api/v1/hr/calendar?version=1', ['title' => 'Rapat'])
        ->assertStatus(409)->assertJsonPath('error.details.rev', $rev);

    $res = $this->withToken($token)->postJson("/api/v1/hr/calendar?version=$rev", ['title' => 'Rapat', 'date' => '2026-10-01', 'meta' => new stdClass])
        ->assertCreated()->assertJsonPath('meta.version', $rev + 1);
    $id = $res->json('data.id');
    $row = Modules::db('hr')->selectOne('SELECT judul, tanggal, data FROM `'.HrState::t('calendar').'` WHERE `'.HrState::idCol().'` = ?', [$id]);
    expect($row->judul)->toBe('Rapat')->and($row->tanggal)->toBe('2026-10-01')->and($row->data)->toContain('"meta":{}')
        ->and(app(HrState::class)->meta()->saved_by)->toBe(officeUser('u-rizkiarfan')['name']);

    $this->withToken($token)->patchJson('/api/v1/hr/calendar/'.$id.'?version='.($rev + 1), ['kind' => 'event'])
        ->assertOk()->assertJsonPath('data.title', 'Rapat')->assertJsonPath('data.kind', 'event')->assertJsonPath('meta.version', $rev + 2);
    $this->withToken($token)->deleteJson('/api/v1/hr/calendar/'.$id.'?version='.($rev + 2))->assertOk()->assertJsonPath('meta.version', $rev + 3);
    $this->withToken($token)->deleteJson('/api/v1/hr/calendar/'.$id.'?version='.($rev + 3))->assertStatus(404);
    expect(hrDocRev())->toBe($rev + 3); // the 404 rolled back
});

it('writes kpi actual cells and settings keys', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $rev = hrDocRev();
    $this->withToken($token)->putJson("/api/v1/hr/kpi-actuals/dX/2026-09/iX?version=$rev", ['value' => 42.5])
        ->assertOk()->assertJsonPath('data.iX', 42.5);
    $this->withToken($token)->putJson('/api/v1/hr/settings/attendanceRules?version='.($rev + 1), ['value' => ['late' => 10]])
        ->assertOk()->assertJsonPath('data.late', 10);
    expect(Modules::db('hr')->selectOne('SELECT v FROM `'.HrState::t('settings')."` WHERE k='attendanceRules'")->v)->toBe('{"late":10}');
});

it('writes an attendance month into extra:attendance when its tables are missing', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/hr/attendance')->assertOk()->assertJsonPath('meta.storage', 'settings');
    $this->withToken($token)->putJson('/api/v1/hr/attendance/2099-01?version='.hrDocRev(), ['value' => ['fileName' => 'x.xlsx', 'days' => []]])
        ->assertOk()->assertJsonPath('data.fileName', 'x.xlsx');
    $map = json_decode(Modules::db('hr')->selectOne("SELECT v FROM settings WHERE k='extra:attendance'")->v, true);
    expect($map)->toHaveKeys(['2026-06', '2099-01']);
})->skip(fn () => HrState::onCore(), 'legacy storage without attendance tables; core: HrOnCoreTest');

it('appends audit entries as the session user', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/hr/audit', ['action' => 'EXPORT', 'detail' => 'csv', 'userName' => 'spoof'])
        ->assertCreated()->assertJsonPath('data.userName', officeUser('u-andry')['name'])->assertJsonPath('data.action', 'EXPORT');
});

it('keeps the manageOps pages (Kru, Kalender HR, Pengaturan, Audit Log) to the hr module admin', function () {
    $token = loginAs(officeUser('u-andry'));
    $rev = hrDocRev();
    $this->withToken($token)->postJson("/api/v1/hr/employees?version=$rev", ['name' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->putJson("/api/v1/hr/settings/k?version=$rev", ['value' => 1])->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/hr/audit')->assertStatus(403);
    // ...while every hr user may read them and write the other pages
    $this->withToken($token)->getJson('/api/v1/hr/employees')->assertOk();
    $this->withToken($token)->postJson("/api/v1/hr/okrs?version=$rev", ['ownerType' => 'div'])->assertCreated();
});

it('serves akademi training stats in-process', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/hr/training-stats')->assertOk()->assertJsonStructure(['data']);
});
