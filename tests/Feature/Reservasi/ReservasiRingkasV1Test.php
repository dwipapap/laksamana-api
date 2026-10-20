<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

require_once __DIR__.'/helpers.php';

/*
 * api#311: compact reads for Office Vue — analytics, dps and the VIP filter —
 * so Analitik, KompasBri and the VIP sweep stop pulling the whole table.
 * Fixtures live on 2031-01-0x (the restored DB holds nothing there), with a
 * source/marker unique to this file, so real rows never disturb the numbers.
 */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function rkToken(array $u): string
{
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}

function rkUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

function rkSched(string $date, string $time): int
{
    return (new DateTimeImmutable("{$date}T{$time}:00", new DateTimeZone('Asia/Jakarta')))->getTimestamp();
}

function rkSeed(string $token): void
{
    $sched = rkSched('2031-01-05', '19:00');
    $rows = [
        // Datang, 5 minutes late (tepat), seated 60 minutes.
        ['name' => 'T311 A', 'phone' => '0831-100-0001', 'date' => '2031-01-05', 'time' => '19:00',
            'status' => 'Datang', 'pax' => 4, 'source' => 'UJI-311', 'picName' => 'Budi',
            'checkinAt' => ($sched + 300) * 1000, 'leftAt' => ($sched + 3900) * 1000,
            'actualPax' => 4, 'createdAt' => 1000],
        // Datang, 30 minutes late (telat), still seated.
        ['name' => 'T311 B', 'phone' => '0831-100-0002', 'date' => '2031-01-05', 'time' => '19:00',
            'status' => 'Datang', 'pax' => 2, 'source' => 'UJI-311', 'picName' => 'Budi',
            'checkinAt' => ($sched + 1800) * 1000, 'actualPax' => 2, 'createdAt' => 2000],
        ['name' => 'T311 C', 'phone' => '0831-100-0003', 'date' => '2031-01-05', 'time' => '19:00',
            'status' => 'No-show', 'pax' => 2, 'source' => 'UJI-311', 'picType' => 'Marketing', 'createdAt' => 3000],
        ['name' => 'T311 D', 'phone' => '0831-100-0004', 'date' => '2031-01-05', 'time' => '19:00',
            'status' => 'Cancelled', 'pax' => 2, 'source' => 'UJI-311', 'createdAt' => 4000],
        ['name' => 'T311 E', 'phone' => '0831-100-0005', 'date' => '2031-01-06', 'time' => '12:00',
            'status' => 'Pending', 'pax' => 1, 'source' => 'UJI-311', 'picName' => 'Ani', 'createdAt' => 5000],
        // Walk-in on the same day: counted apart, never as reservasi.
        ['name' => 'T311 F', 'phone' => '0831-100-0006', 'date' => '2031-01-05', 'time' => '19:00',
            'status' => 'Datang', 'pax' => 5, 'source' => 'Walk-in', 'createdAt' => 6000],
        ['id' => 'vip-t311-x1', 'name' => 'T311 VIP', 'phone' => '0831-100-0007', 'date' => '2031-01-05',
            'time' => '19:00', 'status' => 'Confirmed', 'pax' => 2, 'source' => 'UJI-311',
            'picName' => 'Budi', 'vip' => true, 'createdAt' => 7000],
    ];
    foreach ($rows as $row) {
        test()->withToken($token)->postJson('/api/v1/reservasi/reservations', $row)->assertCreated();
    }
    // One reservation carrying three instalments: in-range, dateless, out-of-range.
    test()->withToken($token)->postJson('/api/v1/reservasi/reservations', [
        'name' => 'T311 G', 'phone' => '0831-100-0008', 'date' => '2031-01-05', 'time' => '19:00',
        'status' => 'Confirmed', 'pax' => 2, 'source' => 'UJI-311', 'createdAt' => 8000,
        'dps' => [
            ['id' => 't311-dp1', 'amount' => 100000, 'tfAmount' => 100000, 'tfDate' => '2031-01-10',
                'tfTime' => '10:00', 'tfBank' => 'BRI', 'method' => 'QRIS', 'tfStatus' => 'verified'],
            ['id' => 't311-dp2', 'amount' => 50000, 'tfDate' => '', 'method' => 'Cash'],
            ['id' => 't311-dp3', 'amount' => 75000, 'tfDate' => '2030-12-20', 'method' => 'Transfer'],
        ],
    ])->assertCreated();
    test()->withToken($token)->postJson('/api/v1/reservasi/reservations', [
        'name' => 'T311 H', 'phone' => '0831-100-0009', 'date' => '2030-12-10', 'time' => '19:00',
        'status' => 'Confirmed', 'pax' => 2, 'source' => 'UJI-311', 'createdAt' => 9000,
        'dps' => [
            ['id' => 't311-dp4', 'amount' => 25000, 'tfDate' => '2030-12-11', 'method' => 'QRIS'],
        ],
    ])->assertCreated();
}

