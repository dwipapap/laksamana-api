<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

require_once __DIR__.'/helpers.php';

/*
 * G-08 (#183): server paging, filters and CSV export for the reservation and
 * audit tables — twins of recapList()/applyFilter()/auditCocok()/exportCSV().
 * Seeds live in 2031 so the restored dump never leaks into the windows.
 */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function brUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

function brToken(array $u): string
{
    // The guard caches its user: drop it AFTER loginAs (whose own request
    // runs with this test's previous bearer), or the next call still
    // answers as whoever made the previous request.
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}

function brSeed(string $token): void
{
    $rows = [
        ['name' => 'And183', 'phone' => '0811-1111', 'date' => '2031-05-03', 'time' => '19:00',
            'table' => 'A1', 'status' => 'Pending', 'pax' => 2, 'createdAt' => 1000],
        ['name' => 'Bud183', 'phone' => '0822-2222', 'date' => '2031-05-01', 'time' => '20:00',
            'table' => 'B2', 'status' => 'Checked-in', 'pax' => 4, 'member' => 1, 'memberNo' => 'M-9', 'createdAt' => 2000],
        ['name' => 'Cit183', 'phone' => '0833-3333', 'date' => '2031-05-02', 'time' => '18:00',
            'table' => 'A1', 'status' => 'Cancelled', 'pax' => 3, 'createdAt' => 3000],
        ['name' => 'Dew183', 'phone' => '0844-4444', 'date' => '2031-05-01', 'time' => '19:00',
            'table' => 'C3', 'status' => 'Booking', 'pax' => 5, 'dpStatus' => 'Sudah', 'dpAmount' => 100000,
            'dps' => [['method' => 'QRIS BRI', 'amount' => 100000]], 'createdAt' => 4000],
    ];
    foreach ($rows as $row) {
        test()->withToken($token)->postJson('/api/v1/reservasi/reservations', $row)->assertCreated();
    }
}

function brWindow(): string
{
    return 'from=2031-05-01&to=2031-05-03';
}

it('G-08: without the new params the list is exactly what it always was', function () {
    $token = brToken(officeUser('u-andry'));
    brSeed($token);

    $res = $this->withToken($token)->getJson('/api/v1/reservasi/reservations?'.brWindow())->assertOk();
    // Every row, Cancelled included, in created_at order — no paging keys.
    expect(array_column($res->json('data'), 'name'))->toBe(['And183', 'Bud183', 'Cit183', 'Dew183'])
        ->and($res->json('meta.total'))->toBe(4)
        ->and($res->json('meta'))->not->toHaveKeys(['page', 'perPage', 'batal']);
});

it('G-08: q matches name, phone and table number, case-insensitively', function () {
    $token = brToken(officeUser('u-andry'));
    brSeed($token);
    $names = fn (string $q) => array_column(
        $this->withToken($token)->getJson('/api/v1/reservasi/reservations?'.brWindow()."&q=$q")->assertOk()->json('data'), 'name');

    expect($names('bud183'))->toBe(['Bud183'])
        ->and($names('0822'))->toBe(['Bud183'])
        ->and($names('DEW183'))->toBe(['Dew183'])
        ->and($names('a1'))->toBe(['And183', 'Cit183'])
        ->and($names('tak-ada-183'))->toBe([]);
});

it('G-08: status compares the normalised status and counts the dropped Cancelled rows', function () {
    $token = brToken(officeUser('u-andry'));
    brSeed($token);
    $get = fn (string $status) => $this->withToken($token)
        ->getJson('/api/v1/reservasi/reservations?'.brWindow()."&status=$status")->assertOk();

    // Checked-in counts as Datang; the Cancelled row drops out and is counted.
    $datang = $get('Datang');
    expect(array_column($datang->json('data'), 'name'))->toBe(['Bud183'])
        ->and($datang->json('meta.total'))->toBe(1)
        ->and($datang->json('meta.batal'))->toBe(1);

    $pending = $get('Pending');
    expect(array_column($pending->json('data'), 'name'))->toBe(['And183'])
        ->and($pending->json('meta.batal'))->toBe(1);

    // Asking for Cancelled returns just it, with nothing dropped.
    $batal = $get('Cancelled');
    expect(array_column($batal->json('data'), 'name'))->toBe(['Cit183'])
        ->and($batal->json('meta.batal'))->toBe(0);
});

