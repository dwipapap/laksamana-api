<?php

use App\Modules\Konten\Services\CampaignAnalytics;
use App\Support\Modules;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/* Google Review (menu Analytics): u-andry holds module `konten` but is no
   campaign admin; the superadmin passes the campaign-admin gate. */

beforeEach(function () {
    config(['laksamana.modules.konten.data_dir' => storage_path('framework/testing/konten-db')]);
    app(CampaignAnalytics::class)->ensureTables();
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_ID' => '']);
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_SECRET' => '']);
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => '']);
    config(['laksamana.konten.secrets.META_MODEL_API_KEY' => '']);
    // Secrets are test-run pollution (no rollback past DDL): drop them so
    // every test starts keyless unless it sets its own.
    grDb()->delete("DELETE FROM cg_setting WHERE k IN ('google_client_id','google_client_secret','google_places_key')");
});

function grDb()
{
    return Modules::db('konten');
}

/** Test-namespaced ids, so earlier runs (no rollback past DDL) stay idempotent. */
function grSeedAkun(int $tokenExp, int $refreshExp): void
{
    grDb()->update("INSERT INTO cg_akun (platform, nama, open_id, access_token, refresh_token, token_exp, refresh_exp, scope, sync_galat, oleh, at)
        VALUES ('google', 'pemilik@contoh.id', 'pemilik@contoh.id', 'gtok_uji', 'gref_uji', ?, ?, '".CampaignAnalytics::GR_SCOPE."', '', 'tes', 1)
        ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
          token_exp=VALUES(token_exp), refresh_exp=VALUES(refresh_exp), scope=VALUES(scope)",
        [$tokenExp, $refreshExp]);
}

function grSeedReview(string $id, string $sumber, string $namaRes, string $tempatId, ?string $balasan = null): void
{
    grDb()->update('INSERT INTO cg_greview (id, nama_res, sumber, tempat_id, penulis, rating, teks, waktu_ms, balasan, pertama_at, sync_at)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nama_res=VALUES(nama_res), sumber=VALUES(sumber), tempat_id=VALUES(tempat_id)',
        [$id, $namaRes, $sumber, $tempatId, 'Tamu Uji '.$id, 'Kotor '.$id, (int) (microtime(true) * 1000) - 86400000,
            $balasan, 1700000000000, (int) (microtime(true) * 1000)]);
}

function grFakeSync(): void
{
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'mybusinessaccountmanagement.googleapis.com')) {
            return Http::response(['accounts' => [['name' => 'accounts/ACCTEST']]]);
        }
        if (str_contains($url, 'mybusinessbusinessinformation.googleapis.com')) {
            return Http::response(['locations' => [
                ['name' => 'locations/LOCTEST', 'title' => 'Laksamana Uji',
                    'storefrontAddress' => ['addressLines' => ['Jl Uji 1'], 'locality' => 'Pekanbaru'],
                    'metadata' => ['placeId' => 'ChIJgbpTEST001', 'mapsUri' => 'https://maps.google.com/?cid=9']],
                ['name' => 'locations/LOCDUP', 'title' => 'Laksamana Duplikat',
                    'metadata' => ['duplicateLocation' => true, 'placeId' => 'ChIJdupTEST001']],
            ]]);
        }
        if (str_contains($url, 'mybusiness.googleapis.com/v4/')) {
            return Http::response(['averageRating' => 4.5, 'totalReviewCount' => 100, 'reviews' => [[
                'name' => 'accounts/ACCTEST/locations/LOCTEST/reviews/REVTEST1',
                'reviewer' => ['displayName' => 'Puas', 'profilePhotoUrl' => 'https://contoh.id/f.png'],
                'starRating' => 'FIVE',
                'comment' => "Kopinya enak\n\n(Translated by Google)\nThe coffee is good",
                'createTime' => '2026-10-18T10:00:00Z',
            ]]]);
        }
        if (str_contains($url, 'places.googleapis.com/v1/places/ChIJplacesTEST001')) {
            return Http::response(['id' => 'ChIJplacesTEST001',
                'displayName' => ['text' => 'Cabang Uji'], 'formattedAddress' => 'Panam',
                'rating' => 4.1, 'userRatingCount' => 90, 'googleMapsUri' => 'https://maps.google.com/?cid=2',
                'reviews' => [[
                    'name' => 'places/ChIJplacesTEST001/reviews/REVPLACE1',
                    'authorAttribution' => ['displayName' => 'Tamu Places', 'uri' => 'https://contoh.id/u', 'photoUri' => ''],
                    'rating' => 2, 'originalText' => ['text' => 'Toilet kotor'],
                    'publishTime' => '2026-10-14T10:00:00Z',
                ]]]);
        }

        return Http::response([], 404);
    });
}