it('311: analytics harian mirrors performaPeriode over the day', function () {
    $token = rkToken(officeUser('u-andry'));
    rkSeed($token);

    $res = $this->withToken($token)
        ->getJson('/api/v1/reservasi/analytics?mode=harian&date=2031-01-05')->assertOk();
    $p = $res->json('data.periode');

    expect($p['label'])->toBe('2031-01-05')
        ->and($p['reservasi'])->toBe(6) // A–D + VIP + G (F is Walk-in)
        ->and($p['walkin'])->toBe(1)
        ->and($p['hariAktif'])->toBe(1)
        ->and($p['hadir'])->toBe(2)
        ->and($p['diharapkan'])->toBe(3)
        ->and($p['kehadiran'])->toBe(67)
        ->and($p['tepat'])->toBe(1)
        ->and($p['telat'])->toBe(1)
        ->and($p['telatRata'])->toBe(30)
        ->and($p['tepatPersen'])->toBe(50)
        ->and($p['durasiRata'])->toBe(60)
        ->and($p['durasiN'])->toBe(1)
        ->and($p['masihDuduk'])->toBe(1)
        ->and($p['paxPesan'])->toBe(11) // datang: A4 + B2 + F5
        ->and($p['paxDatang'])->toBe(11)
        ->and($p['paxRealisasi'])->toBe(100)
        ->and($p['noShow'])->toBe(1)
        ->and($p['batal'])->toBe(1)
        ->and($p['tersibuk'])->toBeNull()
        ->and($p['trenHarian'])->toBe([])
        ->and(array_column($p['palingTelat'], 'telat'))->toBe([30])
        ->and(array_column($p['dudukTerlama'], 'durasi'))->toBe([60]);

    // The whole-range summary still sees the seeded month and source.
    $ring = $res->json('data.ringkasan');
    $bulan = collect($ring['perBulan'])->firstWhere('bulan', '2031-01');
    $sumber = collect($ring['perSumber'])->firstWhere('label', 'UJI-311');
    expect($bulan['n'])->toBe(8)->and($sumber['n'])->toBe(8);

    $this->withToken($token)->getJson('/api/v1/reservasi/analytics?mode=bulanan&date=2031-01')->assertOk()
        ->assertJsonPath('data.periode.reservasi', 7) // all seeded but the Walk-in
        ->assertJsonPath('data.periode.hariAktif', 2); // 01-05 and 01-06
    $this->withToken($token)->getJson('/api/v1/reservasi/analytics?mode=seminggu&date=2031-01-05')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/reservasi/analytics?mode=harian&date=2031-13-99')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/reservasi/analytics?mode=bulanan&date=2031-13')->assertStatus(422);
});

it('311: dps filters by transfer or reservation date like dpsBulan', function () {
    $token = rkToken(officeUser('u-andry'));
    rkSeed($token);

    $res = $this->withToken($token)
        ->getJson('/api/v1/reservasi/dps?tfFrom=2031-01-01&tfTo=2031-01-31')->assertOk();
    $ids = array_column($res->json('data'), 'dpId');

    // t311-dp1 (transfer in range), t311-dp2 (no transfer date, reservation
    // in range), t311-dp3 (transfer out, reservation in range) stay…
    expect($ids)->toContain('t311-dp1', 't311-dp2', 't311-dp3')
        // …while t311-dp4 (both dates out of range) drops out.
        ->and($ids)->not->toContain('t311-dp4');

    $dp1 = collect($res->json('data'))->firstWhere('dpId', 't311-dp1');
    expect($dp1)->toMatchArray([
        'nama' => 'T311 G', 'resTgl' => '2031-01-05', 'tfTgl' => '2031-01-10',
        'tfJam' => '10:00', 'bank' => 'BRI', 'metode' => 'QRIS', 'status' => 'verified',
        'nominal' => 100000, 'diketik' => 100000,
    ]);

    // The Dana Masuk door reads it too (KompasBri lives in Cashier/Finance)…
    $this->withToken(rkToken(rkUser('u-rk-cashier', ['cashier'])))
        ->getJson('/api/v1/reservasi/dps?tfFrom=2031-01-01&tfTo=2031-01-31')->assertOk();
    // …but a holder of neither is refused, like GET /reservations.
    $this->withToken(rkToken(rkUser('u-rk-none', ['hr'])))
        ->getJson('/api/v1/reservasi/dps?tfFrom=2031-01-01&tfTo=2031-01-31')->assertStatus(403);
});

it('311: vip and idPrefix narrow the reservation list', function () {
    $token = rkToken(officeUser('u-andry'));
    rkSeed($token);

    $vip = $this->withToken($token)->getJson('/api/v1/reservasi/reservations?vip=1')->assertOk()->json('data');
    $ids = array_column($vip, 'id');
    expect($ids)->toContain('vip-t311-x1');
    foreach ($vip as $r) {
        expect(! empty($r['vip']))->toBeTrue();
    }

    $pre = $this->withToken($token)->getJson('/api/v1/reservasi/reservations?idPrefix=vip-')->assertOk()->json('data');
    expect(array_column($pre, 'id'))->toContain('vip-t311-x1');
    foreach ($pre as $r) {
        expect(str_starts_with((string) $r['id'], 'vip-'))->toBeTrue();
    }

    // Combined, the Marketing orphan sweep is one call, not a 2020–2100 walk.
    $both = $this->withToken($token)
        ->getJson('/api/v1/reservasi/reservations?vip=1&idPrefix=vip-')->assertOk()->json('data');
    expect(array_column($both, 'id'))->toContain('vip-t311-x1');
});
