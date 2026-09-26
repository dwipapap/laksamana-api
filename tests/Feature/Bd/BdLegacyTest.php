<?php

require_once __DIR__.'/helpers.php';

use App\Support\Modules;
use Illuminate\Testing\TestResponse;

function bdPost(array $body): TestResponse
{
    return test()->call('POST', '/bd-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

it('serves getAll by default with _serverTs and the settings documents', function () {
    $d = $this->get('/bd-api-mysql/api.php')->assertOk()->assertJsonPath('ok', true)->json('data');
    expect($d)->toHaveKeys(['people', 'projects', 'tasks', 'routines', 'coord', 'po', 'pr', 'agenda', 'focus', 'approverSets', 'promos', '_serverTs'])
        ->and(count($d['po']))->toBe((int) Modules::db('bd')->selectOne(bdSql('SELECT COUNT(*) c FROM purchase_orders'))->c);
});

it('extracts indexed columns like legacy ambil()', function () {
    $db = Modules::db('bd');
    $newer = (int) $db->selectOne(bdSql('SELECT COUNT(*) c FROM tasks WHERE updated_at > 10'))->c;

    bdPost(['action' => 'saveAll', 'sinceTs' => 0, 'data' => ['tasks' => [
        ['id' => 't-x1', 'name' => 'X', 'pics' => ['a', 'b'], 'deadline' => '', 'important' => 'yes', 'progress' => '42abc', 'updatedAt' => 10, 'createdAt' => 5],
        ['id' => 't-x2', 'pic' => 'solo', 'deadline' => '2026-01-02', 'updatedAt' => 10],
    ]]])->assertOk()->assertJsonPath('data.jumlah.tasks', 2);

    expect($db->selectOne(bdSql("SELECT pic, deadline, important, progress, created_at FROM tasks WHERE id='t-x1'")))
        ->pic->toBe('a')->deadline->toBeNull()->important->toBe(1)->progress->toBe(42)->created_at->toBe(5)
        ->and($db->selectOne(bdSql("SELECT pic, deadline FROM tasks WHERE id='t-x2'")))->pic->toBe('solo')->deadline->toBe('2026-01-02');
    // sinceTs 0 => the bound is the payload's max updatedAt (10): rows newer than that survive, older ones go
    expect((int) $db->selectOne(bdSql('SELECT COUNT(*) c FROM tasks'))->c)->toBe(2 + $newer);
});

it('never lets an older stamp overwrite a newer row', function () {
    $db = Modules::db('bd');
    $row = $db->selectOne(bdSql('SELECT id, updated_at, data FROM tasks ORDER BY updated_at DESC LIMIT 1'));
    bdPost(['action' => 'saveAll', 'data' => ['tasks' => [['id' => $row->id, 'name' => 'OLD', 'updatedAt' => 1]]], 'sinceTs' => 1])->assertOk();
    expect($db->selectOne(bdSql('SELECT data FROM tasks WHERE id = ?'), [$row->id])->data)->toBe($row->data);
});

it('adds POs insert-only with generated ids and default status', function () {
    bdPost(['action' => 'addPo', 'items' => [['id' => 'mine', 'item' => 'Kertas'], ['vendor' => 'no item']]])
        ->assertOk()->assertJsonPath('data.added', 1)->assertJsonMissingPath('data.ids');
    $row = Modules::db('bd')->selectOne(bdSql("SELECT id, status FROM purchase_orders WHERE item='Kertas'"));
    expect($row->id)->toMatch('/^po[0-9a-f]{10}$/')->and($row->status)->toBe('Diajukan');

    bdPost(['action' => 'addPo', 'items' => []])->assertJsonPath('error', 'Tidak ada baris untuk ditambahkan');
});

it('sets and clears a realisation, keeping the previous status', function () {
    bdPost(['action' => 'setRealisasi', 'id' => 'ponv3hxrv', 'realisasi' => 'Rp 12.500', 'oleh' => 'Kasir'])
        ->assertOk()->assertJsonPath('data.realisasi', 12500)->assertJsonPath('data.status', 'Diterima')->assertJsonPath('data.proses', true);
    $d = json_decode(Modules::db('bd')->selectOne(bdSql("SELECT data FROM purchase_orders WHERE id='ponv3hxrv'"))->data, true);
    expect($d['statusSebelum'])->toBe('Diajukan')->and($d['prosesBy'])->toBe('Kasir');

    bdPost(['action' => 'setRealisasi', 'id' => 'ponv3hxrv', 'realisasi' => ''])->assertJsonPath('data.status', 'Diajukan');
    $row = Modules::db('bd')->selectOne(bdSql("SELECT status, data FROM purchase_orders WHERE id='ponv3hxrv'"));
    expect($row->status)->toBe('Diajukan')->and(json_decode($row->data, true))->not->toHaveKeys(['realisasi', 'proses', 'statusSebelum']);

    bdPost(['action' => 'setRealisasi', 'id' => 'zzz', 'realisasi' => 1])->assertJsonPath('error', 'PO tidak ditemukan: zzz');
});

it('keeps the server sql_mode like the legacy PDO', function () {
    expect(config('database.connections.legacy_bd.strict'))->toBeNull();
});