it('requires login and the konten or campaign module for google state', function () {
    $this->getJson('/api/v1/konten/analytics/google')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/konten/analytics/google')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('reads the google state without secrets', function () {
    grSeedAkun(9999999999999, 9999999999999);
    $res = $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/konten/analytics/google')->assertOk();
    foreach (['tempat', 'harian', 'review', 'totalTersimpan', 'akun', 'ai', 'syncAt', 'galat'] as $key) {
        expect($res->json("data.{$key}"))->not->toBeNull($key);
    }
    $akun = $res->json('data.akun');
    expect($akun)->not->toHaveKey('access_token')->not->toHaveKey('refresh_token');
    expect($akun['terhubung'])->toBeTrue();
});

it('refuses google start without keys, exactly like legacy', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/start')
        ->assertStatus(422)->assertJsonPath('error.message', 'Client ID & Client secret Google belum diisi di Pengaturan.');
});

it('starts google oauth with a 15-minute state', function () {
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_ID' => 'klien-uji']);
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_SECRET' => 'rahasia-uji']);
    $res = $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/start')->assertOk();
    expect($res->json('data.url'))->toContain('accounts.google.com')
        ->and($res->json('data.url'))->toContain('access_type=offline');
    $st = json_decode(grDb()->selectOne("SELECT v FROM cg_setting WHERE k = 'google_state'")->v ?? '', true);
    expect($st['exp'] ?? 0)->toBeGreaterThan(CampaignAnalytics::nowMs());
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_ID' => '']);
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_SECRET' => '']);
});

it('rejects unknown google oauth states on the public callback', function () {
    $this->get('/api/v1/konten/analytics/oauth/google/callback?code=x&state=salah')
        ->assertRedirect()->assertRedirectContains('google=gagal');
});

it('completes google oauth: token, locations, one sync, then redirects', function () {
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_ID' => 'klien-uji']);
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_SECRET' => 'rahasia-uji']);
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => 'kunci-uji']);
    grDb()->update("INSERT INTO cg_setting (k, v) VALUES ('google_state', ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v)",
        [json_encode(['s' => 'uji-state', 'exp' => CampaignAnalytics::nowMs() + 600000, 'by' => 'tes'])]);
    grDb()->update("INSERT INTO cg_setting (k, v) VALUES ('google_places', 'ChIJplacesTEST001')
        ON DUPLICATE KEY UPDATE v = VALUES(v)");
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'oauth2.googleapis.com/token') && ! str_contains($url, 'tokeninfo')) {
            return Http::response(['access_token' => 'gbaru', 'expires_in' => 3600,
                'refresh_token' => 'grefbaru', 'scope' => CampaignAnalytics::GR_SCOPE]);
        }
        if (str_contains($url, 'tokeninfo')) {
            return Http::response(['email' => 'pemilik@contoh.id']);
        }
        // Reuse the sync fakes for accounts, locations, reviews and places.
        if (str_contains($url, 'mybusinessaccountmanagement.googleapis.com')) {
            return Http::response(['accounts' => [['name' => 'accounts/ACCTEST']]]);
        }
        if (str_contains($url, 'mybusinessbusinessinformation.googleapis.com')) {
            return Http::response(['locations' => [
                ['name' => 'locations/LOCTEST', 'title' => 'Laksamana Uji',
                    'storefrontAddress' => ['addressLines' => ['Jl Uji 1'], 'locality' => 'Pekanbaru'],
                    'metadata' => ['placeId' => 'ChIJgbpTEST001', 'mapsUri' => 'https://maps.google.com/?cid=9']],
            ]]);
        }
        if (str_contains($url, 'mybusiness.googleapis.com/v4/')) {
            return Http::response(['averageRating' => 4.5, 'totalReviewCount' => 100, 'reviews' => [[
                'name' => 'accounts/ACCTEST/locations/LOCTEST/reviews/REVCB1',
                'reviewer' => ['displayName' => 'Tamu Callback'],
                'starRating' => 'FOUR', 'comment' => 'Enak',
                'createTime' => '2026-10-18T10:00:00Z',
            ]]]);
        }
        if (str_contains($url, 'places.googleapis.com/v1/places/')) {
            return Http::response(['id' => 'ChIJplacesTEST001', 'displayName' => ['text' => 'Cabang Uji'],
                'rating' => 4.1, 'userRatingCount' => 90, 'reviews' => []]);
        }

        return Http::response([], 404);
    });
    grDb()->delete('DELETE FROM cg_greview WHERE id = ?', [sha1('accounts/ACCTEST/locations/LOCTEST/reviews/REVCB1')]);
    $this->get('/api/v1/konten/analytics/oauth/google/callback?code=kode-uji&state=uji-state')
        ->assertRedirect()->assertRedirectContains('google=ok')->assertRedirectContains('#greview');
    expect(grDb()->selectOne("SELECT v FROM cg_setting WHERE k = 'google_state'")->v)->toBe('');
    $akun = grDb()->selectOne("SELECT nama, refresh_token FROM cg_akun WHERE platform = 'google'");
    expect($akun->nama)->toBe('pemilik@contoh.id')->and((string) $akun->refresh_token)->toBe('grefbaru');
    // locations are pulled, but no full review sync runs inside the redirect (#304)
    expect(grDb()->selectOne("SELECT id FROM cg_gtempat WHERE id = 'accounts/ACCTEST/locations/LOCTEST'"))->not->toBeNull()
        ->and(grDb()->selectOne('SELECT id FROM cg_greview WHERE id = ?', [sha1('accounts/ACCTEST/locations/LOCTEST/reviews/REVCB1')]))->toBeNull();
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_ID' => '']);
    config(['laksamana.konten.secrets.GOOGLE_CLIENT_SECRET' => '']);
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => '']);
});

