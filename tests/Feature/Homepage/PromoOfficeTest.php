<?php

use App\Modules\Bd\Services\BdState;
use App\Modules\Homepage\Models\HomepageBanner;
use App\Modules\Homepage\Services\HomepagePhotos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

/*
 * Office /api/v1/homepage/office/promos — the Promo panel (docs/api/homepage.md
 * §Office Promo). Sanctum + module:homepage. It never writes a BD row: BD is
 * read through BdState and the switch/order/upload live in `homepage_banner`.
 */

beforeEach(function () {
    $this->app->useStoragePath(base_path('storage/framework/testing/homepage-office-storage'));
    $this->artisan('office:grant', ['login' => 'Wandi', 'modules' => ['homepage']])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory(base_path('storage/framework/testing/homepage-office-storage'));
});

it('requires auth for the office promo panel', function () {
    $this->getJson('/api/v1/homepage/office/promos')->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('lists rows and BD candidates with eligible/alasan and the manual order', function () {
    $wib = fn () => now('Asia/Jakarta');
    $running = bdPromo(['nama' => 'Jalan Terus']);
    $upcoming = bdPromo([
        'nama' => 'Akan Mulai',
        'mulai' => $wib()->addDay()->toDateString(),
        'selesai' => $wib()->addDays(3)->toDateString(),
    ]);
    $paused = bdPromo(['nama' => 'Dijeda BD', 'paused' => true]);
    $ended = bdPromo([
        'nama' => 'Sudah Lewat',
        'mulai' => $wib()->subDays(5)->toDateString(),
        'selesai' => $wib()->subDay()->toDateString(),
    ]);
    $noPoster = bdPromo(['nama' => 'Tanpa Poster', 'poster' => '']);
    bdSetPromos([$running, $upcoming, $paused, $ended, $noPoster]);

    $endedRow = bannerRow(['sumber' => 'bd', 'promo_id' => $ended['id'], 'alt' => 'Lewat', 'tampil' => true, 'urutan' => 10]);
    $missingRow = bannerRow(['sumber' => 'bd', 'promo_id' => 'pr_hilang', 'tampil' => true, 'urutan' => 20]);
    $outside = bannerRow(['tampil' => true, 'mulai' => $wib()->addDay()->toDateString(), 'urutan' => 30]);
    $live = bannerRow(['tampil' => true, 'urutan' => 40]);
    $off = bannerRow(['tampil' => false, 'urutan' => 50]);

    $r = $this->withToken(homepageToken())->getJson('/api/v1/homepage/office/promos')->assertOk();
    $items = collect($r->json('data.items'));
    $byId = $items->keyBy('id');

    expect($r->json('data.bd_gagal'))->toBeFalse()
        ->and(collect($r->json('data.items'))->pluck('id')->take(5)->all())->toBe([
            $endedRow->id, $missingRow->id, $outside->id, $live->id, $off->id,
        ])
        ->and($byId[$endedRow->id]['alasan'])->toBe('bd_berakhir')
        ->and($byId[$endedRow->id]['bd']['status'])->toBe('ended')
        ->and($byId[$missingRow->id]['alasan'])->toBe('bd_hilang')
        ->and($byId[$missingRow->id]['bd'])->toBeNull()
        ->and($byId[$missingRow->id]['gambar'])->toBeNull()
        ->and($byId[$outside->id]['alasan'])->toBe('di_luar_periode')
        ->and($byId[$live->id]['alasan'])->toBeNull()
        ->and($byId[$live->id]['eligible'])->toBeTrue()
        ->and($byId[$off->id]['tampil'])->toBeFalse();

    // candidates carry id:null, their BD facts and a by-promo preview
    $candidates = $items->whereNull('id')->values();
    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]['promo_id'])->toBe($running['id'])
        ->and($candidates[0]['alasan'])->toBeNull()
        ->and($candidates[0]['gambar'])->toBe('/api/v1/homepage/office/promos/gambar?promo='.$running['id'])
        ->and($candidates[1]['promo_id'])->toBe($upcoming['id'])
        ->and($candidates[1]['alasan'])->toBe('bd_belum_mulai');

    // paused / ended / no-poster promos are never candidates; a row keeps its row
    $promoIds = $items->pluck('promo_id')->all();
    expect($promoIds)->not->toContain($paused['id'])
        ->not->toContain($noPoster['id']);

    // the office item is an allow-list too: BD secrets never appear
    $body = (string) $r->getContent();
    foreach (['PARTNER_RAHASIA', 'KODE_RAHASIA', 'PIC_RAHASIA', 'Syarat rahasia'] as $secret) {
        expect($body)->not->toContain($secret);
    }

    $item = $byId[$live->id];
    expect(array_keys($item))->toBe([
        'id', 'sumber', 'promo_id', 'alt', 'href', 'mulai', 'selesai',
        'tampil', 'urutan', 'version', 'eligible', 'alasan', 'gambar', 'bd',
    ]);
    expect(array_keys($byId[$endedRow->id]['bd']))->toBe([
        'nama', 'tipe', 'kategori', 'benefit', 'mulai', 'selesai', 'status',
    ]);
});

