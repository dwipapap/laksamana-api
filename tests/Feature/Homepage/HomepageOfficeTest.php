<?php

use App\Modules\Event\Services\EventState;
use App\Modules\Homepage\Models\HomepageEvent;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

/*
 * Office /api/v1/homepage/office — the Event switch screen (docs/api/homepage.md
 * §Office). Sanctum + module:homepage; the toggle never touches an EMS row.
 */

beforeEach(function () {
    $this->posterDir = base_path('storage/framework/testing/homepage-posters');
    File::ensureDirectoryExists($this->posterDir);
    config(['laksamana.ticketing.event_files_dir' => $this->posterDir]);
    // The switch key is registered/granted on a server from the CLI (no migration).
    $this->artisan('office:grant', ['login' => 'Wandi', 'modules' => ['homepage']])->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->posterDir);
});

it('requires auth for the office surface', function () {
    $this->getJson('/api/v1/homepage/office/events')->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('lists candidates with tampil/eligible/alasan and hides the clearly dead', function () {
    $upcoming = emsEvent(['title' => 'Mendatang', 'start_datetime' => now('UTC')->addDays(2)->toIso8601ZuluString()]);
    $planning = emsEvent(['title' => 'Rencana', 'status' => 'Planning', 'start_datetime' => now('UTC')->addDays(3)->toIso8601ZuluString()]);
    $pastOn = emsEvent([
        'title' => 'Baru Lewat',
        'start_datetime' => now('UTC')->subDays(2)->toIso8601ZuluString(),
        'end_datetime' => now('UTC')->subDays(2)->addHours(2)->toIso8601ZuluString(),
    ]);
    $pastOff = emsEvent([
        'title' => 'Lama Lewat',
        'start_datetime' => now('UTC')->subDays(3)->toIso8601ZuluString(),
        'end_datetime' => now('UTC')->subDays(3)->addHours(2)->toIso8601ZuluString(),
    ]);
    $cancelled = emsEvent(['title' => 'Batal', 'status' => 'Cancelled']);

    homepageSwitch($upcoming, true);
    homepageSwitch($pastOn, true);

    $r = $this->withToken(homepageToken())->getJson('/api/v1/homepage/office/events')->assertOk();
    $byId = collect($r->json('data'))->keyBy('id')->all();

    expect($r->json('meta.total'))->toBe(3)
        ->and(collect($r->json('data'))->pluck('id')->all())->toBe([$pastOn, $upcoming, $planning])
        ->and($byId[$upcoming]['tampil'])->toBeTrue()
        ->and($byId[$upcoming]['eligible'])->toBeTrue()
        ->and($byId[$upcoming]['alasan'])->toBeNull()
        ->and($byId[$upcoming]['version'])->toBe(1)
        ->and($byId[$planning]['tampil'])->toBeFalse()
        ->and($byId[$planning]['eligible'])->toBeFalse()
        ->and($byId[$planning]['alasan'])->toBe('belum_upcoming')
        ->and($byId[$planning]['version'])->toBe(0)
        ->and($byId[$pastOn]['eligible'])->toBeFalse()
        ->and($byId[$pastOn]['alasan'])->toBe('sudah_lewat')
        ->and($byId)->not->toHaveKey($pastOff)
        ->and($byId)->not->toHaveKey($cancelled);

    $row = $byId[$upcoming];
    expect(array_keys($row))->toBe([
        'id', 'title', 'category', 'status', 'start', 'end', 'venue', 'poster',
        'price_from', 'is_ticketed', 'tampil', 'eligible', 'alasan', 'version',
    ]);
});

it('toggles the switch on and off without writing a single EMS row', function () {
    $id = emsEvent();
    emsTicketClass($id, 75000);

    $before = app(EventState::class)->emsRows('events');
    $token = homepageToken();

    $on = $this->withToken($token)->patchJson("/api/v1/homepage/office/events/$id/tampil", ['tampil' => true])
        ->assertOk();
    expect($on->json('data.tampil'))->toBeTrue()
        ->and($on->json('data.version'))->toBe(1)
        ->and(homepageSwitchRow($id)['tampil'])->toBe(1)
        ->and(HomepageEvent::query()->where('event_id', $id)->exists())->toBeTrue();

    $off = $this->withToken($token)->patchJson("/api/v1/homepage/office/events/$id/tampil", ['tampil' => false])
        ->assertOk();
    expect($off->json('data.tampil'))->toBeFalse()
        ->and(homepageSwitchRow($id)['tampil'])->toBe(0);

    // the EMS table is byte-for-byte what it was: same rows, same updated_at
    expect(app(EventState::class)->emsRows('events'))->toBe($before);
});

it('validates the toggle body and refuses switching on an ineligible event', function () {
    $planning = emsEvent(['status' => 'Planning']);
    $id = emsEvent();
    $token = homepageToken();

    $this->withToken($token)->patchJson("/api/v1/homepage/office/events/$id/tampil", [])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    $this->withToken($token)->patchJson("/api/v1/homepage/office/events/$planning/tampil", ['tampil' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'tidak_eligible');
    expect(HomepageEvent::query()->where('event_id', $planning)->exists())->toBeFalse();

    // switching OFF an ineligible event is always allowed
    homepageSwitch($planning, true);
    $this->withToken($token)->patchJson("/api/v1/homepage/office/events/$planning/tampil", ['tampil' => false])
        ->assertOk()->assertJsonPath('data.tampil', false);
});

it('404s an unknown event id on the toggle and on the poster', function () {
    $token = homepageToken();

    $this->withToken($token)->patchJson('/api/v1/homepage/office/events/ev_tidak_ada/tampil', ['tampil' => true])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    $this->withToken($token)->get('/api/v1/homepage/office/events/ev_tidak_ada/poster')
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('previews the poster of any candidate (even a Planning event) by id only', function () {
    $planning = emsEvent(['status' => 'Planning']);
    emsUpdateEvent($planning, ['poster_img' => ['key' => 'ev_rencana.jpg', 'name' => 'rencana.jpg', 'size' => 3]]);
    File::put($this->posterDir.'/ev_rencana.jpg', 'JPG');

    $r = $this->withToken(homepageToken())->get("/api/v1/homepage/office/events/$planning/poster")->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($r->baseResponse->getFile()->getFilename())->toBe('ev_rencana.jpg');
});
