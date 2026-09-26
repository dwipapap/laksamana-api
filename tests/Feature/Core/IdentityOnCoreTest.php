<?php

use App\Modules\Jadwal\Services\HeadDirectory;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #44: account, access and Divisi served from core. Each test imports the
 * restored identity and switches the account Modul to core; the default
 * (legacy) connection is the rollback path, covered by the rest of the suite.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    config(['laksamana.modules.account.connection' => 'core']);
});

it('logs in with a legacy sesi stored in core and keeps legacy ids on the wire', function () {
    $u = officeUser('u-arif');
    $login = $this->legacyPost('/account-api-mysql/api.php', ['action' => 'login', 'name' => $u['name'], 'pin' => $u['pin']])
        ->assertOk()->json();
    expect($login['ok'])->toBeTrue()
        ->and($login['user']['id'])->toBe('u-arif')
        ->and($login['user']['modules'])->toContain('dw') // Akses Bawaan of a Kepala Divisi, read from core
        ->and(DB::connection('core')->table('sesi_legacy')->where('token', $login['user']['token'])->exists())->toBeTrue();

    $this->legacyPost('/account-api-mysql/api.php', ['action' => 'whoami', 'token' => $login['user']['token']])
        ->assertOk()->assertJsonPath('user.id', 'u-arif')->assertJsonPath('user.modules', $login['user']['modules']);
});

it('issues Sanctum tokens owned by the core User and exposes its ULID on v1', function () {
    $u = officeUser('u-arif');
    $ulid = DB::connection('core')->table('user')->where('legacy_id', 'u-arif')->value('id');
    $token = loginAs($u);

    $me = $this->withToken($token)->getJson('/api/v1/me')->assertOk()->json('data');
    expect($me['id'])->toBe('u-arif')->and($me['ulid'])->toBe($ulid)->and(Str::isUlid($me['ulid']))->toBeTrue()
        ->and(DB::connection('core')->table('personal_access_tokens')->where('tokenable_id', $ulid)->exists())->toBeTrue();
});

it('moves Kepala Divisi and Penempatan Divisi out of the jadwal blob', function () {
    $jadwal = app(JadwalService::class);
    $data = JsonDoc::toArray($jadwal->setting());
    $data['heads'] = ['bar' => ['u-yuzaalfarel', 'u-arif'], 'floor' => []];
    $data['divOverride'] = ['u-andry' => 'floor', 'u-ghost' => 'bar'];
    $jadwal->saveSetting($data, officeUser('u-admin')['name']);
    app(HeadDirectory::class)->flush();

    $blob = json_decode(Modules::db('jadwal')->selectOne('SELECT data FROM jadwal_setting WHERE id = 1')->data, true);
    $fresh = JsonDoc::toArray(app(JadwalService::class)->setting());
    expect($blob)->not->toHaveKey('heads')->and($blob)->not->toHaveKey('divOverride')
        ->and($fresh['heads'])->toBe(['bar' => ['u-yuzaalfarel', 'u-arif']])
        ->and($fresh['divOverride'])->toBe(['u-andry' => 'floor']) // no User, no row (FK)
        ->and(app(HeadDirectory::class)->compute())->toBe(['u-yuzaalfarel' => ['bar'], 'u-arif' => ['bar']])
        ->and(app(JadwalService::class)->divisiUser('u-andry'))->toBe('floor');
});

it('refuses on v1 to delete a deactivated Kepala Divisi', function () {
    DB::connection('core')->table('user')->where('legacy_id', 'u-arif')->update(['aktif' => 0]);
    $admin = officeUser('u-admin');

    $this->withToken(loginAs($admin))->deleteJson('/api/v1/account/users/u-arif')
        ->assertStatus(409)->assertJsonPath('error.code', 'is_kepala_divisi')->assertJsonPath('error.details.divisi', ['bar']);
    expect(DB::connection('core')->table('user')->where('legacy_id', 'u-arif')->exists())->toBeTrue();
});
