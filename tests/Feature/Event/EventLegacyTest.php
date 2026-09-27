<?php

use App\Modules\Event\Services\EventSchema;
use App\Support\Modules;
use App\Support\RowSync;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['laksamana.modules.event.data_dir' => storage_path('framework/testing/event-db')]);
});

function evPost(array $body): TestResponse
{
    return test()->call('POST', '/event-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=utf-8'], json_encode($body));
}

function evDb()
{
    return Modules::db('event');
}

it('serves getAll by default with every collection, the details map and the settings', function () {
    $d = $this->get('/event-api-mysql/api.php')->assertOk()->assertJsonPath('ok', true)->json('data');

    expect(array_keys($d))->toBe(['talents', 'events', 'schedules', 'recurringRules', 'talentPayments', 'ticketClasses',
        'seats', 'orders', 'tickets', 'ideas', 'refunds', 'calendarExtra', 'checkins', 'eventDetails',
        'entertainmentRules', 'role', 'layoutTemplates'])
        ->and(count($d['seats']))->toBe((int) evDb()->selectOne('SELECT COUNT(*) c FROM seats')->c)
        ->and($d['events'][0]['id'])->toBe(evDb()->selectOne('SELECT id FROM events ORDER BY created_at, id LIMIT 1')->id);
});

it('keeps the legacy action quirks: ?action= is an unknown action, POST without action too', function () {
    $this->get('/event-api-mysql/api.php?action=')->assertOk()->assertExactJson(['ok' => false, 'error' => 'Aksi tidak dikenal: ']);
    evPost(['data' => []])->assertExactJson(['ok' => false, 'error' => 'Aksi tidak dikenal: ']);
    $this->get('/event-api-mysql/api.php?action=ping')->assertJsonPath('data.pong', true)->assertJsonPath('data.versi', '2026-08-11');
});

it('saves rows with the updated_at guard, verbatim data and WIB datetimes', function () {
    evPost(['action' => 'saveAll', 'data' => [
        'events' => [['id' => 'ev_t1', 'title' => 'T', 'status' => 'Upcoming', 'start_datetime' => '2031-03-04T13:30:00.000Z',
            'end_datetime' => '2031-03-04 20:00', 'is_ticketed' => true, 'capacity' => '12abc', 'updatedAt' => 1790100000000, 'baseUpdatedAt' => 5]],
        'orders' => [['id' => 'or_t1', 'total' => 10, 'created' => '2031-02-01T10:00:00Z', 'createdAt' => 1, 'updatedAt' => 1790100000000]],
        'schedules' => [['id' => 'sc_t1', 'date' => '2031-03-04', 'start_time' => '20:00:00.000', 'updatedAt' => 1790100000000]],
    ]])->assertOk()->assertJsonPath('data.saved', true)
        ->assertJsonPath('data.jumlah', ['events' => 1, 'schedules' => 1, 'orders' => 1]);

    $ev = evDb()->selectOne("SELECT * FROM events WHERE id='ev_t1'");
    expect($ev->start_datetime)->toBe('2031-03-04 20:30:00')        // ISO Z -> WIB
        ->and($ev->end_datetime)->toBe('2031-03-05 03:00:00')         // zone-less read as UTC, like legacy strtotime
        ->and((int) $ev->capacity)->toBe(12)->and((int) $ev->is_ticketed)->toBe(1)
        ->and(json_decode($ev->data, true)['baseUpdatedAt'])->toBe(5);  // stored verbatim
    expect((int) evDb()->selectOne("SELECT created_at FROM orders WHERE id='or_t1'")->created_at)->toBe(1927706400000)
        ->and(evDb()->selectOne("SELECT start_time FROM schedules WHERE id='sc_t1'")->start_time)->toBe('20:00:00');

    // an older copy never overwrites
    evPost(['action' => 'saveAll', 'data' => ['events' => [['id' => 'ev_t1', 'title' => 'OLD', 'updatedAt' => 1]]]])->assertOk();
    expect(evDb()->selectOne("SELECT title FROM events WHERE id='ev_t1'")->title)->toBe('T');
});

