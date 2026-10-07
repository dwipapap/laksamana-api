<?php

use App\Auth\AccountRepository;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Event/helpers.php';
require_once __DIR__.'/../Marketing/helpers.php';
require_once __DIR__.'/../Reservasi/helpers.php';

/* The automation feeds read the module DBs locally (AUTOMATION_DB_HOST empty,
 * see ReadDb). Tokens carry exactly `automation:read`; the staff `*` tokens
 * must be refused. Dates are far in the future. */

beforeEach(function () {
    config(['laksamana.modules.event.data_dir' => storage_path('framework/testing/event-db')]);
    config(['laksamana.modules.marketing.data_dir' => storage_path('framework/testing/marketing-db')]);
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

/** A Sanctum token with ONLY the automation ability, like `automation:token`. */
function auto234Token(string $userId): string
{
    return app(AccountRepository::class)->tokenOwner($userId)
        ->createToken('n8n-test', ['automation:read'], null)->plainTextToken;
}

function auto234SeedReservasi(string $date, array $rows): void
{
    $db = Modules::db('reservasi');
    foreach ($rows as $i => $r) {
        $id = 'auto-rs-'.$i.'-'.str_replace('-', '', $date);
        $row = array_merge([
            'id' => $id, 'name' => 'Tamu', 'phone' => '081234567890',
            'date' => $date, 'time' => '19:00', 'pax' => 2, 'status' => 'Confirmed',
            'picName' => '', 'source' => 'WA', 'dpStatus' => '', 'dpAmount' => 0,
            'table' => 'R1', 'dps' => [], 'createdAt' => 1, 'updatedAt' => 1,
        ], $r, ['id' => $id, 'date' => $date]);
        $db->insert(
            'INSERT INTO reservations (id,name,phone,tanggal,jam,pax,status,pic_name,source,dp_amount,updated_at,created_at,data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$row['id'], $row['name'], $row['phone'], $row['date'], $row['time'], $row['pax'],
                $row['status'], $row['picName'], $row['source'], (int) $row['dpAmount'],
                1000 + $i, 1000 + $i, json_encode($row, JSON_UNESCAPED_UNICODE)]
        );
    }
}

function auto234SeedEvent(string $id, string $title, string $start, string $status = 'Upcoming', string $venue = 'Hall A', string $pic = 'PIC Event'): void
{
    Modules::db('event')->insert(
        evSql('INSERT INTO events (id,title,status,venue,pic,start_datetime,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)'),
        [$id, $title, $status, $venue, $pic, $start, json_encode(['id' => $id], JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

function auto234SeedMarketing(string $id, string $nama, string $tanggal, string $status = 'Deal', int $pax = 50, array $data = []): void
{
    Modules::db('marketing')->insert(
        mktSql('INSERT INTO events (id,nama,tanggal,status,pax,mkt_pic,data,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)'),
        [$id, $nama, $tanggal, $status, $pax, '', json_encode(['id' => $id] + $data, JSON_UNESCAPED_UNICODE), 1000, 1000]
    );
}

/** VIP lives in the legacy `settings` JSON (`extra:vip`), the layout the feed reads. */
function auto234SeedVip(array $vip): void
{
    Modules::db('marketing')->insert(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        ['extra:vip', json_encode($vip, JSON_UNESCAPED_UNICODE)]
    );
}

it('reservasi-harian: one day, aggregates + rows, no phones or photo blobs', function () {
    auto234SeedReservasi('2031-05-01', [
        ['name' => 'Wina Polresta', 'time' => '15:00', 'table' => 'VIP 1, VIP 2', 'pax' => 25, 'dpStatus' => 'Sudah', 'dpAmount' => 500000],
        ['name' => 'Joshua', 'time' => '19:00', 'table' => 'M15, M14', 'pax' => 4, 'status' => 'Pending'],
        ['name' => 'Batal Guy', 'status' => 'Cancelled'],
    ]);
    $token = auto234Token('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/reservasi-harian?date=2031-05-01')->assertOk();

    expect($res->json('meta.feed'))->toBe('reservasi-harian')
        ->and($res->json('meta.date'))->toBe('2031-05-01')
        ->and($res->json('meta.generatedAt'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/')
        ->and($res->json('meta.errors'))->toBe([]);

    $days = $res->json('data');
    expect($days)->toHaveCount(1)
        ->and($days[0]['date'])->toBe('2031-05-01')
        ->and($days[0]['total'])->toMatchArray(['reservasi' => 2, 'pax' => 29, 'batal' => 1, 'dpMasuk' => 500000]);
    $rows = $days[0]['rows'];
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Wina Polresta')
        ->and($rows[0]['tables'])->toBe('VIP 1, VIP 2')
        ->and($rows[0])->not->toHaveKey('table')
        ->and($rows[0]['dpAmount'])->toBe(500000)
        ->and($rows[1]['name'])->toBe('Joshua');
    $body = $res->getContent();
    expect($body)->not->toContain('081234567890')->not->toContain('Batal Guy');
});

it('reservasi-harian: days=2 returns three days in order, errors stay an object', function () {
    auto234SeedReservasi('2031-06-01', [['name' => 'H0']]);
    auto234SeedReservasi('2031-06-02', [['name' => 'H1'], ['name' => 'H1b']]);
    $token = auto234Token('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/reservasi-harian?date=2031-06-01&days=2')->assertOk();

    $days = $res->json('data');
    expect($days)->toHaveCount(3)
        ->and($days[0]['date'])->toBe('2031-06-01')
        ->and($days[1]['date'])->toBe('2031-06-02')
        ->and($days[2]['date'])->toBe('2031-06-03')
        ->and($days[0]['total']['reservasi'])->toBe(1)
        ->and($days[1]['total']['reservasi'])->toBe(2)
        ->and($days[2]['total']['reservasi'])->toBe(0);
    expect(json_decode($res->getContent())->meta->errors)->toBeObject();
});

it('reservasi-harian: rejects a bad date and days outside 0..7', function () {
    $token = auto234Token('u-andry');
    $this->withToken($token)->getJson('/api/v1/automation/reservasi-harian?date=besok')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/automation/reservasi-harian?days=99')->assertStatus(422);
});

it('info-pagi: event + multi-day marketing + VIP, no phones', function () {
    auto234SeedEvent('auto-ev1', 'Konser Pagi', '2031-08-01 07:00:00');
    auto234SeedEvent('auto-ev-draft', 'Draft Malam', '2031-08-01 20:00:00', 'Draft');
    auto234SeedMarketing('auto-mk1', 'Wedding Budi', '2031-08-01', 'Deal', 50, [
        'tanggalSelesai' => '2031-08-03', 'mktPICName' => 'PIC Mkt', 'menuFix' => 'Menu A',
    ]);
    auto234SeedVip([
        ['tanggal' => '2031-08-01', 'jenis' => 'Assisted', 'nama' => 'Tamu VIP', 'perusahaan' => 'PT Contoh',
            'jamMulai' => '19:00', 'jamSelesai' => '21:00', 'paxMax' => 8, 'meja' => ['VIP 1'], 'nominal' => 150000, 'mktPICName' => 'PIC Mkt'],
        ['tanggal' => '2031-08-01', 'jenis' => 'Assisted', 'nama' => 'Batal VIP', 'batalAt' => 1],
        ['tanggal' => '2031-08-01', 'jenis' => 'Walk In', 'nama' => 'Bukan VIP'],
    ]);

    $token = auto234Token('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/info-pagi?date=2031-08-01')->assertOk();

    expect($res->json('meta.feed'))->toBe('info-pagi')
        ->and($res->json('meta.date'))->toBe('2031-08-01')
        ->and($res->json('meta.errors'))->toBe([]);

    $ev = $res->json('data.event');
    expect($ev)->toHaveCount(1)
        ->and($ev[0]['nama'])->toBe('Konser Pagi')
        ->and($ev[0]['venue'])->toBe('Hall A');
    $mk = $res->json('data.marketing.events');
    expect($mk)->toHaveCount(1)
        ->and($mk[0]['nama'])->toBe('Wedding Budi')
        ->and($mk[0]['hari'])->toBe(1)
        ->and($mk[0]['totalHari'])->toBe(3)
        ->and($mk[0]['picName'])->toBe('PIC Mkt');
    $vip = $res->json('data.marketing.vip');
    expect($vip)->toHaveCount(1)
        ->and($vip[0]['nama'])->toBe('Tamu VIP')
        ->and($vip[0]['perusahaan'])->toBe('PT Contoh')
        ->and($vip[0]['pax'])->toBe(8);
    $body = $res->getContent();
    expect($body)->not->toContain('0812')->not->toContain('Draft Malam');
});

it('info-pagi: a staff * token is forbidden', function () {
    $staff = loginAs(officeUser('u-andry'));
    $this->withToken($staff)->getJson('/api/v1/automation/info-pagi?date=2031-08-01')
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('info-pagi: no token is 401', function () {
    $this->getJson('/api/v1/automation/info-pagi?date=2031-08-01')->assertStatus(401);
});

it('info-pagi: reads through automation_ro when pinned', function () {
    // Point automation_ro at the same server the test connections use. Rows
    // seeded inside test transactions are invisible here, so this proves the
    // pinned path connects and every section answers instead of failing.
    config([
        'database.connections.automation_ro.host' => config('database.connections.core.host'),
        'database.connections.automation_ro.port' => config('database.connections.core.port'),
        'database.connections.automation_ro.username' => config('database.connections.core.username'),
        'database.connections.automation_ro.password' => config('database.connections.core.password'),
    ]);

    $token = auto234Token('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/info-pagi?date=2031-12-31')->assertOk();

    expect($res->json('meta.errors'))->toBe([])
        ->and($res->json('meta.feed'))->toBe('info-pagi')
        ->and($res->json('data.event'))->toBeArray()
        ->and($res->json('data.marketing.events'))->toBeArray();
});

it('info-pagi: a failed section stays 200 and is reported in meta.errors', function () {
    auto234SeedEvent('auto-ev-err', 'Acara Sehat', '2031-08-02 09:00:00');
    // Point marketing at an empty database: the section must fail, the feed
    // must still answer and say which section failed.
    config(['laksamana.modules.marketing.connection' => 'legacy_site']);

    $token = auto234Token('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/info-pagi?date=2031-08-02')->assertOk();

    expect($res->json('meta.errors'))->toHaveKey('marketing')
        ->and($res->json('data.event'))->toHaveCount(1)
        ->and($res->json('data.marketing.events'))->toBe([])
        ->and($res->json('data.marketing.vip'))->toBe([]);
});

it('reservasi-harian: a failing day is reported per date in meta.errors', function () {
    config(['laksamana.modules.reservasi.connection' => 'legacy_site']);

    $token = auto234Token('u-andry');
    $res = $this->withToken($token)->getJson('/api/v1/automation/reservasi-harian?date=2031-06-01&days=1')->assertOk();

    expect($res->json('data'))->toBe([])
        ->and($res->json('meta.errors'))->toHaveKeys(['2031-06-01', '2031-06-02']);
});

it('automation:token mints a no-expiry token with only automation:read', function () {
    $user = officeUser('u-andry');
    $this->artisan('automation:token', ['name' => 'n8n-234', '--user' => $user['name']])
        ->expectsOutputToContain('automation:read')
        ->assertSuccessful();

    $row = DB::connection('core')->table('personal_access_tokens')->where('name', 'n8n-234')->first();
    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->abilities, true))->toBe(['automation:read'])
        ->and($row->expires_at)->toBeNull();
});
