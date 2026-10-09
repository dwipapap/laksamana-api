<?php

use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/* u-andry holds module `event`; u-lusi does not. */

beforeEach(function () {
    config(['laksamana.modules.event.data_dir' => storage_path('framework/testing/event-db')]);
});

it('requires login and the event module', function () {
    $this->getJson('/api/v1/event/talents')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-lusi')))->getJson('/api/v1/event/talents')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('lists a collection with versions and indexed-column filters', function () {
    $eid = Modules::db('event')->selectOne(evSql('SELECT event_id FROM seats GROUP BY event_id ORDER BY COUNT(*) DESC LIMIT 1'))->event_id;
    $res = $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/event/seats?event_id='.$eid)->assertOk();

    $n = (int) Modules::db('event')->selectOne(evSql('SELECT COUNT(*) c FROM seats WHERE event_id = ?'), [$eid])->c;
    expect($res->json('meta.total'))->toBe($n)->and(collect($res->json('data'))->pluck('event_id')->unique()->all())->toBe([$eid]);
    $first = $res->json('data.0.id');
    expect($res->json("meta.versions.$first"))->toBe((int) Modules::db('event')->selectOne(evSql('SELECT updated_at FROM seats WHERE id = ?'), [$first])->updated_at);
});

it('filters events by date range on the WIB start column', function () {
    $d = $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/event/events?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');
    $n = (int) Modules::db('event')->selectOne(evSql("SELECT COUNT(*) c FROM events WHERE DATE(start_datetime) BETWEEN '2026-09-01' AND '2026-09-30'"))->c;
    expect(count($d))->toBe($n);
});

it('creates an event stamped with the acting user as createdBy, visible to eventsHari', function () {
    $res = $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/event/events', [
        'title' => 'V1 Night', 'status' => 'Upcoming', 'start_datetime' => '2031-07-01T12:00:00.000Z',
        'createdBy' => 'Someone Else', 'venue' => '',
    ])->assertCreated();
    $id = $res->json('data.id');
    expect($id)->toMatch('/^id[0-9a-z]{7}$/')->and($res->json('data.createdById'))->toBe('u-andry')
        ->and($res->json('data.createdBy'))->not->toBe('Someone Else')
        ->and($res->json('data.venue'))->toBe('')   // no empty-string-to-null
        ->and($res->json('meta.version'))->toBe($res->json('data.updatedAt'));

    $row = Modules::db('event')->selectOne(evSql('SELECT start_datetime, updated_at, created_at FROM events WHERE id = ?'), [$id]);
    expect($row->start_datetime)->toBe('2031-07-01 19:00:00')->and((int) $row->updated_at)->toBe($res->json('meta.version'));

    $this->flushHeaders();
    $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/event/events-on/2031-07-01')->assertOk()
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.inputOlehId', 'u-andry');
});