it('validates the switches and never deletes an ineligible row', function () {
    $token = homepageToken();
    $outside = bannerRow(['tampil' => false, 'mulai' => now('Asia/Jakarta')->addDay()->toDateString()]);
    $live = bannerRow(['tampil' => false]);

    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$outside->id}/tampil", ['tampil' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'tidak_eligible');
    expect((bool) bannerDbRow($outside->id)['tampil'])->toBeFalse();

    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$outside->id}/tampil", [])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // switching OFF is always allowed, even outside the window
    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$outside->id}/tampil", ['tampil' => false])
        ->assertOk()->assertJsonPath('data.tampil', false);

    $on = $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$live->id}/tampil", ['tampil' => true])->assertOk();
    expect($on->json('data.tampil'))->toBeTrue()
        ->and($on->json('data.version'))->toBe(2);

    $this->withToken($token)->patchJson('/api/v1/homepage/office/promos/01tidakadaxxxxxxxxxxxxxxxxx/tampil', ['tampil' => true])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('upserts the BD switch by promo id and never writes the BD document', function () {
    $running = bdPromo(['nama' => 'Promo Saklar']);
    $second = bdPromo(['nama' => 'Promo Kedua']);
    $paused = bdPromo(['nama' => 'Dijeda', 'paused' => true]);
    $noPoster = bdPromo(['nama' => 'Tanpa Poster', 'poster' => '']);
    bdSetPromos([$running, $second, $paused, $noPoster]);
    $before = bdPromosDocument();

    $token = homepageToken();
    $on = $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/bd/{$running['id']}/tampil", ['tampil' => true])
        ->assertOk();
    expect($on->json('data.sumber'))->toBe('bd')
        ->and($on->json('data.promo_id'))->toBe($running['id'])
        ->and($on->json('data.tampil'))->toBeTrue()
        ->and($on->json('data.urutan'))->toBe(10)
        ->and($on->json('data.version'))->toBe(1);

    $rowId = $on->json('data.id');
    $off = $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/bd/{$running['id']}/tampil", ['tampil' => false])
        ->assertOk();
    expect($off->json('data.id'))->toBe($rowId)
        ->and($off->json('data.tampil'))->toBeFalse()
        ->and($off->json('data.version'))->toBe(2);

    // a new row gets the position after every existing one
    $next = $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/bd/{$second['id']}/tampil", ['tampil' => true])
        ->assertOk();
    expect($next->json('data.urutan'))->toBe(20);

    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/bd/{$paused['id']}/tampil", ['tampil' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'tidak_eligible');
    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/bd/{$noPoster['id']}/tampil", ['tampil' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'tidak_eligible');
    $this->withToken($token)->patchJson('/api/v1/homepage/office/promos/bd/pr_tidakada/tampil', ['tampil' => true])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    expect(HomepageBanner::query()->where('promo_id', $paused['id'])->exists())->toBeFalse();

    // BD owns the document: it is byte-for-byte what it was
    expect(bdPromosDocument())->toBe($before);
});