it('G-08: page cuts the matches in Recap display order with meta.total counting all', function () {
    $token = brToken(officeUser('u-andry'));
    brSeed($token);

    // Display order is date+time: Dew Bud Cit And (created_at order differs).
    $p1 = $this->withToken($token)->getJson('/api/v1/reservasi/reservations?'.brWindow().'&page=1&perPage=2')->assertOk();
    expect(array_column($p1->json('data'), 'name'))->toBe(['Dew183', 'Bud183'])
        ->and($p1->json('meta'))->toMatchArray(['total' => 4, 'page' => 1, 'perPage' => 2]);

    $p2 = $this->withToken($token)->getJson('/api/v1/reservasi/reservations?'.brWindow().'&page=2&perPage=2')->assertOk();
    expect(array_column($p2->json('data'), 'name'))->toBe(['Cit183', 'And183']);

    // A page past the end clamps to the last page (twin of rsv_potong).
    $far = $this->withToken($token)->getJson('/api/v1/reservasi/reservations?'.brWindow().'&page=99&perPage=2')->assertOk();
    expect($far->json('meta.page'))->toBe(2)
        ->and(array_column($far->json('data'), 'name'))->toBe(['Cit183', 'And183']);

    $this->withToken($token)->getJson('/api/v1/reservasi/reservations?'.brWindow().'&perPage=500')->assertStatus(422);
});