it('continues a long review history from a cursor instead of re-pulling the newest 2,000 (#304)', function () {
    grSeedAkun(9999999999999, 9999999999999);
    $loc = 'accounts/ACCBF/locations/LOCBF';
    grDb()->update("INSERT INTO cg_gtempat (id, sumber, place_id, nama, aktif) VALUES (?, 'gbp', '', 'Riwayat Panjang', 1)
        ON DUPLICATE KEY UPDATE aktif = 1", [$loc]);
    grDb()->delete('DELETE FROM cg_setting WHERE k IN (?, ?)', ['gbp_bf_'.sha1($loc), 'gbp_bfc_'.sha1($loc)]);
    // every location: pages tok1..tok44 after the first, i.e. 45 pages in all
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'mybusiness.googleapis.com/v4/')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $n = isset($q['pageToken']) ? (int) substr((string) $q['pageToken'], 3) : 0;

            return Http::response(['averageRating' => 4.2, 'totalReviewCount' => 2250, 'reviews' => []]
                + ($n + 1 <= 44 ? ['nextPageToken' => 'tok'.($n + 1)] : []));
        }

        return Http::response([], 404);
    });
    $svc = app(CampaignAnalytics::class);
    $halaman = fn () => collect(Http::recorded())->filter(fn ($r) => str_contains((string) $r[0]->url(), 'LOCBF'))->count();

    $svc->googleSync();
    expect($halaman())->toBe(40)
        ->and(grDb()->selectOne('SELECT v FROM cg_setting WHERE k = ?', ['gbp_bfc_'.sha1($loc)])->v)->toBe('tok40');

    // next sync: page 1 (new reviews), then straight to the cursor, through to the end
    $svc->googleSync();
    $kedua = collect(Http::recorded())->filter(fn ($r) => str_contains((string) $r[0]->url(), 'LOCBF'))->slice(40)->map(fn ($r) => (string) $r[0]->url())->values();
    expect($kedua)->toHaveCount(6)
        ->and($kedua[1])->toContain('pageToken=tok40')
        ->and(grDb()->selectOne('SELECT v FROM cg_setting WHERE k = ?', ['gbp_bf_'.sha1($loc)])->v)->toBe('1')
        ->and(grDb()->selectOne('SELECT v FROM cg_setting WHERE k = ?', ['gbp_bfc_'.sha1($loc)])->v)->toBe('');
    grDb()->update('UPDATE cg_gtempat SET aktif = 0 WHERE id = ?', [$loc]);
});

