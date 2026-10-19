<?php

use App\Modules\Konten\Services\CampaignAnalytics;
use App\Support\Modules;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/* u-andry holds module `konten`; u-adit holds neither konten nor campaign. */

beforeEach(function () {
    config(['laksamana.modules.konten.data_dir' => storage_path('framework/testing/konten-db')]);
    app(CampaignAnalytics::class)->ensureTables();
    putenv('META_MODEL_API_KEY=');
    putenv('TIKTOK_CLIENT_KEY=');
    putenv('TIKTOK_CLIENT_SECRET=');
    putenv('INSTAGRAM_APP_ID=');
    putenv('INSTAGRAM_APP_SECRET=');
});

function cgDb()
{
    return Modules::db('konten');
}

function cgClearSettings(): void
{
    cgDb()->delete("DELETE FROM cg_setting WHERE k IN ('ai_key_meta','tiktok_key','tiktok_secret','ig_id','ig_secret')");
}

function cgToken(): string
{
    return loginAs(officeUser('u-andry'));
}

function cgVersionOf(string $table, string $id): int
{
    $row = cgDb()->selectOne("SELECT dibuat_at, diubah_at FROM {$table} WHERE id = ?", [$id]);

    return (int) ($row->diubah_at ?? $row->dibuat_at ?? 0);
}

it('requires login and the konten or campaign module', function () {
    $this->getJson('/api/v1/konten/analytics/state')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/konten/analytics/state')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('reads the full state without secrets', function () {
    $res = $this->withToken(cgToken())->getJson('/api/v1/konten/analytics/state')->assertOk();
    foreach (['insight', 'jadwal', 'riset', 'bank', 'postInfo', 'akun', 'setting', 'boleh'] as $key) {
        expect($res->json("data.{$key}"))->not->toBeNull();
    }
    foreach ($res->json('data.akun') as $akun) {
        expect($akun)->not->toHaveKey('access_token')->not->toHaveKey('refresh_token');
    }
    expect($res->json('data.setting.tiktokScope'))->toBe(CampaignAnalytics::TIKTOK_SCOPE);
});