it('G-08: audit pages and searches text, unchanged without the new params', function () {
    $token = brToken(officeUser('u-andry'));
    $this->withToken($token)->postJson('/api/v1/reservasi/audit', ['action' => 'Uji183 Verifikasi', 'detail' => 'Uji183 lunas'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/reservasi/audit', ['action' => 'Uji183 Pindah', 'detail' => 'ganti meja'])->assertCreated();

    $plain = $this->withToken($token)->getJson('/api/v1/reservasi/audit')->assertOk();
    expect($plain->json())->not->toHaveKey('meta');

    $found = $this->withToken($token)->getJson('/api/v1/reservasi/audit?q=uji183')->assertOk();
    expect($found->json('meta.total'))->toBe(2)
        ->and($found->json())->not->toHaveKey('meta.page');

    $one = $this->withToken($token)->getJson('/api/v1/reservasi/audit?q=lunas')->assertOk();
    expect($one->json('meta.total'))->toBe(1)
        ->and($one->json('data.0.action'))->toBe('Uji183 Verifikasi');

    $paged = $this->withToken($token)->getJson('/api/v1/reservasi/audit?q=uji183&page=2&perPage=1')->assertOk();
    expect($paged->json('meta'))->toMatchArray(['total' => 2, 'page' => 2, 'perPage' => 1])
        ->and($paged->json('data.0.action'))->toBe('Uji183 Verifikasi');
});

it('G-08: export answers the Recap CSV for the same filter', function () {
    $token = brToken(officeUser('u-andry'));
    brSeed($token);

    $res = $this->withToken($token)->getJson('/api/v1/reservasi/reservations/export?'.brWindow());
    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toStartWith('text/csv');
    $body = $res->getContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
    $lines = array_map(fn ($l) => str_getcsv($l), explode("\n", substr($body, 3)));

    expect($lines[0])->toBe(['Nama', 'No HP', 'Tanggal', 'Jam', 'Pax', 'Pax Aktual', 'Meja', 'Lantai', 'Status',
        'DP', 'Nominal DP', 'Metode DP', 'Rekening DP', 'Sumber', 'PIC', 'Member', 'No Member', 'Catatan', 'Diinput Oleh'])
        // Display order: Dew183 first; raw fields, digits-only phone, rekening from dps[].
        ->and($lines[1])->toBe(['Dew183', '08444444', '2031-05-01', '19:00', '5', '', 'C3', '', 'Booking',
            'Sudah', '100000', '', 'QRIS BRI', '', '', 'Tidak', '', '', ''])
        ->and(count($lines))->toBe(5);

    $batal = $this->withToken($token)->getJson('/api/v1/reservasi/reservations/export?'.brWindow().'&status=Cancelled')->assertOk();
    $rows = array_map(fn ($l) => str_getcsv($l), explode("\n", substr($batal->getContent(), 3)));
    expect(count($rows))->toBe(2)->and($rows[1][0])->toBe('Cit183');

    $none = $this->withToken($token)->getJson('/api/v1/reservasi/reservations/export?'.brWindow().'&q=tak-ada-183')->assertOk();
    expect(count(array_map(fn ($l) => str_getcsv($l), explode("\n", substr($none->getContent(), 3)))))->toBe(1);
});

it('G-08: export resolves Lantai from the master layouts, unknown stays empty', function () {
    $token = brToken(officeUser('u-andry'));
    foreach ([
        ['name' => 'Lan183', 'phone' => '0855-5555', 'date' => '2031-06-01', 'time' => '19:00',
            'table' => 'Y7', 'status' => 'Pending', 'createdAt' => 1000],
        ['name' => 'Con183', 'phone' => '0856-6666', 'date' => '2031-06-01', 'time' => '20:00',
            'table' => 'Z9', 'status' => 'Pending', 'createdAt' => 2000],
    ] as $row) {
        test()->withToken($token)->postJson('/api/v1/reservasi/reservations', $row)->assertCreated();
    }
    $section = $this->withToken($token)->getJson('/api/v1/reservasi/master/layouts')->assertOk();
    $value = is_array($section->json('data')) ? $section->json('data') : [];
    // Z9 is claimed by two floors → unknown (twin of lantaiMap's conflict rule).
    $value['hall2'] = ['name' => 'Hall Lantai 2', 'tables' => [['id' => 'Y7'], ['id' => 'Z9']]];
    $value['ruang1'] = ['name' => 'Ruang 1', 'tables' => [['id' => 'Z9']]];
    $this->withToken($token)->putJson('/api/v1/reservasi/master/layouts?version='.$section->json('meta.version'), ['value' => $value])->assertOk();

    $body = $this->withToken($token)->getJson('/api/v1/reservasi/reservations/export?from=2031-06-01&to=2031-06-01')->assertOk()->getContent();
    $lines = array_map(fn ($l) => str_getcsv($l), explode("\n", substr($body, 3)));
    expect($lines[1][0])->toBe('Lan183')->and($lines[1][7])->toBe('2')
        ->and($lines[2][0])->toBe('Con183')->and($lines[2][7])->toBe('');
});

it('G-08: the new reads keep the reservation gates', function () {
    $token = brToken(officeUser('u-andry'));
    brSeed($token);

    // Service Excellent holders read the list, the audit and the export…
    $se = brToken(officeUser('u-ernimianiangelapurba'));
    $this->withToken($se)->getJson('/api/v1/reservasi/reservations?'.brWindow().'&page=1')->assertOk();
    $this->withToken($se)->getJson('/api/v1/reservasi/audit?q=x')->assertOk();
    $this->withToken($se)->getJson('/api/v1/reservasi/reservations/export?'.brWindow())->assertOk();
    // …a holder of neither is refused everywhere.
    $none = brToken(brUser('u-br-none', ['hr']));
    $this->withToken($none)->getJson('/api/v1/reservasi/reservations?'.brWindow().'&page=1')->assertStatus(403);
    $this->withToken($none)->getJson('/api/v1/reservasi/audit?q=x')->assertStatus(403);
    $this->withToken($none)->getJson('/api/v1/reservasi/reservations/export?'.brWindow())->assertStatus(403);
});
