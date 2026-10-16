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

it('buatPdfOmsetHarian memulangkan biner PDF sesuai spesifikasi', function () {
    $bin = LaporanOmset::buatPdfOmsetHarian([
        'food' => 11405800, 'bev' => 9952014, 'lainnya' => 345000,
        'discount' => 315200, 'tax' => 2070781, 'service_charge' => 1035391,
        'traffic' => 284, 'bill' => 141,
        'qty_food' => 288, 'qty_bev' => 517, 'qty_others' => 9,
        'self_amount' => 3656000, 'self_disc' => 0,
        'compliment_total' => 1051100,
        'tanggal' => '2026-10-15', 'nama_pencetak' => 'AI Agent',
        'waktu_cetak' => '16 Oktober 2026 pukul 22.50',
        'bulan' => ['tagihan' => 459184816, 'net' => 405042035, 'hari_terinput' => 15],
    ]);
    expect(substr($bin, 0, 5))->toBe('%PDF-')->and(strlen($bin))->toBeGreaterThan(10000);
});

it('hitungHarian menampilkan strip saat pembagi nol', function () {
    $h = LaporanOmset::hitungHarian([]);
    expect($h['upt'])->toBe('—')->and($h['atv'])->toBe('—')->and($h['aps'])->toBe('—')
        ->and($h['selfPct'])->toBe('—')->and($h['rFood'])->toBe('—');
});

it('hitungHarian memformat angka gaya Indonesia', function () {
    $h = LaporanOmset::hitungHarian([
        'food' => 11405800, 'bev' => 9952014, 'lainnya' => 345000,
        'discount' => 315200, 'tax' => 2070781, 'service_charge' => 1035391,
        'traffic' => 284, 'bill' => 141,
        'qty_food' => 288, 'qty_bev' => 517, 'qty_others' => 9,
        'self_amount' => 3656000, 'self_disc' => 0, 'compliment_total' => 1051100,
    ]);
    expect($h['tagihan'])->toBe('Rp24.493.786')
        ->and($h['net'])->toBe('Rp21.387.614')
        ->and($h['rFood'])->toBe('52,6%')
        ->and($h['upt'])->toBe('5,77')
        ->and($h['atv'])->toBe('Rp151.685');
});