it('syncs google reviews: dedup place, skips duplicates, keeps pertama_at', function () {
    grSeedAkun(9999999999999, 9999999999999);
    grDb()->update("INSERT INTO cg_setting (k, v) VALUES ('google_places', 'ChIJplacesTEST001')
        ON DUPLICATE KEY UPDATE v = VALUES(v)");
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => 'kunci-uji']);
    grFakeSync();
    $t = loginAs(officeUser('u-andry'));

    $sebelum = grDb()->selectOne('SELECT pertama_at FROM cg_greview WHERE id = ?', [sha1('accounts/ACCTEST/locations/LOCTEST/reviews/REVTEST1')]);
    $res = $this->withToken($t)->postJson('/api/v1/konten/analytics/google/sync')->assertOk();
    expect($res->json('data.jumlah'))->toBeGreaterThanOrEqual(2);

    // GBP place stored with its placeId; the duplicate profile never lands.
    $gbp = grDb()->selectOne("SELECT * FROM cg_gtempat WHERE id = 'accounts/ACCTEST/locations/LOCTEST'");
    expect($gbp->nama)->toBe('Laksamana Uji')->and($gbp->place_id)->toBe('ChIJgbpTEST001');
    expect(grDb()->selectOne("SELECT id FROM cg_gtempat WHERE id = 'accounts/ACCTEST/locations/LOCDUP'"))->toBeNull();

    // Translation suffix stripped: the guest's original text is stored.
    $rev = grDb()->selectOne('SELECT teks, pertama_at FROM cg_greview WHERE id = ?',
        [sha1('accounts/ACCTEST/locations/LOCTEST/reviews/REVTEST1')]);
    expect($rev->teks)->toBe('Kopinya enak');

    // Re-sync keeps pertama_at (insert-only column, never overwritten).
    $this->withToken($t)->postJson('/api/v1/konten/analytics/google/sync')->assertOk();
    $sesudah = grDb()->selectOne('SELECT pertama_at FROM cg_greview WHERE id = ?',
        [sha1('accounts/ACCTEST/locations/LOCTEST/reviews/REVTEST1')]);
    expect((int) $sesudah->pertama_at)->toBe((int) ($sebelum->pertama_at ?? $sesudah->pertama_at))
        ->and((int) $sesudah->pertama_at)->toBeGreaterThan(0);

    // Places-covered-by-GBP is skipped; the uncovered place is pulled.
    expect(grDb()->selectOne("SELECT id FROM cg_gtempat WHERE id = 'ChIJgbpTEST001'"))->toBeNull();
    $cab = grDb()->selectOne("SELECT * FROM cg_gtempat WHERE id = 'ChIJplacesTEST001'");
    expect($cab->sumber)->toBe('places')->and((int) $cab->total)->toBe(90);
    $rplace = grDb()->selectOne('SELECT penulis FROM cg_greview WHERE id = ?',
        [sha1('places/ChIJplacesTEST001/reviews/REVPLACE1')]);
    expect($rplace->penulis)->toBe('Tamu Places');
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => '']);
});

it('posts a reply to google and records the writer', function () {
    grSeedAkun(9999999999999, 9999999999999);
    grSeedReview('grbalas1', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVTEST1', 'accounts/ACCTEST/locations/LOCTEST');
    Http::fake(['mybusiness.googleapis.com/*' => Http::response(['updateTime' => '2026-10-19T00:00:00Z'])]);
    $t = loginAs(officeUser('u-andry'));
    $res = $this->withToken($t)->postJson('/api/v1/konten/analytics/google/reply',
        ['id' => 'grbalas1', 'teks' => 'Mohon maaf Kak, kami tindak lanjuti.'])->assertOk();
    expect($res->json('data.ok'))->toBeTrue();
    $row = grDb()->selectOne('SELECT balasan, balasan_oleh, balasan_at FROM cg_greview WHERE id = ?', ['grbalas1']);
    expect($row->balasan)->toBe('Mohon maaf Kak, kami tindak lanjuti.')
        ->and($row->balasan_oleh)->toBe(officeUser('u-andry')['name'])
        ->and((int) $row->balasan_at)->toBeGreaterThan(0);
});

it('refuses replies for places-sourced reviews, exactly like legacy', function () {
    grSeedAkun(9999999999999, 9999999999999);
    grSeedReview('grplaces1', 'places', 'places/ChIJplacesTEST001/reviews/REVPLACE1', 'ChIJplacesTEST001');
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/reply',
        ['id' => 'grplaces1', 'teks' => 'Halo'])
        ->assertStatus(422)->assertJsonPath('error.message',
            'Review ini ditarik lewat Places API — Google tidak mengizinkan membalasnya dari luar. Hubungkan Google Business Profile di Pengaturan supaya review bisa dibalas dari sini.');
});

