<?php

require_once __DIR__.'/helpers.php';

use App\Modules\Kompas\Services\LaporanOmset;

/* u-novi holds finance; u-adit holds no module. */

it('laporan-pdf needs login and one of kompas/finance/cashier', function () {
    $this->get('/api/v1/kompas/laporan-pdf')->assertStatus(401);
    $token = loginAs(officeUser('u-adit'));
    $this->withToken($token)->get('/api/v1/kompas/laporan-pdf')->assertStatus(403);
});

it('laporan-pdf returns a PDF for the latest day by default', function () {
    $token = loginAs(officeUser('u-novi'));
    $res = $this->withToken($token)->get('/api/v1/kompas/laporan-pdf')->assertOk();
    expect($res->headers->get('content-type'))->toStartWith('application/pdf');
    expect(substr($res->streamedContent() ?? $res->getContent(), 0, 5))->toBe('%PDF-');
});

it('laporan-pdf honours tanggal and 404s an empty day', function () {
    $token = loginAs(officeUser('u-novi'));
    $blob = kpBlob();
    $dates = array_column(array_filter($blob['daily'] ?? [], fn ($d) => is_array($d) && isset($d['date'])), 'date');
    $this->withToken($token)->get('/api/v1/kompas/laporan-pdf?tanggal=1999-01-01')->assertStatus(404);
    if ($dates !== []) {
        $t = max($dates);
        $this->withToken($token)->get('/api/v1/kompas/laporan-pdf?tanggal='.$t)->assertOk()
            ->assertHeader('content-disposition', fn ($v) => str_contains((string) $v, "Laporan-Omset-$t.pdf"));
    }
});

it('laporan-omset math matches the dashboard (net/tagihan/self)', function () {
    $r = LaporanOmset::ringkas([[
        'food' => 1000000, 'bev' => 500000, 'lainnya' => 0, 'discount' => 100000,
        'service_charge' => 75000, 'tax' => 150000, 'traffic' => 40, 'bill' => 20,
        'qty_food' => 30, 'qty_bev' => 15, 'qty_others' => 0,
        'bd' => ['self' => ['amount' => 200000, 'disc' => 0]],
    ]], [['nominal' => 50000]]);
    expect($r['net'])->toBe(1400000)
        ->and($r['tagihan'])->toBe(1625000)
        ->and($r['self'])->toBe(200000)
        ->and($r['kasir'])->toBe(1200000)
        ->and($r['comp'])->toBe(50000)
        ->and($r['svcOnly'])->toBe(75000)
        ->and($r['taxOnly'])->toBe(150000)
        ->and($r['upt'])->toBe(2.25);
});

it('laporan-omset build menandai AI Agent dan waktu cetak', function () {
    $lap = LaporanOmset::build(
        ['daily' => [['date' => '2026-10-15', 'food' => 1]], 'compliments' => []], null);
    expect($lap['tanggal'])->toBe('2026-10-15')
        ->and($lap['oleh'])->toBe('AI Agent')
        ->and($lap['dicetakPada'])->toMatch('/pukul \d{2}\.\d{2}$/');
});