it('requires If-Match on PATCH/DELETE, refuses to delete a BD row, and removes the upload file', function () {
    $token = homepageToken();
    $upload = bannerRow(['tampil' => true, 'gambar_key' => homepagePhoto('hb_hapus.jpg', 'BYE')]);
    $bd = bdPromo();
    bdSetPromos([$bd]);
    $bdRow = bannerRow(['sumber' => 'bd', 'promo_id' => $bd['id'], 'tampil' => true]);

    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$upload->id}", ['alt' => 'Baru'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');

    $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$upload->id}", ['alt' => 'Baru'], ['If-Match' => '99'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current', 1);

    $ok = $this->withToken($token)->patchJson("/api/v1/homepage/office/promos/{$upload->id}", [
        'alt' => 'Baru',
        'href' => '/promo/kopi',
        'mulai' => now('Asia/Jakarta')->subDay()->toDateString(),
        'selesai' => now('Asia/Jakarta')->addDay()->toDateString(),
    ], ['If-Match' => '1'])->assertOk();
    expect($ok->json('data.alt'))->toBe('Baru')
        ->and($ok->json('data.href'))->toBe('/promo/kopi')
        ->and($ok->json('data.version'))->toBe(2);

    // BD rows are switched off in BD, never deleted here
    $this->withToken($token)->deleteJson("/api/v1/homepage/office/promos/{$bdRow->id}", [], ['If-Match' => '1'])
        ->assertStatus(422)->assertJsonPath('error.code', 'pakai_saklar');
    expect(HomepageBanner::query()->find($bdRow->id))->not->toBeNull();

    $this->withToken($token)->deleteJson("/api/v1/homepage/office/promos/{$upload->id}", [], ['If-Match' => '2'])
        ->assertOk()->assertJsonPath('data.deleted', true);
    expect(HomepageBanner::query()->find($upload->id))->toBeNull()
        ->and(app(HomepagePhotos::class)->exists('hb_hapus.jpg'))->toBeFalse();

    // DELETE without a version is refused before anything is removed
    $other = bannerRow();
    $this->withToken($token)->deleteJson("/api/v1/homepage/office/promos/{$other->id}")
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
    expect(HomepageBanner::query()->find($other->id))->not->toBeNull();
});

it('saves a partial manual order and skips a foreign id', function () {
    $a = bannerRow(['urutan' => 10]);
    $b = bannerRow(['urutan' => 20]);
    $c = bannerRow(['urutan' => 30]);

    $this->withToken(homepageToken())->putJson('/api/v1/homepage/office/promos/urutan', [
        'items' => [
            ['id' => $c->id, 'urutan' => 5],
            ['id' => '01asingxxxxxxxxxxxxxxxxxxx', 'urutan' => 1],
            ['id' => $a->id, 'urutan' => 50],
        ],
    ])->assertOk()->assertJsonPath('data.count', 2);

    expect((int) bannerDbRow($c->id)['urutan'])->toBe(5)
        ->and((int) bannerDbRow($a->id)['urutan'])->toBe(50)
        ->and((int) bannerDbRow($b->id)['urutan'])->toBe(20);
});

