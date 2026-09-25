<?php

use App\Support\Modules;

/*
 * Legacy /akademi-api-mysql/api.php — the whole-state saveAll on RowSync
 * (notIn delete + baseUpdatedAt), the composite-key progress maps, the
 * sha1-derived activity ids, the receipt store, and trainingStats.
 * Full old-vs-new parity lives in tools/parity/cases/akademi.json.
 */

beforeEach(function () {
    config(['laksamana.modules.akademi.data_dir' => storage_path('framework/testing/akademi-db')]);
});

function akSave(array $data): array
{
    return test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => $data])->assertOk()->json();
}

function akDivision(string $id): ?array
{
    $row = Modules::db('akademi')->selectOne('SELECT updated_at, data FROM divisions WHERE id = ?', [$id]);

    return $row ? ['v' => (int) $row->updated_at, 'data' => json_decode($row->data, true)] : null;
}

it('keeps saveAll ok:true but refuses a stale edit into bentrok', function () {
    akSave(['divisions' => [['id' => 'd_t1', 'name' => 'A', 'updatedAt' => 1000]]]);
    akSave(['divisions' => [['id' => 'd_t1', 'name' => 'B', 'updatedAt' => 2000, 'baseUpdatedAt' => 1000]]]);

    $r = akSave(['divisions' => [['id' => 'd_t1', 'name' => 'STALE', 'updatedAt' => 3000, 'baseUpdatedAt' => 1000]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['koleksi' => 'divisions', 'id' => 'd_t1', 'versiServer' => 2000])
        ->and(akDivision('d_t1')['data']['name'])->toBe('B');
});

it('bumps the column above the server for a legit edit but keeps data.updatedAt as sent', function () {
    akSave(['divisions' => [['id' => 'd_t2', 'name' => 'A', 'updatedAt' => 7000]]]);
    $r = akSave(['divisions' => [['id' => 'd_t2', 'name' => 'B', 'updatedAt' => 6000, 'baseUpdatedAt' => 7000]]]);

    expect($r['data']['bentrok'])->toBe([])
        ->and($r['data'])->not->toHaveKey('versi')
        ->and(akDivision('d_t2'))->toMatchArray(['v' => 7001])
        ->and(akDivision('d_t2')['data']['updatedAt'])->toBe(6000);
});

it('reproduces the legacy unbounded delete: missing rows die even when newer than the client', function () {
    akSave(['divisions' => [
        ['id' => 'd_old', 'name' => 'old', 'updatedAt' => 100],
        ['id' => 'd_new', 'name' => 'new', 'updatedAt' => 900000000000000],
        ['id' => 'd_keep', 'name' => 'keep', 'updatedAt' => 100],
    ]]);

    // A client that loaded before d_new existed sends only what it knows.
    akSave(['divisions' => [['id' => 'd_keep', 'name' => 'keep', 'updatedAt' => 100]]]);

    expect(akDivision('d_old'))->toBeNull()
        ->and(akDivision('d_new'))->toBeNull() // no _sejak bound: born after the client loaded, still deleted
        ->and(akDivision('d_keep'))->not->toBeNull();
});

it('never deletes on an empty list, and skips collections missing from the payload', function () {
    akSave(['divisions' => [['id' => 'd_t3', 'name' => 'stay', 'updatedAt' => 100]]]);

    akSave(['divisions' => []]);
    expect(akDivision('d_t3'))->not->toBeNull();

    akSave(['materials' => []]);
    expect(akDivision('d_t3'))->not->toBeNull();
});

it('round-trips progress maps to composite-key rows and never deletes on an empty map', function () {
    akSave(['progress' => ['u-adit' => ['m_bar_basic' => ['status' => 'done', 'completedAt' => 1790100000000]]]]);

    $row = Modules::db('akademi')->selectOne(
        'SELECT done, at_ms, updated_at, data FROM progress WHERE user_id = ? AND material_id = ?',
        ['u-adit', 'm_bar_basic']);
    expect($row)->not->toBeNull()
        ->and(json_decode($row->data, true))->toMatchArray(['status' => 'done']);

    $get = test()->get('/akademi-api-mysql/api.php?action=getAll')->assertOk()->json();
    expect($get['data']['progress']['u-adit']['m_bar_basic'])->toMatchArray(['status' => 'done']);

    akSave(['progress' => []]);
    expect(Modules::db('akademi')->selectOne(
        'SELECT 1 c FROM progress WHERE user_id = ? AND material_id = ?', ['u-adit', 'm_bar_basic']))->not->toBeNull();
});

it('round-trips 3-level progProg maps', function () {
    akSave(['progProg' => ['u-adit' => ['p_x' => ['m_bar_basic' => ['done' => true, 'at' => 1790100000000]]]]]);

    $get = test()->get('/akademi-api-mysql/api.php?action=getAll')->assertOk()->json();
    expect($get['data']['progProg']['u-adit']['p_x']['m_bar_basic'])->toMatchArray(['done' => true]);
});

it('appends activity with sha1-derived ids and never doubles a resend', function () {
    $before = (int) Modules::db('akademi')->selectOne('SELECT COUNT(*) c FROM activity')->c;
    $r = akSave(['activity' => [
        ['ts' => 1790100000000, 'userId' => 'u-adit', 'action' => 'uji', 'detail' => 'coba'],
        ['noid' => true],
    ]]);
    // Every array row is attempted (there is no id to skip on, unlike
    // konten logs), so jumlah counts both; the fingerprint-less row lands
    // on the empty fingerprint id.
    expect($r['data']['jumlah']['activity'])->toBe(2);
    expect((int) Modules::db('akademi')->selectOne('SELECT COUNT(*) c FROM activity')->c)->toBe($before + 2);

    $id = 'ac_'.substr(sha1('1790100000000|u-adit|uji|coba'), 0, 24);
    expect(Modules::db('akademi')->selectOne('SELECT id FROM activity WHERE id = ?', [$id]))->not->toBeNull();

    // Resending the identical list changes nothing (INSERT IGNORE on the
    // content-derived id); a changed detail is a genuinely new row.
    akSave(['activity' => [['ts' => 1790100000000, 'userId' => 'u-adit', 'action' => 'uji', 'detail' => 'coba']]]);
    $row = Modules::db('akademi')->selectOne('SELECT data FROM activity WHERE id = ?', [$id]);
    expect(json_decode($row->data, true)['detail'])->toBe('coba');
    expect((int) Modules::db('akademi')->selectOne('SELECT COUNT(*) c FROM activity')->c)->toBe($before + 2);
});

it('stores settings plus unknown keys, and keeps the safety nets', function () {
    akSave(['version' => 2, 'fiturBaru' => ['a' => 1], '_rev' => 9]);

    $get = test()->get('/akademi-api-mysql/api.php?action=getAll')->assertOk()->json();
    expect($get['data']['fiturBaru'])->toBe(['a' => 1])
        ->and($get['data'])->not->toHaveKey('_rev')
        ->and($get['data']['settings'])->toBeArray()
        ->and($get['data']['version'])->toBe(2);
});

it('rejects an invalid payload and unknown actions like legacy', function () {
    test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll'])
        ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error', 'Payload data kosong/invalid');

    test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'nope'])
        ->assertOk()->assertJsonPath('error', 'Aksi tidak dikenal: nope');
});

