<?php

use App\Support\Modules;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/* u-wandi is the superadmin; u-dinamariana holds tree only; u-adit holds neither. */

beforeEach(function () {
    config(['laksamana.modules.stock.data_dir' => storage_path('framework/testing/stock')]);
});

afterEach(function () {
    foreach (['usage', 'sales_detail'] as $sub) {
        File::deleteDirectory(storage_path('framework/testing/stock/'.$sub));
    }
});

function stockAdminPost(string $file, mixed $body): TestResponse
{
    return test()->call('POST', '/stock-api-mysql/'.$file, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=utf-8'],
        is_string($body) ? $body : json_encode($body));
}

function stockAdminDir(): string
{
    return storage_path('framework/testing/stock');
}

it('legacy: users are served with PINs and add/update/delete keep the last admin', function () {
    $this->get('/stock-api-mysql/users.php')->assertOk()
        ->assertJsonCount(1, 'users')->assertJsonStructure(['users' => [['id', 'name', 'pin', 'role', 'keterangan']]]);

    stockAdminPost('users.php', ['action' => 'add', 'user' => ['id' => 'u-parity', 'name' => 'Kru Parity', 'pin' => '1234', 'keterangan' => 'Kitchen']])
        ->assertExactJson(['status' => 'success']);
    expect(json_decode(Modules::db('stock')->selectOne("SELECT data FROM users WHERE id='u-parity'")->data, true))
        ->toMatchArray(['id' => 'u-parity', 'name' => 'Kru Parity', 'role' => 'full', 'keterangan' => 'Kitchen']);

    stockAdminPost('users.php', ['action' => 'update', 'user' => ['id' => 'u-parity', 'name' => 'Kru Diubah', 'role' => 'full']])
        ->assertExactJson(['status' => 'success']);
    expect(json_decode(Modules::db('stock')->selectOne("SELECT data FROM users WHERE id='u-parity'")->data, true))
        ->toMatchArray(['name' => 'Kru Diubah', 'pin' => '', 'role' => 'full']);

    stockAdminPost('users.php', ['action' => 'add'])->assertExactJson(['status' => 'error', 'message' => 'user tanpa id']);
    stockAdminPost('users.php', ['action' => 'delete', 'user' => ['id' => 'u-admin']])
        ->assertExactJson(['status' => 'error', 'message' => 'tidak bisa menghapus admin terakhir']);
    stockAdminPost('users.php', ['action' => 'delete', 'user' => ['id' => 'u-parity']])
        ->assertExactJson(['status' => 'success', 'deleted' => 1]);
});

it('legacy: ordering users save one, seed many without deleting, and delete by id', function () {
    $res = stockAdminPost('ordering-users.php', ['action' => 'saveUser', 'user' => ['name' => 'Dapur Parity', 'pin' => '4321']])
        ->assertExactJson(['status' => 'success']);
    $id = (string) Modules::db('stock')->selectOne("SELECT id FROM ordering_users WHERE nama='Dapur Parity'")->id;
    expect($id)->toStartWith('u-')->and($res->json())->toBe(['status' => 'success']);

    stockAdminPost('ordering-users.php', ['action' => 'bulkSeed', 'users' => [
        ['id' => 'u-seed-1', 'name' => 'Seed Satu', 'pin' => '1', 'role' => 'checkin'],
        ['id' => $id, 'name' => 'Dapur Diubah', 'pin' => '9'],
        'not-an-object',
    ]])->assertExactJson(['status' => 'success', 'seeded' => 2]);
    expect((int) Modules::db('stock')->selectOne('SELECT COUNT(*) c FROM ordering_users')->c)->toBe(29)
        ->and(json_decode(Modules::db('stock')->selectOne("SELECT data FROM ordering_users WHERE id='$id'")->data, true)['name'])->toBe('Dapur Diubah');

    stockAdminPost('ordering-users.php', ['action' => 'bulkSeed', 'users' => (object) ['x' => 1]])
        ->assertExactJson(['status' => 'error', 'message' => 'users bukan array']);
    stockAdminPost('ordering-users.php', ['action' => 'saveUser', 'user' => 'x'])
        ->assertExactJson(['status' => 'error', 'message' => 'user tidak sah']);
    stockAdminPost('ordering-users.php', ['action' => 'deleteUser', 'user' => ['id' => $id]])
        ->assertExactJson(['status' => 'success', 'deleted' => 1]);
});

