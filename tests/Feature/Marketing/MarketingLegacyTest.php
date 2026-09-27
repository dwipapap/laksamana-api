<?php

use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/*
 * Legacy /marketing-api-mysql/api.php — the saveAll guards that exist because
 * data was lost in production (laksamana-office CLAUDE.md, babak 1–7).
 * Full old-vs-new parity lives in tools/parity/cases/marketing.json.
 */

function mktSave(array $data): array
{
    return test()->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => $data])->assertOk()->json();
}

function mktClient(string $id): ?array
{
    $row = Modules::db('marketing')->selectOne(mktSql('SELECT updated_at, data FROM clients WHERE id = ?'), [$id]);

    return $row ? ['v' => (int) $row->updated_at, 'data' => json_decode($row->data, true)] : null;
}

it('keeps saveAll ok:true but refuses a stale edit into bentrok', function () {
    mktSave(['clients' => [['id' => 'c_t1', 'nama' => 'A', 'updatedAt' => 1000, 'createdAt' => 1000]]]);
    mktSave(['clients' => [['id' => 'c_t1', 'nama' => 'B', 'updatedAt' => 2000, 'baseUpdatedAt' => 1000]]]);

    $r = mktSave(['clients' => [['id' => 'c_t1', 'nama' => 'STALE', 'updatedAt' => 3000, 'baseUpdatedAt' => 1000]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['koleksi' => 'clients', 'id' => 'c_t1', 'versiServer' => 2000])
        ->and(mktClient('c_t1')['data']['nama'])->toBe('B');
});

it('does not report identical content as a conflict and returns the server version', function () {
    mktSave(['clients' => [['id' => 'c_t2', 'nama' => 'Same', 'x' => ['b' => 1, 'a' => 2], 'updatedAt' => 5000]]]);
    $r = mktSave(['clients' => [['x' => ['a' => 2, 'b' => 1], 'nama' => 'Same', 'id' => 'c_t2', 'updatedAt' => 9999, 'baseUpdatedAt' => 1]]]);

    expect($r['data']['bentrok'])->toBe([])
        ->and($r['data']['versi'])->toBe(['clients:c_t2' => 5000]);
});

it('bumps a legit edit whose stamp is not newer and reports it in versi', function () {
    mktSave(['clients' => [['id' => 'c_t3', 'nama' => 'A', 'updatedAt' => 7000]]]);
    $r = mktSave(['clients' => [['id' => 'c_t3', 'nama' => 'B', 'updatedAt' => 6000, 'baseUpdatedAt' => 7000]]]);

    expect($r['data']['versi'])->toBe(['clients:c_t3' => 7001])
        ->and(mktClient('c_t3'))->toMatchArray(['v' => 7001])
        ->and(mktClient('c_t3')['data']['updatedAt'])->toBe(7001);
});

it('never deletes when _sejak is missing, and only deletes rows the client could know about', function () {
    mktSave(['clients' => [
        ['id' => 'c_old', 'nama' => 'old', 'updatedAt' => 100],
        ['id' => 'c_new', 'nama' => 'new', 'updatedAt' => 900000000000000],
        ['id' => 'c_keep', 'nama' => 'keep', 'updatedAt' => 100],
    ]]);

    mktSave(['clients' => [['id' => 'c_keep', 'nama' => 'keep', 'updatedAt' => 100]]]);           // no _sejak
    expect(mktClient('c_old'))->not->toBeNull();

    mktSave(['clients' => [['id' => 'c_keep', 'nama' => 'keep', 'updatedAt' => 100]], '_sejak' => 500]);
    expect(mktClient('c_old'))->toBeNull()                 // known (<= _sejak) and missing => deleted
        ->and(mktClient('c_new'))->not->toBeNull()         // born after the client loaded => protected
        ->and(mktClient('c_keep'))->not->toBeNull();
});

it('merges Reservasi VIP per row instead of overwriting the whole list', function () {
    $before = count(mktVipList());
    mktSave(['vip' => [['id' => 'vip_t1', 'nama' => 'New VIP', 'updatedAt' => 1]]]);

    $after = mktVipList();
    expect($after)->toHaveCount($before + 1);
});

it('answers eventsHari without a session (called by finance)', function () {
    $this->get('/marketing-api-mysql/api.php?action=eventsHari&tgl=2026-09-20')
        ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['events', 'vip', 'settings' => ['serviceCharge', 'pb1']]]);
});

it('reports the library version CI checks in ping', function () {
    $this->get('/marketing-api-mysql/api.php?action=ping')->assertJsonPath('data.versi', '2026-08-11');
});