it('rejects a duplicate id, a missing version and a stale version', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->postJson('/api/v1/event/ideas', ['id' => 'id_v1dupe', 'name' => 'Satu'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/event/ideas', ['id' => 'id_v1dupe', 'name' => 'Dua'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
    $this->withToken($token)->patchJson('/api/v1/event/ideas/id_v1dupe', ['name' => 'X'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
    $this->withToken($token)->withHeader('If-Match', '"1"')->patchJson('/api/v1/event/ideas/id_v1dupe', ['name' => 'X'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')->assertJsonPath('error.details.current.name', 'Satu');
});

it('merges a PATCH, bumps the version above the stored one and beats an older legacy save', function () {
    $token = loginAs(officeUser('u-andry'));
    $c = $this->withToken($token)->postJson('/api/v1/event/talents', ['name' => 'Band', 'phone' => '0812', 'createdAt' => 5])->assertCreated();
    $id = $c->json('data.id');
    $v = $c->json('meta.version');

    $p = $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')->patchJson('/api/v1/event/talents/'.$id, ['name' => 'Band 2'])
        ->assertOk()->assertJsonPath('data.name', 'Band 2')->assertJsonPath('data.phone', '0812');
    expect($p->json('meta.version'))->toBeGreaterThan($v)->and($p->json('data.createdAt'))->toBe($c->json('data.createdAt'));

    // an old tab saving its stale copy (older updatedAt) through the compat route cannot undo it
    $this->call('POST', '/event-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'],
        json_encode(['action' => 'saveAll', 'data' => ['talents' => [['id' => $id, 'name' => 'Band', 'updatedAt' => $v]]]]))->assertOk();
    expect(Modules::db('event')->selectOne(evSql('SELECT name FROM talents WHERE id = ?'), [$id])->name)->toBe('Band 2');
});

it('replaces with PUT and deletes with the right version', function () {
    $token = loginAs(officeUser('u-andry'));
    $c = $this->withToken($token)->postJson('/api/v1/event/calendar-extra', ['type' => 'note', 'title' => 'A', 'date' => '2031-01-02', 'x' => 1])->assertCreated();
    $id = $c->json('data.id');
    $u = $this->withToken($token)->withHeader('If-Match', (string) $c->json('meta.version'))
        ->putJson('/api/v1/event/calendar-extra/'.$id, ['type' => 'note', 'title' => 'B', 'date' => '2031-01-03'])->assertOk();
    expect($u->json('data'))->not->toHaveKey('x');
    expect(Modules::db('event')->selectOne(evSql('SELECT tanggal FROM calendar_extra WHERE id = ?'), [$id])->tanggal)->toBe('2031-01-03');

    $this->withToken($token)->withHeader('If-Match', (string) $u->json('meta.version'))
        ->deleteJson('/api/v1/event/calendar-extra/'.$id)->assertOk()->assertJsonPath('data.deleted', true);
    $this->flushHeaders();
    $this->withToken($token)->getJson('/api/v1/event/calendar-extra/'.$id)->assertStatus(404);
});

it('answers 409 duplicate for a reused ticket QR token', function () {
    $qr = Modules::db('event')->selectOne(evSql('SELECT qr_token FROM tickets WHERE qr_token IS NOT NULL LIMIT 1'))->qr_token;
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/event/tickets', ['qr_token' => $qr])
        ->assertStatus(409)->assertJsonPath('error.code', 'duplicate');
});

it('creates, versions and deletes an event detail document', function () {
    $token = loginAs(officeUser('u-andry'));
    $c = $this->withToken($token)->putJson('/api/v1/event/event-details/ev_v1d', ['rundown' => [['t' => '20:00']]])->assertCreated();
    $this->withToken($token)->putJson('/api/v1/event/event-details/ev_v1d', ['rundown' => []])
        ->assertStatus(428);
    $u = $this->withToken($token)->withHeader('If-Match', (string) $c->json('meta.version'))
        ->putJson('/api/v1/event/event-details/ev_v1d', ['rundown' => []])->assertOk();
    $this->flushHeaders();
    $this->withToken($token)->getJson('/api/v1/event/event-details')->assertOk()
        ->assertJsonPath('data.ev_v1d.rundown', [])->assertJsonPath('meta.versions.ev_v1d', $u->json('meta.version'));
    $this->withToken($token)->withHeader('If-Match', (string) $u->json('meta.version'))
        ->deleteJson('/api/v1/event/event-details/ev_v1d')->assertOk();
});

it('records append-only check-ins with the acting user as staff', function () {
    $token = loginAs(officeUser('u-andry'));
    $c = $this->withToken($token)->postJson('/api/v1/event/checkins', ['id' => 'ci_v1', 'ticket_id' => 'tk_v1', 'staff' => 'Director', 'gate' => 'Main', 'result' => 'Valid'])
        ->assertCreated();
    $name = officeUser('u-andry')['name'];
    expect($c->json('data.staff'))->toBe($name)->and($c->json('data.checked_in_at'))->toEndWith('Z');

    $this->withToken($token)->postJson('/api/v1/event/checkins', ['id' => 'ci_v1', 'ticket_id' => 'tk_v1'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
    $this->withToken($token)->postJson('/api/v1/event/checkins', ['result' => 'Valid'])->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/event/checkins?ticket_id=tk_v1')->assertOk()->assertJsonPath('meta.total', 1);
    $this->withToken($token)->deleteJson('/api/v1/event/checkins/ci_v1')->assertNotFound(); // no delete route
});

it('reads and writes the settings documents with a content version', function () {
    $token = loginAs(officeUser('u-andry'));
    $s = $this->withToken($token)->getJson('/api/v1/event/settings')->assertOk();
    expect($s->json('data'))->toHaveKeys(['entertainmentRules', 'role', 'layoutTemplates']);

    $v = $s->json('meta.versions.layoutTemplates');
    $this->withToken($token)->withHeader('If-Match', 'nope')->putJson('/api/v1/event/settings/layoutTemplates', ['value' => []])->assertStatus(409);
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/event/settings/layoutTemplates', ['value' => [['id' => 'lt1']]])
        ->assertOk()->assertJsonPath('data.0.id', 'lt1');
    expect(Modules::db('event')->selectOne(evSql("SELECT v FROM settings WHERE k='layoutTemplates'"))->v)->toBe('[{"id":"lt1"}]');
});

it('uploads a file and streams it back', function () {
    $token = loginAs(officeUser('u-andry'));
    $key = $this->withToken($token)->postJson('/api/v1/event/files', ['dataBase64' => 'aGVsbG8=', 'fileName' => 'kontrak.pdf'])
        ->assertCreated()->json('data.key');
    expect($key)->toMatch('/^ev_[0-9a-f]{16}\.pdf$/');
    $this->withToken($token)->get('/api/v1/event/files/'.$key)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->withToken($token)->getJson('/api/v1/event/files/ev_nope.pdf')->assertStatus(404);
    $this->withToken($token)->postJson('/api/v1/event/files', ['dataBase64' => 'aGk=', 'fileName' => 'x.exe'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
});

it('rejects an invalid events-on date', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/event/events-on/2031-7-1')->assertStatus(422);
});

it('filters tickets by event_id through their order', function () {
    $token = loginAs(officeUser('u-andry'));
    $uniq = substr(bin2hex(random_bytes(4)), 0, 8);
    $e1 = $this->withToken($token)->postJson('/api/v1/event/events', [
        'title' => 'Filter A '.$uniq, 'status' => 'Upcoming', 'start_datetime' => '2031-08-01T12:00:00.000Z',
    ])->assertCreated()->json('data.id');
    $e2 = $this->withToken($token)->postJson('/api/v1/event/events', [
        'title' => 'Filter B '.$uniq, 'status' => 'Upcoming', 'start_datetime' => '2031-08-02T12:00:00.000Z',
    ])->assertCreated()->json('data.id');

    $o1 = $this->withToken($token)->postJson('/api/v1/event/orders', [
        'event_id' => $e1, 'buyer_name' => 'Buyer A', 'payment_status' => 'Paid',
    ])->assertCreated()->json('data.id');
    $o2 = $this->withToken($token)->postJson('/api/v1/event/orders', [
        'event_id' => $e2, 'buyer_name' => 'Buyer B', 'payment_status' => 'Paid',
    ])->assertCreated()->json('data.id');

    $t1 = $this->withToken($token)->postJson('/api/v1/event/tickets', [
        'order_item_id' => $o1, 'qr_token' => 'QR'.$uniq.'a1', 'status' => 'Valid',
    ])->assertCreated()->json('data.id');
    $t2 = $this->withToken($token)->postJson('/api/v1/event/tickets', [
        'order_item_id' => $o2, 'qr_token' => 'QR'.$uniq.'b1', 'status' => 'Valid',
    ])->assertCreated()->json('data.id');
    // A ticket carrying its own event_id is matched directly, even without an order.
    $t3 = $this->withToken($token)->postJson('/api/v1/event/tickets', [
        'event_id' => $e1, 'qr_token' => 'QR'.$uniq.'a2', 'status' => 'Valid',
    ])->assertCreated()->json('data.id');
    // A ticket whose order does not exist is kept unfiltered but never matches.
    $tOrphan = $this->withToken($token)->postJson('/api/v1/event/tickets', [
        'order_item_id' => 'order_missing_'.$uniq, 'qr_token' => 'QR'.$uniq.'x1', 'status' => 'Valid',
    ])->assertCreated()->json('data.id');

    $ids = fn ($res) => collect($res->json('data'))->pluck('id')->all();
    $filtered = $this->withToken($token)->getJson('/api/v1/event/tickets?event_id='.$e1)->assertOk();
    expect($ids($filtered))->toContain($t1, $t3)->not->toContain($t2, $tOrphan);

    $other = $this->withToken($token)->getJson('/api/v1/event/tickets?event_id='.$e2)->assertOk();
    expect($ids($other))->toContain($t2)->not->toContain($t1, $t3, $tOrphan);

    // Without the filter every ticket is still returned.
    $all = $this->withToken($token)->getJson('/api/v1/event/tickets')->assertOk();
    expect($ids($all))->toContain($t1, $t2, $t3, $tOrphan);
});