it('legacy: settings reproduce the missing stock_settings table as 500', function () {
    foreach (['ordering-settings.php', 'purchasing-settings.php'] as $file) {
        $this->get('/stock-api-mysql/'.$file)->assertStatus(500)
            ->assertExactJson(['status' => 'error', 'message' => 'kesalahan server']);
    }
    stockAdminPost('ordering-settings.php', ['action' => 'savePerms', 'perms' => (object) []])
        ->assertStatus(500)->assertJsonPath('message', 'kesalahan server');
    stockAdminPost('purchasing-settings.php', ['action' => 'saveTemplates', 'templates' => (object) []])
        ->assertStatus(500)->assertJsonPath('message', 'kesalahan server');
    stockAdminPost('ordering-settings.php', ['action' => 'nope'])
        ->assertStatus(400)->assertJsonPath('message', 'action tidak dikenal: nope');
});

it('legacy: training stores an xlsx, summarises it and keeps list/get behind the API token', function () {
    $this->get('/stock-api-mysql/training.php')->assertOk()
        ->assertExactJson(['status' => 'success',
            'usage' => ['files' => 0, 'last_date' => null, 'next_from' => null],
            'sales_detail' => ['files' => 0, 'last_date' => null, 'next_from' => null]]);

    $this->get('/stock-api-mysql/training.php?action=list&target=usage')->assertStatus(403)
        ->assertExactJson(['status' => 'error', 'message' => 'unduh arsip butuh API_TOKEN diatur di config.php']);
    $this->get('/stock-api-mysql/training.php?action=get&target=usage&name=x.xlsx')->assertStatus(403);

    stockAdminPost('training.php', ['type' => 'training', 'target' => 'usage', 'filename' => 'x.txt', 'content_b64' => 'QUJD'])
        ->assertExactJson(['status' => 'error', 'message' => 'hanya berkas .xlsx/.xls']);
    stockAdminPost('training.php', ['type' => 'training', 'target' => 'usage', 'filename' => 'x.xlsx', 'content_b64' => '!!'])
        ->assertExactJson(['status' => 'error', 'message' => 'isi berkas tidak terbaca']);

    $saved = stockAdminPost('training.php', ['type' => 'training', 'target' => 'usage', 'filename' => '../x.xlsx', 'content_b64' => base64_encode('ABC')])
        ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('size_kb', 0);
    expect($saved->json('saved_as'))->toMatch('/^\d{8}-\d{6}_x\.xlsx$/')
        ->and(is_file(stockAdminDir().'/usage/'.$saved->json('saved_as')))->toBeTrue();
    $this->get('/stock-api-mysql/training.php')->assertOk()->assertJsonPath('usage.files', 1);
});

it('v1: users are versioned and never return a PIN', function () {
    $token = loginAs(officeUser('u-wandi'));
    $list = $this->withToken($token)->getJson('/api/v1/stock/users')->assertOk();
    foreach ($list->json('data') as $row) {
        expect($row)->not->toHaveKey('pin');
    }

    $created = $this->withToken($token)->postJson('/api/v1/stock/users', [
        'id' => 'u-v1-test', 'name' => 'Kru V1', 'role' => 'full', 'keterangan' => 'Kitchen', 'pin' => '9999',
    ])->assertCreated()->assertJsonPath('data.name', 'Kru V1');
    expect($created->json('data'))->not->toHaveKey('pin');
    $v = $created->json('meta.version');

    $this->withToken($token)->patchJson('/api/v1/stock/users/u-v1-test', ['role' => 'admin'])->assertStatus(428);
    $this->withToken($token)->patchJson('/api/v1/stock/users/u-v1-test?version=stale', ['role' => 'admin'])->assertStatus(409);
    $patched = $this->withToken($token)->patchJson('/api/v1/stock/users/u-v1-test?version='.$v, ['role' => 'admin'])
        ->assertOk()->assertJsonPath('data.role', 'admin');
    expect($patched->json('data'))->not->toHaveKey('pin')
        ->and(json_decode(Modules::db('stock')->selectOne("SELECT data FROM users WHERE id='u-v1-test'")->data, true)['pin'])->toBe('9999');

    $this->withToken($token)->deleteJson('/api/v1/stock/users/u-v1-test?version='.$patched->json('meta.version'))->assertOk();
});

