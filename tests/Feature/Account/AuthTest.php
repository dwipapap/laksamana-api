<?php

use App\Auth\OfficeAccess;
use App\Support\Modules;

/** A real active user from the restored account DB, with its plain PIN. */
function anyActiveUser(bool $superadmin = false): array
{
    $db = Modules::db('account');
    $row = $superadmin
        ? $db->selectOne("SELECT u.id, u.name, u.pin FROM users u JOIN admins a ON a.user_id = u.id WHERE a.module = '*' AND u.active = 1 LIMIT 1")
        : $db->selectOne("SELECT u.id, u.name, u.pin FROM users u WHERE u.active = 1 AND u.id NOT IN (SELECT user_id FROM admins WHERE module = '*') LIMIT 1");

    return (array) $row;
}

function loginAs(array $u): string
{
    return test()->postJson('/api/v1/auth/login', ['login' => $u['name'], 'pin' => $u['pin']])
        ->assertOk()->json('data.token');
}

it('issues a Sanctum token for valid Office credentials and /me matches legacy module resolution', function () {
    $u = anyActiveUser();
    $res = $this->postJson('/api/v1/auth/login', ['login' => $u['name'], 'pin' => $u['pin']]);

    $res->assertOk()
        ->assertJsonPath('data.tokenType', 'Bearer')
        ->assertJsonPath('data.user.id', $u['id']);

    $expected = app(OfficeAccess::class)->modules($u['id']);
    $this->withToken($res->json('data.token'))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $u['id'])
        ->assertJsonPath('data.modules', $expected)
        ->assertJsonMissingPath('data.pin');
});

it('rejects wrong credentials with the v1 error envelope', function () {
    $this->postJson('/api/v1/auth/login', ['login' => 'Admin', 'pin' => '0000000'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

it('validates the login payload', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('requires a token for /me', function () {
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('cuts off a token as soon as the account is deactivated', function () {
    $u = anyActiveUser();
    $token = loginAs($u);
    Modules::db('account')->update('UPDATE users SET active = 0 WHERE id = ?', [$u['id']]);

    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
});

it('revokes the token on logout', function () {
    $token = loginAs(anyActiveUser());
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
});

it('keeps superadmin endpoints closed to non-superadmins', function () {
    $this->withToken(loginAs(anyActiveUser()))->getJson('/api/v1/account/users')
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('opens superadmin endpoints to superadmins without ever returning PINs', function () {
    $res = $this->withToken(loginAs(anyActiveUser(true)))->getJson('/api/v1/account/users')->assertOk();
    expect($res->json('data.0'))->not->toHaveKey('pin');
});

it('never falls back to PIN 1111 when a superadmin edits a user via v1', function () {
    $sa = anyActiveUser(true);
    $target = anyActiveUser();
    $this->withToken(loginAs($sa))
        ->patchJson('/api/v1/account/users/'.$target['id'], ['name' => $target['name'], 'keterangan' => 'Bar'])
        ->assertOk();

    $pin = Modules::db('account')->selectOne('SELECT pin FROM users WHERE id = ?', [$target['id']])->pin;
    expect($pin)->toBe($target['pin']);
});

it('serves the legacy account-api on its old URL with the old envelope', function () {
    $this->get('/account-api-mysql/api.php?action=ping')
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('data.pong', true)
        ->assertHeader('Access-Control-Allow-Origin', '*');

    $this->legacyPost('/account-api-mysql/api.php', ['action' => 'nope'])
        ->assertOk()->assertExactJson(['ok' => false, 'error' => 'unknown_action']);
});

it('lets a legacy session token (lm_session.token) resolve through whoami', function () {
    $u = anyActiveUser();
    $login = $this->legacyPost('/account-api-mysql/api.php', ['action' => 'login', 'name' => $u['name'], 'pin' => $u['pin']])
        ->assertOk()->json();
    expect($login['ok'])->toBeTrue()->and($login['user']['token'])->toHaveLength(64);

    $this->legacyPost('/account-api-mysql/api.php', ['action' => 'whoami', 'token' => $login['user']['token']])
        ->assertOk()
        ->assertJsonPath('user.id', $u['id'])
        ->assertJsonPath('user.modules', $login['user']['modules']);
});