it('creates an upload banner and validates href/periode/gambar_key and the switch', function () {
    $token = homepageToken();
    $key = homepagePhoto('hb_tambah.jpg');

    $this->withToken($token)->postJson('/api/v1/homepage/office/promos', ['gambar_key' => $key, 'href' => 'http://bukan-https'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->postJson('/api/v1/homepage/office/promos', ['gambar_key' => $key, 'href' => '//evil.example.com'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->postJson('/api/v1/homepage/office/promos', ['gambar_key' => 'hb_tidak_ada.jpg'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->postJson('/api/v1/homepage/office/promos', [
        'gambar_key' => $key,
        'mulai' => now('Asia/Jakarta')->addDays(2)->toDateString(),
        'selesai' => now('Asia/Jakarta')->toDateString(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // switching ON at creation follows the same eligibility rule
    $this->withToken($token)->postJson('/api/v1/homepage/office/promos', [
        'gambar_key' => $key,
        'mulai' => now('Asia/Jakarta')->addDay()->toDateString(),
        'tampil' => true,
    ])->assertStatus(422)->assertJsonPath('error.code', 'tidak_eligible');

    $ok = $this->withToken($token)->postJson('/api/v1/homepage/office/promos', [
        'gambar_key' => $key, 'alt' => 'Promo Baru', 'href' => 'https://example.com/promo',
    ])->assertCreated();
    expect($ok->json('data.sumber'))->toBe('unggah')
        ->and($ok->json('data.tampil'))->toBeFalse()
        ->and($ok->json('data.urutan'))->toBe(10)
        ->and($ok->json('data.version'))->toBe(1)
        ->and($ok->json('data.gambar'))->toBe('/api/v1/homepage/office/promos/gambar?banner='.$ok->json('data.id'));
});

it('uploads a photo with a server-made key', function () {
    $token = homepageToken();

    $up = $this->withToken($token)->post('/api/v1/homepage/office/promos/foto', ['file' => promoPng()])->assertCreated();
    $key = $up->json('data.key');
    expect($key)->toMatch('/^hb_[a-f0-9]{8}\.png$/')
        ->and($up->json('data.url'))->toBeNull(); // never served by key: only by banner id

    $this->withToken($token)->post('/api/v1/homepage/office/promos/foto', ['file' => UploadedFile::fake()->create('doc.pdf', 3, 'application/pdf')])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
    $this->withToken($token)->post('/api/v1/homepage/office/promos/foto', ['file' => UploadedFile::fake()->create('big.png', 9000, 'image/png')])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
    $this->withToken($token)->post('/api/v1/homepage/office/promos/foto', [])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('previews images by banner id or BD promo id only', function () {
    $upload = bannerRow(['gambar_key' => homepagePhoto('hb_preview.jpg', 'PREVIEW')]);
    $bd = bdPromo();
    bdSetPromos([$bd]);
    $token = homepageToken();

    $byBanner = $this->withToken($token)->get('/api/v1/homepage/office/promos/gambar?banner='.$upload->id)->assertOk();
    expect($byBanner->getContent())->toBe('PREVIEW');

    $byPromo = $this->withToken($token)->get('/api/v1/homepage/office/promos/gambar?promo='.$bd['id'])->assertOk();
    expect($byPromo->getContent())->toBe('JPEGBODY');

    $this->withToken($token)->get('/api/v1/homepage/office/promos/gambar')->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->get('/api/v1/homepage/office/promos/gambar?banner=x&promo=y')->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->get('/api/v1/homepage/office/promos/gambar?banner=01tidakada')->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
    $this->withToken($token)->get('/api/v1/homepage/office/promos/gambar?promo=pr_tidakada')->assertStatus(404);
});

it('flags bd_gagal and keeps the rows when BD cannot be read', function () {
    $this->app->instance(BdState::class, new class extends BdState
    {
        public function setting(string $k, mixed $default): mixed
        {
            throw new RuntimeException('BD down');
        }
    });

    $keep = bannerRow(['tampil' => true]);

    $r = $this->withToken(homepageToken())->getJson('/api/v1/homepage/office/promos')->assertOk();
    expect($r->json('data.bd_gagal'))->toBeTrue()
        ->and(collect($r->json('data.items'))->pluck('id')->all())->toBe([$keep->id]);

    // switching on an existing BD row cannot be verified while BD is down
    $bdRow = bannerRow(['sumber' => 'bd', 'promo_id' => 'pr_x']);
    $this->withToken(homepageToken())->patchJson("/api/v1/homepage/office/promos/{$bdRow->id}/tampil", ['tampil' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'tidak_eligible');

    // the dedicated BD toggle says why
    $this->withToken(homepageToken())->patchJson('/api/v1/homepage/office/promos/bd/pr_x/tampil', ['tampil' => true])
        ->assertStatus(503)->assertJsonPath('error.code', 'bd_tidak_terhubung');
});