it('answers ping and stats with the legacy shape', function () {
    $this->get('/akademi-api-mysql/api.php?action=ping')->assertOk()
        ->assertJsonPath('ok', true)->assertJsonPath('data.pong', true)->assertJsonPath('data.backend', 'laravel');

    $n = (int) Modules::db('akademi')->selectOne('SELECT COUNT(*) c FROM materials')->c;
    $this->get('/akademi-api-mysql/api.php?action=stats')->assertOk()
        ->assertJsonPath('ok', true)->assertJsonPath('data.materials', $n)->assertJsonPath('data.backend', 'laravel');
});

it('serves trainingStats keyed by user with the client userStats shape', function () {
    $r = $this->get('/akademi-api-mysql/api.php?action=trainingStats')->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['data'])->toBeArray();
    $first = reset($r['data']);
    expect($first)->toHaveKeys(['mandPct', 'mandTotal', 'mandDone', 'total', 'done', 'certified']);
});

it('validates receipt uploads like legacy', function () {
    test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'uploadReceipt'])
        ->assertOk()->assertJsonPath('error', 'file kosong');

    test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'uploadReceipt', 'dataBase64' => '@@@', 'mimeType' => 'image/png'])
        ->assertOk()->assertJsonPath('error', 'base64 tidak valid');

    $big = str_repeat('A', 11 * 1024 * 1024); // decodes to > 8 MB
    test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'uploadReceipt', 'dataBase64' => $big, 'mimeType' => 'image/png'])
        ->assertOk()->assertJsonPath('error', 'file melebihi 8MB');
});

it('stores a receipt and streams it back', function () {
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $out = test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'uploadReceipt',
        'dataBase64' => 'data:image/png;base64,'.$png, 'mimeType' => 'image/png', 'fileName' => 'dot.png'])
        ->assertOk()->json();

    expect($out['data']['key'])->toStartWith('rc_')->toEndWith('.png')
        ->and($out['data']['name'])->toBe('dot.png');

    test()->get('/akademi-api-mysql/api.php?action=receipt&key='.$out['data']['key'])
        ->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('answers 400 for a bad receipt key and 404 when missing', function () {
    test()->get('/akademi-api-mysql/api.php?action=receipt&key=../x')->assertStatus(400);
    test()->get('/akademi-api-mysql/api.php?action=receipt&key=rc_tidak_ada.png')->assertStatus(404);
    test()->get('/akademi-api-mysql/api.php?action=receipt')->assertStatus(400);
});