it('refuses an empty reply, exactly like legacy', function () {
    grSeedReview('grbalas2', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVEMPTY1', 'accounts/ACCTEST/locations/LOCTEST');
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/reply',
        ['id' => 'grbalas2'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Isi balasannya dulu.');
});

it('deletes our own reply on google and clears it locally', function () {
    grSeedAkun(9999999999999, 9999999999999);
    grSeedReview('grhapus1', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVDEL1',
        'accounts/ACCTEST/locations/LOCTEST', 'Balasan lama');
    Http::fake(['mybusiness.googleapis.com/*' => Http::response('', 204)]);
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/reply/delete',
        ['id' => 'grhapus1'])->assertOk()->assertJsonPath('data.ok', true);
    $row = grDb()->selectOne('SELECT balasan, balasan_at FROM cg_greview WHERE id = ?', ['grhapus1']);
    expect($row->balasan)->toBeNull()->and((int) $row->balasan_at)->toBe(0);
});

it('flags a review ditangani with a note, and unflags it clean', function () {
    $t = loginAs(officeUser('u-andry'));
    grSeedReview('grflag1', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVFLAG1', 'accounts/ACCTEST/locations/LOCTEST');
    $this->withToken($t)->postJson('/api/v1/konten/analytics/google/flag',
        ['id' => 'grflag1', 'ditangani' => true, 'catatan' => 'tamu dihubungi'])->assertOk();
    $row = grDb()->selectOne('SELECT ditangani, tangani_catatan, tangani_oleh FROM cg_greview WHERE id = ?', ['grflag1']);
    expect((int) $row->ditangani)->toBe(1)->and($row->tangani_catatan)->toBe('tamu dihubungi')
        ->and($row->tangani_oleh)->toBe(officeUser('u-andry')['name']);
    $this->withToken($t)->postJson('/api/v1/konten/analytics/google/flag',
        ['id' => 'grflag1', 'ditangani' => false])->assertOk();
    $row = grDb()->selectOne('SELECT ditangani, tangani_catatan, tangani_oleh FROM cg_greview WHERE id = ?', ['grflag1']);
    expect((int) $row->ditangani)->toBe(0)->and($row->tangani_catatan)->toBeNull();
});

it('analyses reviews with AI, drops invented ids, and stores the result', function () {
    config(['laksamana.konten.secrets.META_MODEL_API_KEY' => 'kunci-palsu']);
    grSeedReview('grai1', 'gbp', 'accounts/ACCTEST/locations/LOCAI/reviews/REVA1', 'accounts/ACCTEST/locations/LOCAI');
    Http::fake(['api.meta.ai/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"ringkasan":"R","sentimen":{"positif":0,"netral":0,"negatif":1},'
        .'"kekuatan":[],"perbaikan":[{"masalah":"Kotor","area":"Kebersihan","jumlah":1,"prioritas":"tinggi","saran":"Bersihkan","bukti":["r1","rX"]}],'
        .'"tangani":[{"review":"r1","urgensi":"segera","alasan":"Kotor","tindakan":"Hubungi","usulanBalasan":"Maaf Kak"}]}']],
        'model' => CampaignAnalytics::AI_MODELS[0]])]);
    $res = $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/ai',
        ['hari' => 365, 'tempat' => 'accounts/ACCTEST/locations/LOCAI'])->assertOk();
    // rX is invented: dropped. r1 maps to the real stored review.
    expect($res->json('data.perbaikan.0.bukti'))->toEqualCanonicalizing(['grai1'])
        ->and($res->json('data.tangani.0.id'))->toBe('grai1');
    $tersimpan = json_decode(grDb()->selectOne("SELECT v FROM cg_setting WHERE k = 'gr_ai'")->v ?? '', true);
    expect($tersimpan['ringkasan'] ?? null)->toBe('R');
    config(['laksamana.konten.secrets.META_MODEL_API_KEY' => '']);
});

