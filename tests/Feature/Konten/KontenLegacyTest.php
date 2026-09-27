<?php

use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/*
 * Legacy /konten-api-mysql/api.php — the whole-state saveAll on RowSync,
 * the receipt store, and the legacy unbounded delete (kept exactly).
 * Full old-vs-new parity lives in tools/parity/cases/konten.json.
 */

beforeEach(function () {
    config(['laksamana.modules.konten.data_dir' => storage_path('framework/testing/konten-db')]);
});

function ktSave(array $data): array
{
    return test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => $data])->assertOk()->json();
}

function ktBrand(string $id): ?array
{
    $row = Modules::db('konten')->selectOne(ktSql('SELECT updated_at, data FROM brands WHERE id = ?'), [$id]);

    return $row ? ['v' => (int) $row->updated_at, 'data' => json_decode($row->data, true)] : null;
}

it('keeps saveAll ok:true but refuses a stale edit into bentrok', function () {
    ktSave(['brands' => [['id' => 'b_t1', 'name' => 'A', 'updatedAt' => 1000]]]);
    ktSave(['brands' => [['id' => 'b_t1', 'name' => 'B', 'updatedAt' => 2000, 'baseUpdatedAt' => 1000]]]);

    $r = ktSave(['brands' => [['id' => 'b_t1', 'name' => 'STALE', 'updatedAt' => 3000, 'baseUpdatedAt' => 1000]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['koleksi' => 'brands', 'id' => 'b_t1', 'versiServer' => 2000])
        ->and(ktBrand('b_t1')['data']['name'])->toBe('B');
});

it('bumps the column above the server for a legit edit but keeps data.updatedAt as sent', function () {
    ktSave(['brands' => [['id' => 'b_t2', 'name' => 'A', 'updatedAt' => 7000]]]);
    $r = ktSave(['brands' => [['id' => 'b_t2', 'name' => 'B', 'updatedAt' => 6000, 'baseUpdatedAt' => 7000]]]);

    // The older marketing-style `versi` map does not exist here.
    expect($r['data']['bentrok'])->toBe([])
        ->and($r['data'])->not->toHaveKey('versi')
        ->and(ktBrand('b_t2'))->toMatchArray(['v' => 7001])
        ->and(ktBrand('b_t2')['data']['updatedAt'])->toBe(6000);
});

it('reproduces the legacy unbounded delete: missing rows die even when newer than the client', function () {
    ktSave(['brands' => [
        ['id' => 'b_old', 'name' => 'old', 'updatedAt' => 100],
        ['id' => 'b_new', 'name' => 'new', 'updatedAt' => 900000000000000],
        ['id' => 'b_keep', 'name' => 'keep', 'updatedAt' => 100],
    ]]);

    // A client that loaded before b_new existed sends only what it knows.
    ktSave(['brands' => [['id' => 'b_keep', 'name' => 'keep', 'updatedAt' => 100]]]);

    expect(ktBrand('b_old'))->toBeNull()
        ->and(ktBrand('b_new'))->toBeNull() // no _sejak bound: born after the client loaded, still deleted
        ->and(ktBrand('b_keep'))->not->toBeNull();
});

it('never deletes on an empty list, and skips collections missing from the payload', function () {
    ktSave(['brands' => [['id' => 'b_t3', 'name' => 'stay', 'updatedAt' => 100]]]);

    ktSave(['brands' => []]);
    expect(ktBrand('b_t3'))->not->toBeNull();

    ktSave(['content' => []]);
    expect(ktBrand('b_t3'))->not->toBeNull();
});

it('appends logs without touching existing rows and stores settings plus unknown keys', function () {
    $before = (int) Modules::db('konten')->selectOne(ktSql('SELECT COUNT(*) c FROM logs'))->c;
    $r = ktSave([
        'logs' => [
            ['id' => 'lg_test1', 'by' => 'u-andry', 'action' => 'uji coba', 'target' => 'x', 'at' => 1790100000000],
            ['noid' => true],
        ],
        'perms' => ['dashboard' => ['admin' => 2]],
        'fiturBaru' => ['a' => 1],
        '_sejak' => 123, // konten ignores it — and stores it as an extra key, like legacy
    ]);

    expect($r['data']['jumlah']['logs'])->toBe(1);
    $count = (int) Modules::db('konten')->selectOne(ktSql('SELECT COUNT(*) c FROM logs'))->c;
    expect($count)->toBe($before + 1);

    // Re-saving the same log id changes nothing (INSERT IGNORE).
    ktSave(['logs' => [['id' => 'lg_test1', 'by' => 'u-andry', 'action' => 'diubah?', 'target' => 'x', 'at' => 1790100000000]]]);
    $row = Modules::db('konten')->selectOne(ktSql("SELECT data FROM logs WHERE id = 'lg_test1'"));
    expect(json_decode($row->data, true)['action'])->toBe('uji coba');

    $get = test()->get('/konten-api-mysql/api.php?action=getAll')->assertOk()->json();
    expect($get['data']['perms'])->toBe(['dashboard' => ['admin' => 2]])
        ->and($get['data']['fiturBaru'])->toBe(['a' => 1])
        ->and($get['data']['_sejak'])->toBe(123);
});

it('rejects an invalid payload and unknown actions like legacy', function () {
    test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll'])
        ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error', 'Payload data kosong/invalid');

    test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'nope'])
        ->assertOk()->assertJsonPath('error', 'Aksi tidak dikenal: nope');
});

it('answers ping and stats with the legacy shape', function () {
    $this->get('/konten-api-mysql/api.php?action=ping')->assertOk()
        ->assertJsonPath('ok', true)->assertJsonPath('data.pong', true)->assertJsonPath('data.backend', 'laravel');

    $n = (int) Modules::db('konten')->selectOne(ktSql('SELECT COUNT(*) c FROM content'))->c;
    $this->get('/konten-api-mysql/api.php?action=stats')->assertOk()
        ->assertJsonPath('ok', true)->assertJsonPath('data.content', $n)->assertJsonPath('data.backend', 'laravel');
});

it('validates receipt uploads like legacy', function () {
    test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'uploadReceipt'])
        ->assertOk()->assertJsonPath('error', 'file kosong');

    test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'uploadReceipt', 'dataBase64' => '@@@', 'mimeType' => 'image/png'])
        ->assertOk()->assertJsonPath('error', 'base64 tidak valid');

    $big = str_repeat('A', 11 * 1024 * 1024); // decodes to > 8 MB
    test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'uploadReceipt', 'dataBase64' => $big, 'mimeType' => 'image/png'])
        ->assertOk()->assertJsonPath('error', 'file melebihi 8MB');
});

it('stores a receipt and streams it back', function () {
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $out = test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'uploadReceipt',
        'dataBase64' => 'data:image/png;base64,'.$png, 'mimeType' => 'image/png', 'fileName' => 'dot.png'])
        ->assertOk()->json();

    expect($out['data']['key'])->toStartWith('rc_')->toEndWith('.png')
        ->and($out['data']['name'])->toBe('dot.png');

    test()->get('/konten-api-mysql/api.php?action=receipt&key='.$out['data']['key'])
        ->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('answers 400 for a bad receipt key and 404 when missing', function () {
    test()->get('/konten-api-mysql/api.php?action=receipt&key=../x')->assertStatus(400);
    test()->get('/konten-api-mysql/api.php?action=receipt&key=rc_tidak_ada.png')->assertStatus(404);
    test()->get('/konten-api-mysql/api.php?action=receipt')->assertStatus(400);
});
