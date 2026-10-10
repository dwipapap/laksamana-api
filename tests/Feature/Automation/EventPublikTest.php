<?php

use App\Auth\AccountRepository;
use App\Support\Modules;

require_once __DIR__.'/../Event/helpers.php';

/* Event-publik + talent-hari-ini: event DB only (no marketing), read-only
 * feeds for the guest chatbot. Tokens carry exactly `automation:read`. */

beforeEach(function () {
    config(['laksamana.modules.event.data_dir' => storage_path('framework/testing/event-db')]);
});

/** A Sanctum token with ONLY the automation ability, like `automation:token`. */
function autoEventToken(string $userId): string
{
    return app(AccountRepository::class)->tokenOwner($userId)
        ->createToken('n8n-test', ['automation:read'], null)->plainTextToken;
}

function autoEventSeedEvent(string $id, string $title, string $start, string $status = 'Upcoming', string $venue = 'Hall A', string $description = ''): void
{
    Modules::db('event')->insert(
        evSql('INSERT INTO events (id,title,status,venue,pic,start_datetime,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)'),
        [$id, $title, $status, $venue, 'PIC Event', $start, json_encode(['id' => $id, 'description' => $description], JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

function autoEventSeedTalent(string $id, string $name, string $category = 'DJ', string $phone = '0812000000'): void
{
    Modules::db('event')->insert(
        evSql('INSERT INTO talents (id,name,category,phone,status,contract_status,default_fee,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)'),
        [$id, $name, $category, $phone, 'Active', 'Kontrak', 1500000, json_encode(['id' => $id], JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

function autoEventSeedSchedule(string $id, string $talentId, string $eventId, string $tanggal, string $status = 'Confirmed'): void
{
    Modules::db('event')->insert(
        evSql('INSERT INTO schedules (id,talent_id,event_id,tanggal,start_time,end_time,performance_type,fee,status,source,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'),
        [$id, $talentId, $eventId, $tanggal, '20:00:00', '22:00:00', 'Live', 1500000, $status, 'manual', json_encode(['id' => $id], JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

it('event-publik: returns the week range, skips drafts, no phones or fees', function () {
    autoEventSeedEvent('auto-pub1', 'Konser Jumat', '2031-09-05 20:00:00', 'Upcoming', 'Hall A', 'Seru');
    autoEventSeedEvent('auto-pub2', 'Jazz Sabtu', '2031-09-06 20:00:00', 'Upcoming', 'Hall B');
    autoEventSeedEvent('auto-pub-draft', 'Rapat Internal', '2031-09-05 10:00:00', 'Draft');
    autoEventSeedEvent('auto-pub-luar', 'Konser Lalu', '2031-08-01 20:00:00');

    $token = autoEventToken('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/event-publik?from=2031-09-01&to=2031-09-07')->assertOk();

    expect($res->json('meta.feed'))->toBe('event-publik')
        ->and($res->json('meta.errors'))->toBe([])
        ->and($res->json('data.from'))->toBe('2031-09-01')
        ->and($res->json('data.to'))->toBe('2031-09-07');

    $events = $res->json('data.events');
    expect($events)->toHaveCount(2)
        ->and($events[0]['nama'])->toBe('Konser Jumat')
        ->and($events[0]['mulai'])->toBe('2031-09-05 20:00')
        ->and($events[0]['deskripsi'])->toBe('Seru')
        ->and($events[0]['bertiket'])->toBeFalse()
        ->and($events[1]['nama'])->toBe('Jazz Sabtu');
    $body = $res->getContent();
    expect($body)->not->toContain('Rapat Internal')->not->toContain('Konser Lalu');
});

it('event-publik: rejects a bad date, an inverted range and a range over 31 days', function () {
    $token = autoEventToken('u-andry');
    $this->withToken($token)->getJson('/api/v1/automation/event-publik?from=besok')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/automation/event-publik?from=2031-09-07&to=2031-09-01')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/automation/event-publik?from=2031-09-01&to=2031-10-15')->assertStatus(422);
});

it('talent-hari-ini: returns confirmed DJ/Band with event names, no phones or fees', function () {
    autoEventSeedEvent('auto-tal-ev', 'DJ Night', '2031-09-05 20:00:00', 'Upcoming', 'Hall A');
    autoEventSeedTalent('auto-tal-1', 'DJ Raka', 'DJ', '0812999888');
    autoEventSeedTalent('auto-tal-2', 'Band Sore', 'Band', '0812777666');
    autoEventSeedSchedule('auto-sch-1', 'auto-tal-1', 'auto-tal-ev', '2031-09-05');
    autoEventSeedSchedule('auto-sch-2', 'auto-tal-2', 'auto-tal-ev', '2031-09-05', 'Cancelled');

    $token = autoEventToken('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/talent-hari-ini?date=2031-09-05')->assertOk();

    expect($res->json('meta.feed'))->toBe('talent-hari-ini')
        ->and($res->json('meta.errors'))->toBe([])
        ->and($res->json('data.date'))->toBe('2031-09-05');

    $rows = $res->json('data.rows');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['talent'])->toBe('DJ Raka')
        ->and($rows[0]['kategori'])->toBe('DJ')
        ->and($rows[0]['event'])->toBe('DJ Night')
        ->and($rows[0]['jam'])->toBe('20:00 - 22:00')
        ->and($rows[0]['tipe'])->toBe('Live');
    $body = $res->getContent();
    expect($body)->not->toContain('0812999888')->not->toContain('0812777666')
        ->not->toContain('1500000')->not->toContain('Band Sore');
});

it('talent-hari-ini: Scheduled rows are included like the Office calendar', function () {
    autoEventSeedEvent('auto-tal-ev2', 'Live Malam', '2031-09-06 20:00:00', 'Upcoming', 'Hall A');
    autoEventSeedTalent('auto-tal-3', 'DJ Sore', 'DJ', '0812000111');
    autoEventSeedSchedule('auto-sch-3', 'auto-tal-3', 'auto-tal-ev2', '2031-09-06', 'Scheduled');

    $token = autoEventToken('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/talent-hari-ini?date=2031-09-06')->assertOk();

    $rows = $res->json('data.rows');
    expect($rows)->toHaveCount(1)->and($rows[0]['talent'])->toBe('DJ Sore');
});

it('event-publik and talent-hari-ini: a staff * token is forbidden, no token is 401', function () {
    $staff = loginAs(officeUser('u-andry'));
    $this->withToken($staff)->getJson('/api/v1/automation/event-publik?from=2031-09-01&to=2031-09-07')
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($staff)->getJson('/api/v1/automation/talent-hari-ini?date=2031-09-05')
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->getJson('/api/v1/automation/event-publik')->assertStatus(401);
    $this->getJson('/api/v1/automation/talent-hari-ini')->assertStatus(401);
});