it('bounds deletes by the newest stamp in the payload, and deletes unbounded without stamps', function () {
    $db = evDb();
    $db->delete('DELETE FROM refunds');
    $db->insert("INSERT INTO refunds (id, status, updated_at, data) VALUES ('r_old','x',100,'{}'), ('r_new','x',900,'{}')");

    evPost(['action' => 'saveAll', 'data' => ['refunds' => [['id' => 'r_me', 'updatedAt' => 500]]]])->assertOk();
    expect(array_column($db->select('SELECT id FROM refunds ORDER BY id'), 'id'))->toBe(['r_me', 'r_new']);

    evPost(['action' => 'saveAll', 'data' => ['refunds' => [['id' => 'r_only']]]])->assertOk();
    expect(array_column($db->select('SELECT id FROM refunds'), 'id'))->toBe(['r_only']);

    evPost(['action' => 'saveAll', 'data' => ['refunds' => []]])->assertOk();
    expect(array_column($db->select('SELECT id FROM refunds'), 'id'))->toBe(['r_only']);
});

it('keeps checkins append-only and maps eventDetails by event id', function () {
    evPost(['action' => 'saveAll', 'data' => [
        'checkins' => [['id' => 'ci_t1', 'ticket_id' => 'tk1', 'checked_in_at' => '2031-03-04T13:45:00Z', 'result' => 'Valid'], ['noid' => 1]],
        'eventDetails' => ['ev_t1' => ['rundown' => [], 'updatedAt' => 10], 'x' => 'skip'],
    ]])->assertJsonPath('data.jumlah', ['checkins' => 1, 'eventDetails' => 1]);
    evPost(['action' => 'saveAll', 'data' => ['checkins' => [['id' => 'ci_t1', 'result' => 'Invalid']]]])->assertOk();

    $ci = evDb()->selectOne("SELECT * FROM checkins WHERE id='ci_t1'");
    expect($ci->result)->toBe('Valid')->and($ci->checked_in_at)->toBe('2031-03-04 20:45:00')
        ->and(array_column(evDb()->select('SELECT event_id FROM event_details'), 'event_id'))->toBe(['ev_t1']);
});

it('writes only the settings that are sent and not null', function () {
    evPost(['action' => 'saveAll', 'data' => ['role' => null, 'layoutTemplates' => [['id' => 'l1']]]])->assertOk();
    expect(evDb()->selectOne("SELECT v FROM settings WHERE k='layoutTemplates'")->v)->toBe('[{"id":"l1"}]')
        ->and(evDb()->selectOne("SELECT v FROM settings WHERE k='role'")->v)->toBe('"Director"');
});

it('rejects a bad payload with the legacy message', function () {
    evPost(['action' => 'saveAll'])->assertExactJson(['ok' => false, 'error' => 'Payload data kosong/invalid']);
    evPost(['action' => 'saveAll', 'data' => 'x'])->assertExactJson(['ok' => false, 'error' => 'Payload data kosong/invalid']);
});

it('refuses a saveAll whose ticket qr_token already belongs to another ticket (#100)', function () {
    $t = evDb()->selectOne('SELECT '.EventSchema::idCol().' AS id, qr_token, updated_at FROM '.EventSchema::table('tickets').' WHERE qr_token IS NOT NULL LIMIT 1');

    // the whole save is refused, nothing is written: no new row and the other
    // ticket keeps its token (legacy silently rewrote, then bounded-deleted it)
    evPost(['action' => 'saveAll', 'data' => ['tickets' => [['id' => 'tk_dupe', 'qr_token' => $t->qr_token, 'status' => 'x', 'updatedAt' => (int) $t->updated_at + 1]]]])
        ->assertExactJson(['ok' => false, 'error' => 'qr_token ganda: '.$t->qr_token]);
    expect(evDb()->selectOne('SELECT '.EventSchema::idCol().' AS id FROM '.EventSchema::table('tickets').' WHERE '.EventSchema::idCol().' = ?', ['tk_dupe']))->toBeNull()
        ->and(evDb()->selectOne('SELECT '.EventSchema::idCol().' AS id FROM '.EventSchema::table('tickets').' WHERE qr_token = ?', [$t->qr_token])->id)->toBe($t->id);

    // its own token is not a conflict: the ticket saves normally
    evPost(['action' => 'saveAll', 'data' => ['tickets' => [['id' => $t->id, 'qr_token' => $t->qr_token, 'status' => 'reuse-ok', 'updatedAt' => (int) $t->updated_at + 2]]]])
        ->assertJsonPath('data.saved', true);
    expect(evDb()->selectOne('SELECT status FROM '.EventSchema::table('tickets').' WHERE '.EventSchema::idCol().' = ?', [$t->id])->status)->toBe('reuse-ok');
});