it('drafts a single reply with AI without storing anything', function () {
    config(['laksamana.konten.secrets.META_MODEL_API_KEY' => 'kunci-palsu']);
    grSeedReview('grdraf1', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVD1', 'accounts/ACCTEST/locations/LOCTEST');
    Http::fake(['api.meta.ai/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"balasan":"Terima kasih Kak"}']],
        'model' => CampaignAnalytics::AI_MODELS[0]])]);
    $res = $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/draft',
        ['id' => 'grdraf1'])->assertOk();
    expect($res->json('data.balasan'))->toBe('Terima kasih Kak');
    config(['laksamana.konten.secrets.META_MODEL_API_KEY' => '']);
});

it('refuses google AI without a key, exactly like legacy', function () {
    grSeedReview('graikey1', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVKEY1', 'accounts/ACCTEST/locations/LOCTEST');
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/konten/analytics/google/ai', ['hari' => 365])
        ->assertStatus(422)->assertJsonPath('error.message',
            'API key Meta (Muse Spark) belum diisi. Admin modul bisa mengisinya di Pengaturan.');
});

it('keeps place search and disconnect behind the campaign admin', function () {
    $t = loginAs(officeUser('u-andry'));
    $this->withToken($t)->postJson('/api/v1/konten/analytics/google/search', ['q' => 'Laksamana'])
        ->assertStatus(403);
    $this->withToken($t)->postJson('/api/v1/konten/analytics/google/disconnect')
        ->assertStatus(403);
});

it('refuses place search without a places key, exactly like legacy', function () {
    $this->withToken(loginAs(anyActiveUser(true)))->postJson('/api/v1/konten/analytics/google/search', ['q' => 'Laksamana'])
        ->assertStatus(422)->assertJsonPath('error.message', 'API key Places belum diisi (simpan dulu pengaturannya).');
});

it('searches places and disconnects without deleting reviews', function () {
    $admin = loginAs(anyActiveUser(true));
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => 'kunci-uji']);
    Http::fake(['places.googleapis.com/*' => Http::response(['places' => [[
        'id' => 'ChIJbaru000001', 'displayName' => ['text' => 'Laksamana Baru'],
        'formattedAddress' => 'Jl X', 'rating' => 4.8, 'userRatingCount' => 20,
    ]]])]);
    $res = $this->withToken($admin)->postJson('/api/v1/konten/analytics/google/search', ['q' => 'Laksamana'])->assertOk();
    expect($res->json('data.hasil.0.id'))->toBe('ChIJbaru000001');
    config(['laksamana.konten.secrets.GOOGLE_PLACES_KEY' => '']);

    grSeedAkun(9999999999999, 9999999999999);
    grSeedReview('grputus1', 'gbp', 'accounts/ACCTEST/locations/LOCTEST/reviews/REVPUTUS1', 'accounts/ACCTEST/locations/LOCTEST');
    $this->withToken($admin)->postJson('/api/v1/konten/analytics/google/disconnect')->assertOk();
    $akun = grDb()->selectOne("SELECT access_token, refresh_token FROM cg_akun WHERE platform = 'google'");
    expect((string) $akun->access_token)->toBe('')->and((string) $akun->refresh_token)->toBe('');
    expect(grDb()->selectOne("SELECT id FROM cg_greview WHERE id = 'grputus1'")->id)->toBe('grputus1');
});

it('saves google settings and rejects a pasted maps link as place id', function () {
    $admin = loginAs(anyActiveUser(true));
    $this->withToken($admin)->putJson('/api/v1/konten/analytics/settings',
        ['placesIds' => 'https://maps.google.com/xyz'])
        ->assertStatus(422)->assertJsonPath('error.message',
            'Place ID tidak sah: "https://maps.google.com/xyz". Pakai tombol Cari tempat untuk mendapatkannya.');
    $this->withToken($admin)->putJson('/api/v1/konten/analytics/settings',
        ['googleClientId' => 'klien-uji', 'placesKey' => 'kunci-uji', 'placesIds' => 'ChIJsetTEST001, ChIJsetTEST001'])
        ->assertOk();
    expect(grDb()->selectOne("SELECT v FROM cg_setting WHERE k = 'google_client_id'")->v)->toBe('klien-uji');
    expect(grDb()->selectOne("SELECT v FROM cg_setting WHERE k = 'google_places'")->v)->toBe('ChIJsetTEST001');
    $ringkas = $this->withToken($admin)->getJson('/api/v1/konten/analytics/settings')->assertOk()->json('data');
    expect($ringkas['placesIds'] ?? '')->toContain('ChIJsetTEST001');
});
