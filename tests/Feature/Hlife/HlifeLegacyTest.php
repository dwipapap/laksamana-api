<?php

use App\Support\Modules;
use Illuminate\Testing\TestResponse;

const HL_TOKEN = 'HL-5mHh8Lfu8bpiPMkgtRphSmvM';

function hlPost(mixed $body, string $query = ''): TestResponse
{
    return test()->call('POST', '/howandi-life-api-mysql/api.php'.$query, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=utf-8'],
        is_string($body) ? $body : json_encode($body));
}

it('answers ping without a token', function () {
    $this->get('/howandi-life-api-mysql/api.php?action=ping')
        ->assertOk()->assertJsonPath('ok', true)->assertHeader('Cache-Control', 'no-store, private');
});

it('rejects a missing or wrong token with 403', function () {
    $this->get('/howandi-life-api-mysql/api.php?action=getAll')->assertStatus(403)->assertExactJson(['ok' => false, 'error' => 'token salah']);
    hlPost(['action' => 'saveAll', 'token' => 'nope', 'data' => ['tasks' => []]])->assertStatus(403);
    // ?token= wins over body.token, like `$_GET['token'] ?? $body->token`
    hlPost(['action' => 'saveAll', 'token' => HL_TOKEN, 'data' => []], '?token=nope')->assertStatus(403);
});

it('returns the full state with settings defaults filled in', function () {
    Modules::db('hlife')->delete("DELETE FROM settings WHERE k IN ('mood','auth')");
    $d = $this->get('/howandi-life-api-mysql/api.php?action=getAll&token='.HL_TOKEN)->assertOk()->json('data');

    expect($d['mood'])->toBe(3)->and($d['auth'])->toBe(['enabled' => false, 'hash' => ''])
        ->and($d['finance'])->toHaveKey('ledger')
        ->and(count($d['tasks']))->toBe((int) Modules::db('hlife')->selectOne('SELECT COUNT(*) c FROM tasks')->c);
});

it('uses real HTTP status codes for errors', function () {
    $this->get('/howandi-life-api-mysql/api.php?token='.HL_TOKEN)->assertStatus(400)->assertJsonPath('error', 'action tidak dikenal');
    hlPost('not json')->assertStatus(400)->assertJsonPath('error', 'body bukan JSON');
    hlPost(['action' => 'saveAll', 'token' => HL_TOKEN, 'data' => ['mood' => 1]])->assertStatus(400)->assertJsonPath('error', 'payload_rusak');
    $this->put('/howandi-life-api-mysql/api.php')->assertStatus(405)->assertJsonPath('error', 'metode tidak didukung');
});

it('saves the whole state: upsert, delete-not-in, empty objects kept, missing collections emptied', function () {
    $res = hlPost(['action' => 'saveAll', 'token' => HL_TOKEN, 'data' => json_decode('{
        "tasks": [{"id":"t-new","name":"Baru","done":true,"meta":{}}],
        "dreams": [{"id":"d1","title":"Mimpi","year":null}],
        "finance": {"ledger":[{"id":"l1","month":"2026-09","scope":"personal","income":5,"expense":2}]},
        "mood": 5, "notASetting": 1
    }')]);
    $res->assertOk()->assertExactJson(['ok' => true, 'data' => ['saved' => true]]);

    $db = Modules::db('hlife');
    expect($db->select('SELECT id, done, data FROM tasks'))->toHaveCount(1)
        ->and($db->selectOne('SELECT data FROM tasks')->data)->toBe('{"id":"t-new","name":"Baru","done":true,"meta":{}}')
        ->and((int) $db->selectOne('SELECT done FROM tasks')->done)->toBe(1)
        ->and($db->selectOne('SELECT tahun FROM dreams')->tahun)->toBeNull()
        ->and((int) $db->selectOne('SELECT COUNT(*) c FROM projects')->c)->toBe(0)
        ->and($db->selectOne("SELECT v FROM settings WHERE k='mood'")->v)->toBe('5')
        ->and($db->selectOne("SELECT v FROM settings WHERE k='notASetting'"))->toBeNull();
});

it('leaves sql_mode to the server, so a non-strict production stores dreams year "" as 0 like legacy', function () {
    expect(config('database.connections.legacy_hlife.strict'))->toBeNull();

    $db = Modules::db('hlife');
    $db->statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'"); // production-like
    hlPost(['action' => 'saveAll', 'token' => HL_TOKEN, 'data' => ['tasks' => [],
        'dreams' => [['id' => 'd-empty-year', 'title' => 'X', 'year' => '']]]])->assertOk();
    expect((int) $db->selectOne("SELECT tahun FROM dreams WHERE id='d-empty-year'")->tahun)->toBe(0);
});