it('answers eventsHari with the drop-list filter and createdBy from the blob', function () {
    evPost(['action' => 'saveAll', 'data' => ['events' => [
        ['id' => 'eh1', 'title' => 'B', 'status' => 'Finished', 'start_datetime' => '2031-06-01T12:00:00Z', 'createdBy' => 'Andi', 'createdById' => 'u-andi', 'updatedAt' => 1790100000000],
        ['id' => 'eh2', 'title' => 'A', 'status' => 'Planning', 'start_datetime' => '2031-06-01T12:00:00Z', 'updatedAt' => 1790100000000],
    ]]])->assertOk();

    $this->get('/event-api-mysql/api.php?action=eventsHari&tgl=2031-06-01')->assertExactJson(['ok' => true, 'data' => ['events' => [
        ['id' => 'eh1', 'nama' => 'B', 'status' => 'Finished', 'venue' => null, 'picName' => null,
            'inputOleh' => 'Andi', 'inputOlehId' => 'u-andi', 'mulai' => '2031-06-01 19:00:00'],
    ]]]);
    $this->get('/event-api-mysql/api.php?action=eventsHari&tgl=1-6-2031')->assertExactJson(['ok' => false, 'error' => 'tanggal tidak sah: 1-6-2031']);
});

it('uploads images/PDF as ev_<hex> files and serves them back; no other types', function () {
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $d = evPost(['action' => 'upload', 'dataBase64' => 'data:image/png;base64,'.$png, 'mimeType' => 'image/png', 'fileName' => 'ktp.png'])
        ->assertOk()->json('data');
    expect($d['key'])->toMatch('/^ev_[0-9a-f]{16}\.png$/')->and($d['name'])->toBe('ktp.png')->and($d['size'])->toBe(70);
    expect(is_file(storage_path('framework/testing/event-db/files/'.$d['key'])))->toBeTrue();

    $this->get('/event-api-mysql/api.php?action=file&key='.$d['key'])->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/event-api-mysql/api.php?action=file&key=../x')->assertStatus(400);
    $this->get('/event-api-mysql/api.php?action=file&key=ev_nope.png')->assertStatus(404);

    evPost(['action' => 'upload'])->assertExactJson(['ok' => false, 'error' => 'file kosong']);
    evPost(['action' => 'upload', 'dataBase64' => 'aGk=', 'fileName' => 'x.exe'])->assertJsonPath('error', 'hanya gambar (jpg/png/webp/gif) atau PDF');
    evPost(['action' => 'upload', 'dataBase64' => '@@', 'mimeType' => 'application/pdf'])->assertJsonPath('error', 'base64 tidak valid');
});

it('stores an array in a string column as "Array", like the legacy (string) cast', function () {
    evPost(['action' => 'saveAll', 'data' => ['talents' => [['id' => 'tl_arr', 'phone' => ['1', '2'], 'updatedAt' => 1]]]])->assertOk();
    expect(evDb()->selectOne("SELECT phone FROM talents WHERE id='tl_arr'")->phone)->toBe('Array')
        ->and(RowSync::ambil(['t' => ['x']], 't', 'time'))->toBe('Array');
});

it('leaves sql_mode to the server, so a non-strict production truncates an over-long phone like legacy', function () {
    expect(config('database.connections.legacy_ems.strict'))->toBeNull();

    evDb()->statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'"); // production-like
    evPost(['action' => 'saveAll', 'data' => ['talents' => [['id' => 'tl_long', 'phone' => str_repeat('9', 40), 'updatedAt' => 1]]]])
        ->assertJsonPath('ok', true);
    expect(evDb()->selectOne("SELECT phone FROM talents WHERE id='tl_long'")->phone)->toBe(str_repeat('9', 32));
});

it('answers stats with the per-table counts and the blob size', function () {
    $d = $this->get('/event-api-mysql/api.php?action=stats')->assertOk()->json('data');
    expect($d['seats'])->toBe((int) evDb()->selectOne('SELECT COUNT(*) c FROM seats')->c)
        ->and($d['blobChars'])->toBeGreaterThan(1000)->and($d)->toHaveKeys(['env', 'db', 'versi', 'blobMB', 'ts']);
});