it('validates insight writes exactly like legacy', function () {
    $t = cgToken();
    $this->withToken($t)->postJson('/api/v1/konten/analytics/insights', ['temuan' => ''])
        ->assertStatus(422)->assertJsonPath('error.message', 'Temuan wajib diisi — itulah inti insight-nya.');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/insights', ['temuan' => 'x', 'url' => 'http://a'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tautan konten harus diawali https:// .');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/insights', ['temuan' => 'x', 'url' => ''])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tautan konten wajib diisi, supaya insight bisa ditelusuri ke konten aslinya.');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/insights',
        ['temuan' => 'x', 'url' => 'https://contoh.id/a', 'tglTayang' => '2026-13-99'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tanggal konten tayang wajib diisi.');
});

it('creates an insight and guards it with versions', function () {
    $t = cgToken();
    $body = ['platform' => 'TikTok', 'kategori' => 'Hook 3 Detik & Retensi', 'temuan' => 'Hook menang',
        'url' => 'https://contoh.id/a', 'tglTayang' => '2026-10-01', 'metrik' => 'ER 9%', 'aksi' => 'Ulangi'];
    $id = $this->withToken($t)->postJson('/api/v1/konten/analytics/insights', $body)
        ->assertOk()->json('data.id');
    expect($id)->toStartWith('ins');
    $v = cgVersionOf('cg_insight', $id);
    expect($v)->toBeGreaterThan(0);

    $this->withToken($t)->patchJson("/api/v1/konten/analytics/insights/{$id}", ['temuan' => 'x'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');

    $this->withToken($t)->withHeader('If-Match', '"1"')->patchJson("/api/v1/konten/analytics/insights/{$id}", ['temuan' => 'y'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current.id', $id);

    $this->withToken($t)->withHeader('If-Match', '"'.$v.'"')->patchJson("/api/v1/konten/analytics/insights/{$id}", $body)
        ->assertOk()->assertJsonPath('data.id', $id);
    expect(cgVersionOf('cg_insight', $id))->toBe($v);

    $this->withToken($t)->withHeader('If-Match', '"'.$v.'"')->patchJson("/api/v1/konten/analytics/insights/{$id}",
        ['temuan' => 'Hook menang v2'])
        ->assertOk();
    expect(cgVersionOf('cg_insight', $id))->toBeGreaterThan($v);
});

it('archives and restores an insight', function () {
    $t = cgToken();
    $id = $this->withToken($t)->postJson('/api/v1/konten/analytics/insights',
        ['temuan' => 'Arsipkan saya', 'url' => 'https://contoh.id/b', 'tglTayang' => '2026-10-02'])
        ->assertOk()->json('data.id');
    $this->withToken($t)->postJson("/api/v1/konten/analytics/insights/{$id}/arsip", ['arsip' => true])->assertOk();
    expect((int) cgDb()->selectOne('SELECT arsip FROM cg_insight WHERE id = ?', [$id])->arsip)->toBe(1);
    $this->withToken($t)->postJson("/api/v1/konten/analytics/insights/{$id}/arsip", ['arsip' => false])->assertOk();
});

it('validates schedules exactly like legacy', function () {
    $t = cgToken();
    $this->withToken($t)->postJson('/api/v1/konten/analytics/schedules', ['judul' => '', 'tanggal' => '2026-10-05'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Judul konten wajib diisi.');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/schedules', ['judul' => 'x', 'tanggal' => 'Besok'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tanggal tayang wajib diisi.');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/schedules',
        ['judul' => 'x', 'tanggal' => '2026-10-05', 'jam' => '25:00'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Jam tayang harus berbentuk JJ:MM (24 jam).');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/schedules',
        ['judul' => 'x', 'tanggal' => '2026-10-05', 'status' => 'TAYANG'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tandai Tayang butuh tautan postingannya.');
    $id = $this->withToken($t)->postJson('/api/v1/konten/analytics/schedules',
        ['judul' => 'Tayang perdana', 'tanggal' => '2026-10-05', 'status' => 'TERJADWAL'])
        ->assertOk()->json('data.id');
    expect($id)->toStartWith('jdw');
});

it('deletes bank ideas only from ARSIP', function () {
    $t = cgToken();
    $id = $this->withToken($t)->postJson('/api/v1/konten/analytics/bank', ['judul' => 'Ide hapus'])
        ->assertOk()->json('data.id');
    $this->withToken($t)->deleteJson("/api/v1/konten/analytics/bank/{$id}")
        ->assertStatus(422)->assertJsonPath('error.message', 'Hanya ide yang sudah diarsipkan yang bisa dihapus.');
    $this->withToken($t)->postJson("/api/v1/konten/analytics/bank/{$id}/arsip", ['arsip' => true])->assertOk();
    $this->withToken($t)->deleteJson("/api/v1/konten/analytics/bank/{$id}")->assertOk();
    expect(cgDb()->selectOne('SELECT id FROM cg_bank WHERE id = ?', [$id]))->toBeNull();
});

it('serves one page of top posts with the 9-number breakdown', function () {
    $t = cgToken();
    cgDb()->update("INSERT INTO cg_post (platform, post_id, judul, url, cover, tayang_at, views, likes, comments, shares, durasi, sync_at, hilang)
        VALUES ('tiktok', 'vid_test_1', 'Video uji', 'https://contoh.id/v1', '', 1760000000000, 1000, 100, 10, 5, 30, 1760000000000, 0)
        ON DUPLICATE KEY UPDATE judul=VALUES(judul), views=VALUES(views), hilang=0");
    $res = $this->withToken($t)->getJson('/api/v1/konten/analytics/top?per=10')->assertOk();
    expect($res->json('data.total'))->toBeGreaterThanOrEqual(1)
        ->and($res->json('data.per'))->toBe(10)->and($res->json('data.hal'))->toBe(1);
    $baris = $res->json('data.baris');
    expect($baris[0])->toHaveKeys(['rinci', 'platform', 'judul', 'url', 'tanggal', 'views', 'er', 'sumber']);
    expect(array_keys($baris[0]['rinci']))->toEqualCanonicalizing(
        ['reach', 'impr', 'views', 'likes', 'comments', 'shares', 'saves', 'cta', 'follow']);
});

it('refuses OAuth start without keys, exactly like legacy', function () {
    $t = cgToken();
    cgClearSettings();
    $this->withToken($t)->postJson('/api/v1/konten/analytics/oauth/tiktok/start')
        ->assertStatus(422)->assertJsonPath('error.message', 'Client key & secret TikTok belum diisi di Pengaturan.');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/oauth/instagram/start')
        ->assertStatus(422)->assertJsonPath('error.message', 'Instagram App ID & App Secret belum diisi di Pengaturan.');
});

it('rejects unknown OAuth states on the public callbacks', function () {
    $this->get('/api/v1/konten/analytics/oauth/tiktok/callback?code=x&state=salah')
        ->assertRedirect()->assertRedirectContains('tiktok=gagal');
    $this->get('/api/v1/konten/analytics/oauth/instagram/callback?code=x&state=salah')
        ->assertRedirect()->assertRedirectContains('instagram=gagal');
});

it('syncs TikTok against faked API responses', function () {
    $t = cgToken();
    cgDb()->update("INSERT INTO cg_akun (platform, open_id, access_token, refresh_token, token_exp, refresh_exp, scope, oleh, at)
        VALUES ('tiktok', 'open_1', 'tok_1', 'ref_1', 9999999999999, 9999999999999, '".CampaignAnalytics::TIKTOK_SCOPE."', 'tes', 1)
        ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
          token_exp=VALUES(token_exp), refresh_exp=VALUES(refresh_exp), scope=VALUES(scope)");
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, '/v2/user/info/')) {
            return Http::response(['data' => ['user' => [
                'display_name' => 'Brand', 'avatar_url' => 'https://contoh.id/a.png', 'follower_count' => 42,
                'following_count' => 7, 'likes_count' => 100, 'video_count' => 3,
            ]]]);
        }
        if (str_contains($url, '/v2/video/list/')) {
            return Http::response(['data' => ['videos' => [[
                'id' => 'vid_sync_1', 'video_description' => 'Sinkron', 'create_time' => 1760000000,
                'cover_image_url' => '', 'share_url' => 'https://contoh.id/s1',
                'view_count' => 500, 'like_count' => 50, 'comment_count' => 5, 'share_count' => 2, 'duration' => 15,
            ]], 'has_more' => false]]);
        }

        return Http::response(['data' => ['videos' => []]]);
    });
    $res = $this->withToken($t)->postJson('/api/v1/konten/analytics/oauth/tiktok/sync')->assertOk();
    expect($res->json('data.jumlah'))->toBeGreaterThanOrEqual(1);
    $post = cgDb()->selectOne("SELECT judul, views FROM cg_post WHERE platform='tiktok' AND post_id='vid_sync_1'");
    expect($post->judul)->toBe('Sinkron')->and((int) $post->views)->toBe(500);
});

it('syncs Instagram against faked API responses', function () {
    $t = cgToken();
    cgDb()->update("INSERT INTO cg_akun (platform, open_id, access_token, refresh_token, token_exp, refresh_exp, scope, oleh, at)
        VALUES ('instagram', 'ig_1', 'igtok_1', '', 9999999999999, 9999999999999, '".CampaignAnalytics::IG_SCOPE."', 'tes', 1)
        ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), token_exp=VALUES(token_exp), scope=VALUES(scope)");
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, '/me/media')) {
            return Http::response(['data' => [[
                'id' => 'ig_sync_1', 'caption' => 'IG sinkron', 'media_type' => 'VIDEO',
                'permalink' => 'https://contoh.id/ig1', 'timestamp' => '2026-10-01T10:00:00+0000',
                'thumbnail_url' => '', 'like_count' => 30, 'comments_count' => 3,
            ]]]);
        }
        if (str_contains($url, '/insights')) {
            return Http::response(['data' => [
                ['name' => 'views', 'values' => [['value' => 300]]],
                ['name' => 'reach', 'values' => [['value' => 250]]],
            ]]);
        }

        return Http::response([
            'username' => 'brand', 'name' => 'Brand', 'followers_count' => 10,
            'follows_count' => 2, 'media_count' => 1, 'biography' => 'Bio',
        ]);
    });
    $res = $this->withToken($t)->postJson('/api/v1/konten/analytics/oauth/instagram/sync')->assertOk();
    expect($res->json('data.jumlah'))->toBeGreaterThanOrEqual(1);
    $post = cgDb()->selectOne("SELECT views, reach FROM cg_post WHERE platform='instagram' AND post_id='ig_sync_1'");
    expect((int) $post->views)->toBe(300)->and((int) $post->reach)->toBe(250);
});

it('runs research against a faked Meta answer and stores it', function () {
    putenv('META_MODEL_API_KEY=fake-key');
    cgClearSettings();
    Http::fake([
        'api.meta.ai/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"ringkasan":"R","pola":[],"tren":[],"ide":[],"waktuTayang":[],"hashtag":[],"peringatan":[]}']],
            'model' => CampaignAnalytics::AI_MODELS[0]]),
    ]);
    $res = $this->withToken(cgToken())->postJson('/api/v1/konten/analytics/research', ['topik' => 'malam minggu'])
        ->assertOk();
    expect($res->json('data.hasil.ringkasan'))->toBe('R');
    expect(cgDb()->selectOne('SELECT topik FROM cg_riset WHERE id = ?', [$res->json('data.id')])->topik)->toBe('malam minggu');
    putenv('META_MODEL_API_KEY=');
});

it('refuses research without an AI key, exactly like legacy', function () {
    cgClearSettings();
    $this->withToken(cgToken())->postJson('/api/v1/konten/analytics/research', ['topik' => 'x'])
        ->assertStatus(422)->assertJsonPath('error.message',
            'API key Meta (Muse Spark) belum diisi. Admin modul bisa mengisinya di Pengaturan.');
});
