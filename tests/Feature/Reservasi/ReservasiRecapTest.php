<?php

use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/* u-andry holds module reservasi. Recap tests run on the same connection
 * (no prod pin in tests), with dates far in the future. */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function rsRecapSeed(string $date, array $rows): void
{
    $db = Modules::db('reservasi');
    foreach ($rows as $i => $r) {
        $id = 'recap-seed-'.$i.'-'.str_replace('-', '', $date);
        $row = array_merge([
            'id' => $id, 'name' => 'Tamu', 'phone' => '081234567890',
            'date' => $date, 'time' => '19:00', 'pax' => 2, 'status' => 'Confirmed',
            'picName' => '', 'source' => 'WA', 'dpStatus' => '', 'dpAmount' => 0,
            'table' => 'R1', 'dps' => [], 'createdAt' => 1, 'updatedAt' => 1,
        ], $r, ['id' => $id, 'date' => $date]);
        $db->insert(
            'INSERT INTO reservations (id,name,phone,tanggal,jam,pax,status,pic_name,source,dp_amount,updated_at,created_at,data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$row['id'], $row['name'], $row['phone'], $row['date'], $row['time'], $row['pax'],
                $row['status'], $row['picName'], $row['source'], (int) $row['dpAmount'],
                1000 + $i, 1000 + $i, json_encode($row, JSON_UNESCAPED_UNICODE)]
        );
    }
}

it('recap: one day, aggregates + per-row lines, no phones or photo blobs', function () {
    rsRecapSeed('2031-05-01', [
        ['name' => 'Wina Polresta', 'time' => '15:00', 'table' => 'VIP 1, VIP 2', 'pax' => 25, 'dpStatus' => 'Sudah', 'dpAmount' => 500000],
        ['name' => 'Joshua', 'time' => '19:00', 'table' => 'M15, M14', 'pax' => 4, 'status' => 'Pending'],
        ['name' => 'Batal Guy', 'status' => 'Cancelled'],
    ]);
    $token = loginAs(officeUser('u-andry'));
    $res = $this->withToken($token)->getJson('/api/v1/reservasi/recap?date=2031-05-01')->assertOk();

    expect($res->json('meta.total'))->toMatchArray(['reservasi' => 2, 'pax' => 29, 'batal' => 1, 'dpMasuk' => 500000]);
    $rows = $res->json('data');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Wina Polresta')
        ->and($rows[0]['table'])->toBe('VIP 1, VIP 2')
        ->and($rows[0]['dpAmount'])->toBe(500000)
        ->and($rows[1]['name'])->toBe('Joshua');
    $body = $res->getContent();
    expect($body)->not->toContain('081234567890')->not->toContain('Batal Guy');
});

it('recap: days=2 returns three days in order', function () {
    rsRecapSeed('2031-06-01', [['name' => 'H0']]);
    rsRecapSeed('2031-06-02', [['name' => 'H1'], ['name' => 'H1b']]);
    $token = loginAs(officeUser('u-andry'));
    $res = $this->withToken($token)->getJson('/api/v1/reservasi/recap?date=2031-06-01&days=2')->assertOk();

    $days = $res->json('data');
    expect($days)->toHaveCount(3)
        ->and($days[0]['date'])->toBe('2031-06-01')
        ->and($days[1]['date'])->toBe('2031-06-02')
        ->and($days[2]['date'])->toBe('2031-06-03')
        ->and($days[0]['total']['reservasi'])->toBe(1)
        ->and($days[1]['total']['reservasi'])->toBe(2)
        ->and($days[2]['total']['reservasi'])->toBe(0);
});

it('recap: requires login and the module', function () {
    $this->getJson('/api/v1/reservasi/recap?date=2031-05-01')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))
        ->getJson('/api/v1/reservasi/recap?date=2031-05-01')->assertStatus(403);
});

it('recap: source=prod refuses when not pinned', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/reservasi/recap?source=prod')->assertStatus(501);
});

it('recap: bad date and days are rejected', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/reservasi/recap?date=besok')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/reservasi/recap?days=99')->assertStatus(422);
});
