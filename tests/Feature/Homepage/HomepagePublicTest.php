<?php

use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

/*
 * Public /api/v1/homepage/events — the website "Malam" feed (docs/api/homepage.md
 * §Public). No auth; only switched-on AND eligible events, in start order.
 */

beforeEach(function () {
    $this->posterDir = base_path('storage/framework/testing/homepage-posters');
    File::ensureDirectoryExists($this->posterDir);
    config(['laksamana.ticketing.event_files_dir' => $this->posterDir]);
});

afterEach(function () {
    File::deleteDirectory($this->posterDir);
});

it('serves only switched-on eligible events, in start order, with the allow-list shape', function () {
    $soon = emsEvent(['title' => 'Dekat', 'start_datetime' => now('UTC')->addDays(2)->toIso8601ZuluString()]);
    $later = emsEvent(['title' => 'Jauh', 'start_datetime' => now('UTC')->addDays(5)->toIso8601ZuluString()]);
    $planning = emsEvent(['title' => 'Masih Rencana', 'status' => 'Planning']);
    $past = emsEvent([
        'title' => 'Sudah Lewat',
        'start_datetime' => now('UTC')->subDays(3)->toIso8601ZuluString(),
        'end_datetime' => now('UTC')->subDays(2)->toIso8601ZuluString(),
    ]);

    homepageSwitch($soon, true);
    homepageSwitch($later, true);
    homepageSwitch($planning, true);
    homepageSwitch($past, true);

    emsTicketClass($soon, 150000);
    emsTicketClass($soon, 50000);

    $r = $this->getJson('/api/v1/homepage/events')->assertOk();

    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$soon, $later]);

    $first = $r->json('data.0');
    expect(array_keys($first))->toBe([
        'id', 'title', 'category', 'start', 'end', 'venue', 'description', 'poster', 'price_from', 'is_ticketed',
    ])
        ->and($first['id'])->toBe($soon)
        ->and($first['title'])->toBe('Dekat')
        ->and($first['category'])->toBe('Music')
        ->and($first['venue'])->toBe('Laksamana Muda')
        ->and($first['description'])->toBe('Deskripsi event uji.')
        ->and($first['poster'])->toBeNull()
        ->and($first['price_from'])->toBe(50000)
        ->and($first['is_ticketed'])->toBeTrue()
        ->and($first['start'])->toMatch('/\+07:00$/');
});

it('drops a switched-on event that stops being eligible without deleting its row', function () {
    $started = emsEvent([
        'title' => 'Barusan Mulai',
        'start_datetime' => now('UTC')->subHour()->toIso8601ZuluString(),
        'end_datetime' => now('UTC')->addHour()->toIso8601ZuluString(),
    ]);
    homepageSwitch($started, true);

    expect($this->getJson('/api/v1/homepage/events')->json('data.0.id'))->toBe($started);

    // ended: now past -> gone from the website, switch row untouched
    emsUpdateEvent($started, [
        'end_datetime' => now('UTC')->subMinute()->toIso8601ZuluString(),
    ]);

    expect($this->getJson('/api/v1/homepage/events')->json('data'))->toBe([])
        ->and(homepageSwitchRow($started)['tampil'])->toBe(1);
});

it('treats an event without an end date as past only after its WIB day', function () {
    // starts today at 22:00 WIB, no end: still eligible today
    $today = now('Asia/Jakarta')->startOfDay()->addHours(22)->toIso8601ZuluString();
    $on = emsEvent(['end_datetime' => '', 'start_datetime' => $today]);
    homepageSwitch($on, true);

    expect($this->getJson('/api/v1/homepage/events')->json('data.0.id'))->toBe($on);

    // yesterday with no end: past
    $old = emsEvent(['end_datetime' => '', 'start_datetime' => now('UTC')->subDay()->toIso8601ZuluString()]);
    homepageSwitch($old, true);

    expect(collect($this->getJson('/api/v1/homepage/events')->json('data'))->pluck('id')->all())->toBe([$on]);
});

it('sends Cache-Control and an ETag, and honours If-None-Match with a 304', function () {
    $id = emsEvent();
    homepageSwitch($id, true);

    $r = $this->getJson('/api/v1/homepage/events')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($r->headers->get('ETag'))->not->toBeNull()
        ->and($r->json('meta.version'))->not->toBe('0');

    $this->withHeaders(['If-None-Match' => $r->headers->get('ETag')])
        ->getJson('/api/v1/homepage/events')->assertStatus(304);
});

it('serves the poster only for a switched-on eligible event, by event id', function () {
    $on = emsEvent();
    homepageSwitch($on, true);
    emsUpdateEvent($on, ['poster_img' => ['key' => 'ev_poster.jpg', 'name' => 'poster.jpg', 'size' => 3]]);
    File::put($this->posterDir.'/ev_poster.jpg', 'PNG');

    $ok = $this->get("/api/v1/homepage/events/$on/poster")->assertOk();
    expect($ok->headers->get('Content-Type'))->toContain('image/jpeg')
        ->and($ok->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($ok->baseResponse->getFile()->getFilename())->toBe('ev_poster.jpg');

    // a switched-off event with a valid poster must not leak it
    $off = emsEvent();
    emsUpdateEvent($off, ['poster_img' => ['key' => 'ev_poster.jpg', 'name' => 'poster.jpg', 'size' => 3]]);
    $this->get("/api/v1/homepage/events/$off/poster")->assertStatus(404);

    // a switched-on but PAST event must not leak it either
    $past = emsEvent([
        'start_datetime' => now('UTC')->subDays(3)->toIso8601ZuluString(),
        'end_datetime' => now('UTC')->subDays(2)->toIso8601ZuluString(),
    ]);
    homepageSwitch($past, true);
    emsUpdateEvent($past, ['poster_img' => ['key' => 'ev_poster.jpg', 'name' => 'poster.jpg', 'size' => 3]]);
    $this->get("/api/v1/homepage/events/$past/poster")->assertStatus(404);

    // the folder holds other files (KTP, transfer proofs); a raw key is never a route
    File::put($this->posterDir.'/ktp_talent.jpg', 'SECRET');
    $this->get('/api/v1/homepage/events/ktp_talentjpg/poster')->assertStatus(404);
});

it('answers an empty list when nothing is switched on', function () {
    emsEvent();

    $this->getJson('/api/v1/homepage/events')->assertOk()
        ->assertJson(['data' => []]);
});
