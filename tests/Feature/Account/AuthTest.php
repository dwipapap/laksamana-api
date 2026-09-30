<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

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
    setUserActive($u['id'], false);

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

    $pin = officeUser($target['id'])['pin'];
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

it('gives the jadwal Akses Bawaan to a User whose Tim is FOH (#3)', function () {
    // Talenta Organization "FOH" lands in the Tim column. The owner decided
    // (#3) that it counts as floor, so it must reach the built-in jadwal
    // access — not only an explicit grant (u-andry also has one, hence
    // builtinModules, which is exactly the Akses Bawaan list).
    app(AccountRepository::class)->updateUser('u-andry', ['keterangan' => 'FOH']);
    app(OfficeAccess::class)->forgetUser('u-andry');

    expect(app(OfficeAccess::class)->builtinModules('u-andry'))->toContain('jadwal');
});

it('gives radar to every account as Akses Bawaan, yet an explicit deny still removes it', function () {
    // Legacy modul_bawaan_untuk, 27 Sep 2026: radar for everyone ("semua orang
    // bisa lihat"). Grants are applied after built-ins, so access=0 wins.
    $u = anyActiveUser();
    $access = app(OfficeAccess::class);
    expect($access->builtinModules($u['id']))->toContain('radar')
        ->and($access->hasModule($u['id'], 'radar'))->toBeTrue();

    app(AccountRepository::class)->upsertGrant($u['id'], 'radar', false, 'test');
    $access->forgetUser($u['id']);
    expect($access->hasModule($u['id'], 'radar'))->toBeFalse();
});
