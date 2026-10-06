<?php

use App\Support\Modules;

require_once __DIR__.'/../Event/helpers.php';
require_once __DIR__.'/../Marketing/helpers.php';
require_once __DIR__.'/../Reservasi/helpers.php';

/* u-andry holds module reservasi (the gate for /api/v1/info/pagi);
 * u-adit does not. InfoPagi reads the dev module DBs by default (no pins
 * in tests), seeded with dates far in the future. */

beforeEach(function () {
    config(['laksamana.modules.event.data_dir' => storage_path('framework/testing/event-db')]);
    config(['laksamana.modules.marketing.data_dir' => storage_path('framework/testing/marketing-db')]);
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function infoPagiSeedEvent(string $id, string $title, string $start, string $status = 'Upcoming', string $venue = 'Hall A', string $pic = 'PIC Event'): void
{
    Modules::db('event')->insert(
        evSql('INSERT INTO events (id,title,status,venue,pic,start_datetime,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)'),
        [$id, $title, $status, $venue, $pic, $start, json_encode(['id' => $id], JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

function infoPagiSeedMarketingEvent(string $id, string $nama, string $tanggal, string $status = 'Deal'): void
{
    Modules::db('marketing')->insert(
        mktSql('INSERT INTO events (id,nama,tanggal,status,pax,mkt_pic,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)'),
        [$id, $nama, $tanggal, $status, 50, '', json_encode(['id' => $id], JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

it('info pagi: one date, event + marketing combined, no phones', function () {
    infoPagiSeedEvent('ip-ev1', 'Konser Pagi', '2031-08-01 07:00:00');
    infoPagiSeedEvent('ip-ev-draft', 'Draft Malam', '2031-08-01 20:00:00', 'Draft');
    infoPagiSeedMarketingEvent('ip-mk1', 'Wedding Budi', '2031-08-01');

    $token = loginAs(officeUser('u-andry'));
    $res = $this->withToken($token)->getJson('/api/v1/info/pagi?date=2031-08-01')->assertOk();

    expect($res->json('meta.date'))->toBe('2031-08-01');
    $ev = $res->json('data.event');
    expect($ev)->toHaveCount(1)
        ->and($ev[0]['nama'])->toBe('Konser Pagi')
        ->and($ev[0]['venue'])->toBe('Hall A');
    $mk = $res->json('data.marketing.events');
    expect($mk)->toHaveCount(1)->and($mk[0]['nama'])->toBe('Wedding Budi');
    $body = $res->getContent();
    expect($body)->not->toContain('0812')->not->toContain('Draft Malam');
});

it('info pagi: requires login and the reservasi module', function () {
    $this->getJson('/api/v1/info/pagi?date=2031-08-01')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))
        ->getJson('/api/v1/info/pagi?date=2031-08-01')->assertStatus(403)
        ->assertJsonPath('error.code', 'module_not_granted');
});

it('info pagi: source=prod refuses when not pinned', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/info/pagi?source=prod')->assertStatus(501);
});

it('info pagi: rejects an invalid date', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/info/pagi?date=2031-8-1')->assertStatus(422);
});
