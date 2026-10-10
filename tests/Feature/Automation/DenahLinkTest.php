<?php

use App\Auth\AccountRepository;
use App\Modules\Automation\Maps\DenahLink;
use App\Support\Modules;

/* Temporary public denah (option A): signed link, no storage, no PII. */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

/** A Sanctum token with ONLY the automation ability, like `automation:token`. */
function autoDenahToken(string $userId): string
{
    return app(AccountRepository::class)->tokenOwner($userId)
        ->createToken('n8n-test', ['automation:read'], null)->plainTextToken;
}

function autoDenahSeed(string $id, string $table, string $date, string $time, string $status = 'Confirmed'): void
{
    Modules::db('reservasi')->insert(
        'INSERT INTO reservations (id,name,phone,tanggal,jam,pax,status,pic_name,source,dp_amount,updated_at,created_at,data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id, 'Tamu Uji', '081234567890', $date, $time, 2, $status, '', 'WA', 0, 1000, 1000,
            json_encode(['id' => $id, 'name' => 'Tamu Uji', 'phone' => '081234567890', 'time' => '20:00', 'table' => $table, 'tables' => $table, 'status' => $status], JSON_UNESCAPED_UNICODE)]
    );
}

it('denah-link: mints a URL, the page marks the booked table, no PII leaks', function () {
    autoDenahSeed('auto-den-u1', 'U1', '2031-07-10', '20:00:00');

    $token = autoDenahToken('u-andry');
    $mint = $this->withToken($token)->postJson('/api/v1/automation/denah-link', ['date' => '2031-07-10', 'time' => '19:00'])->assertOk();
    $url = $mint->json('data.url');
    expect($url)->toContain('/api/v1/automation/denah?t=');

    $page = $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertOk();
    $html = $page->getContent();
    expect($html)
        ->toContain('data-table="U1" data-status="booked"')
        ->toContain('data-table="U2" data-status="free"')
        ->not->toContain('081234567890')
        ->not->toContain('Tamu Uji');
});

it('denah-link: rejects bad input, denah rejects tampered and expired tokens', function () {
    $token = autoDenahToken('u-andry');
    $this->withToken($token)->postJson('/api/v1/automation/denah-link', ['date' => 'besok', 'time' => '19:00'])->assertStatus(422);

    $this->get('/api/v1/automation/denah')->assertStatus(403);
    $this->get('/api/v1/automation/denah?t=dipotong')->assertStatus(403);

    $minted = DenahLink::mint('2031-07-10', '19:00', 1000);
    expect($minted)->not->toBeNull();
    $this->get('/api/v1/automation/denah?t='.$minted['token'])->assertStatus(410);
    expect(DenahLink::verify($minted['token'], 1000 + DenahLink::TTL_SECONDS - 1))->toMatchArray(['ok' => true]);
});

it('denah-link: a staff * token is forbidden, no token is 401', function () {
    $staff = loginAs(officeUser('u-andry'));
    $this->withToken($staff)->postJson('/api/v1/automation/denah-link', ['date' => '2031-07-10', 'time' => '19:00'])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->postJson('/api/v1/automation/denah-link', ['date' => '2031-07-10', 'time' => '19:00'])->assertStatus(401);
});
