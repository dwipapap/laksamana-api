<?php

/* #282: slim list projection + pillar/arsip/sort/facets for the Planning table. */

beforeEach(function () {
    config(['laksamana.modules.konten.data_dir' => storage_path('framework/testing/konten-db')]);
});

function slimToken(): string
{
    return loginAs(officeUser('u-andry'));
}

function slimPost(array $body): array
{
    return test()->withToken(slimToken())->postJson('/api/v1/konten/content', $body)->assertCreated()->json('data');
}

it('returns slim rows without photo blobs on ringkas=1', function () {
    $row = slimPost(['title' => 'Slim Uji '.Str::random(6), 'status' => 'Idea',
        'refs' => [['t' => 'img', 'v' => str_repeat('A', 5000)]],
        'detail' => 'detail panjang', 'perf' => ['IG' => ['views' => 3]]]);
    $list = test()->withToken(slimToken())->getJson('/api/v1/konten/content?ringkas=1&q='.urlencode($row['title']))
        ->assertOk()->json('data');
    expect($list)->toHaveCount(1);
    expect($list[0])->not->toHaveKey('refs')->not->toHaveKey('detail')->not->toHaveKey('perf')
        ->and($list[0]['title'])->toBe($row['title'])->and($list[0]['id'])->toBe($row['id']);
    $full = test()->withToken(slimToken())->getJson('/api/v1/konten/content/'.$row['id'])->assertOk()->json('data');
    expect($full)->toHaveKey('refs');
});

it('filters arsip with the F.2 status override', function () {
    $posted = slimPost(['title' => 'Slim Posted '.Str::random(6), 'status' => 'Posted']);
    $idea = slimPost(['title' => 'Slim Idea '.Str::random(6), 'status' => 'Idea']);
    $t = slimToken();
    $aktif = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&arsip=aktif&q=Slim')->assertOk()->json('data');
    expect(collect($aktif)->pluck('id'))->not->toContain($posted['id']);
    $arsip = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&arsip=arsip&q=Slim')->assertOk()->json('data');
    expect(collect($arsip)->pluck('id'))->toContain($posted['id'])->not->toContain($idea['id']);
    // F.2: status=Posted wins over arsip=aktif.
    $ov = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&arsip=aktif&status=Posted&q=Slim')->assertOk()->json('data');
    expect(collect($ov)->pluck('id'))->toContain($posted['id']);
});

it('filters pillar and matches any platform (F.27)', function () {
    $row = slimPost(['title' => 'Slim Pilar '.Str::random(6), 'status' => 'Idea',
        'pillar' => 'Entertainment', 'platform' => 'IG', 'platforms' => ['IG', 'TT']]);
    $t = slimToken();
    $hit = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&pillar=Entertainment&q='.urlencode($row['title']))
        ->assertOk()->json('data');
    expect($hit)->toHaveCount(1);
    $tt = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&platform=TT&q='.urlencode($row['title']))
        ->assertOk()->json('data');
    expect($tt)->toHaveCount(1);
});

it('sorts by deadline, undated last', function () {
    $a = slimPost(['title' => 'Slim Sort A '.Str::random(6), 'status' => 'Idea', 'deadline' => '2026-12-31']);
    $b = slimPost(['title' => 'Slim Sort B '.Str::random(6), 'status' => 'Idea', 'deadline' => '2026-01-01']);
    $c = slimPost(['title' => 'Slim Sort C '.Str::random(6), 'status' => 'Idea']);
    $t = slimToken();
    $ids = collect(test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&sort=deadline&q=Slim+Sort')->assertOk()->json('data'))
        ->pluck('id')->all();
    expect(array_search($b['id'], $ids))->toBeLessThan(array_search($a['id'], $ids));
    expect(array_search($c['id'], $ids))->toBeGreaterThan(array_search($a['id'], $ids));
});

it('counts PIC facets ignoring the pic filter (F.13)', function () {
    $t = slimToken();
    $res = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&facets=pic&perPage=1&q=Slim')->assertOk();
    $facets = $res->json('meta.facets');
    expect($facets)->toBeArray()->and($facets[''])->toBe($res->json('meta.total'));
    $perPic = 0;
    foreach ($facets as $k => $n) {
        if ($k !== '') {
            $perPic += $n;
        }
    }
    expect($perPic)->toBe($facets['']);
});

it('searches title and detailing, not photo noise', function () {
    $row = slimPost(['title' => 'Slim Cari '.Str::random(6), 'status' => 'Idea',
        'detail' => 'kata-kunci-unik-'.Str::random(6), 'refs' => [['t' => 'img', 'v' => 'QUJDREVGR0g=']]]);
    $t = slimToken();
    expect(test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&q='.urlencode($row['title']))->assertOk()->json('data'))
        ->toHaveCount(1);
    $noise = 'QUJDREVGR0g';
    $hit = test()->withToken($t)->getJson('/api/v1/konten/content?ringkas=1&q='.$noise)->assertOk()->json('data');
    expect(collect($hit)->pluck('id'))->not->toContain($row['id']);
});
