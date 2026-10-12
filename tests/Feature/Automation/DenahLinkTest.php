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
    expect($url)->toContain('/d/');

    $page = $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
    $html = $page->getContent();
    expect($html)
        ->toContain('data-table="U1" data-status="booked"')
        ->toContain('data-table="U2" data-status="free"')
        ->not->toContain('081234567890')
        ->not->toContain('Tamu Uji');
});

it('denah-link: the public base is configurable (main domain), token stays short', function () {
    config(['denah.public_base' => 'https://laksamanamuda.id']);

    $token = autoDenahToken('u-andry');
    $mint = $this->withToken($token)->postJson('/api/v1/automation/denah-link', ['date' => '2031-07-10', 'time' => '19:00'])->assertOk();
    $url = (string) $mint->json('data.url');
    expect($url)->toStartWith('https://laksamanamuda.id/d/');

    // The compact (v2) token: 11-char body + '.' + 11-char tag.
    $suffix = substr($url, strlen('https://laksamanamuda.id/d/'));
    expect(strlen($suffix))->toBeLessThanOrEqual(24);

    $minted = DenahLink::mint('2031-07-10', '19:00', 1_000_000);
    expect($minted)->not->toBeNull();
    expect(strlen($minted['token']))->toBeLessThanOrEqual(24);
    expect(DenahLink::verify($minted['token'], 1_000_000))->toMatchArray(['ok' => true, 'date' => '2031-07-10', 'time' => '19:00']);
});

it('denah-link: still verifies a legacy (v1 JSON) token until it expires', function () {
    $date = '2031-07-10';
    $time = '19:00';
    $exp = 1_000_000 + DenahLink::TTL_SECONDS;
    $payload = json_encode(['v' => 1, 'date' => $date, 'time' => $time, 'exp' => $exp]);
    $body = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, (string) config('app.key'), true)), '+/', '-_'), '=');
    $legacy = $body.'.'.$sig;

    expect(DenahLink::verify($legacy, 1_000_000))
        ->toMatchArray(['ok' => true, 'date' => $date, 'time' => $time]);
    expect(DenahLink::verify($legacy, $exp))->toMatchArray(['ok' => false, 'error' => 'expired']);

    // A tampered signature never passes (flip the FIRST tag char: the last one
    // only carries base64 padding bits and could decode to the same bytes).
    $flip = $sig;
    $flip[0] = $flip[0] === 'A' ? 'B' : 'A';
    expect(DenahLink::verify($body.'.'.$flip, 1_000_000))
        ->toMatchArray(['ok' => false, 'error' => 'invalid']);

    // Same for the compact format.
    $compact = DenahLink::mint('2031-07-10', '19:00', 1_000_000);
    $cpart = explode('.', $compact['token']);
    $cflip = $cpart[1];
    $cflip[0] = $cflip[0] === 'A' ? 'B' : 'A';
    expect(DenahLink::verify($cpart[0].'.'.$cflip, 1_000_000))
        ->toMatchArray(['ok' => false, 'error' => 'invalid']);
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