it('v1: the last Purchasing admin cannot be deleted and non-admins are refused', function () {
    $token = loginAs(officeUser('u-wandi'));
    $admin = $this->withToken($token)->getJson('/api/v1/stock/users/u-admin')->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/stock/users/u-admin?version='.$admin->json('meta.version'))
        ->assertStatus(409)->assertJsonPath('error.code', 'last_admin');
    expect(Modules::db('stock')->selectOne("SELECT id FROM users WHERE id='u-admin'"))->not->toBeNull();
});

it('v1: ordering users use the same contract without PINs', function () {
    $token = loginAs(officeUser('u-wandi'));
    $created = $this->withToken($token)->postJson('/api/v1/stock/ordering-users', [
        'id' => 'u-v1-ordering', 'name' => 'Dapur V1', 'role' => 'checkin', 'keterangan' => 'Kitchen',
    ])->assertCreated();
    expect($created->json('data'))->not->toHaveKey('pin');
    expect(collect($this->withToken($token)->getJson('/api/v1/stock/ordering-users')->assertOk()->json('data'))
        ->pluck('id')->contains('u-v1-ordering'))->toBeTrue();
    $this->withToken($token)->deleteJson('/api/v1/stock/ordering-users/u-v1-ordering?version='.$created->json('meta.version'))->assertOk();
});

it('v1: settings degrade to defaults while stock_settings is missing and refuse writes', function () {
    $token = loginAs(officeUser('u-wandi'));
    $p = $this->withToken($token)->getJson('/api/v1/stock/settings/purchasing')->assertOk()
        ->assertJsonPath('data.perms', [])->assertJsonPath('data.templates', [])->assertJsonPath('meta.available', false);
    $o = $this->withToken($token)->getJson('/api/v1/stock/settings/ordering')->assertOk()->assertJsonPath('meta.available', false);

    $this->withToken($token)->putJson('/api/v1/stock/settings/purchasing', ['perms' => []])->assertStatus(428);
    $this->withToken($token)->putJson('/api/v1/stock/settings/purchasing?version='.$p->json('meta.version'), ['perms' => []])
        ->assertStatus(503)->assertJsonPath('error.code', 'settings_unavailable');
    $this->withToken($token)->putJson('/api/v1/stock/settings/ordering?version='.$o->json('meta.version'), ['perms' => []])
        ->assertStatus(503);
});

it('v1: training uploads, lists and streams an xlsx for the Ordering admin', function () {
    $token = loginAs(officeUser('u-wandi'));
    $this->withToken($token)->getJson('/api/v1/stock/training')->assertOk()->assertJsonPath('data.usage.files', 0);
    $this->withToken($token)->postJson('/api/v1/stock/training', ['target' => 'usage', 'fileName' => 'x.txt', 'contentB64' => 'QUJD'])
        ->assertStatus(422);
    $this->withToken($token)->postJson('/api/v1/stock/training', ['target' => 'usage', 'fileName' => 'x.xlsx', 'contentB64' => '!!'])
        ->assertStatus(422);

    $bytes = "PK\x03\x04training";
    $saved = $this->withToken($token)->postJson('/api/v1/stock/training', [
        'target' => 'usage', 'fileName' => 'forecast.xlsx', 'contentB64' => base64_encode($bytes),
    ])->assertCreated();
    $name = $saved->json('data.name');
    expect($name)->toMatch('~^\\d{8}-\\d{6}_forecast\\.xlsx$~');
    $this->withToken($token)->getJson('/api/v1/stock/training/usage')->assertOk()->assertJsonPath('meta.total', 1);
    $this->withToken($token)->get('/api/v1/stock/training/usage/'.rawurlencode($name))->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename=\"{$name}\"");
    expect(file_get_contents(stockAdminDir().'/usage/'.$name))->toBe($bytes);
});

it('v1: Pohon Resep reads recipes and ingredients but cannot write them', function () {
    $token = loginAs(officeUser('u-dinamariana'));
    $this->withToken($token)->getJson('/api/v1/stock/hpp/recipes')->assertOk();
    $this->withToken($token)->getJson('/api/v1/stock/hpp/ingredients')->assertOk();
    $this->withToken($token)->postJson('/api/v1/stock/hpp/recipes', [])->assertStatus(403);
});

it('v1: a user without the Modul is refused', function () {
    $token = loginAs(officeUser('u-adit'));
    $this->withToken($token)->getJson('/api/v1/stock/users')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/stock/training')->assertStatus(403);
});
