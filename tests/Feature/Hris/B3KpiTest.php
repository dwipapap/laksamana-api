<?php

use App\Modules\Hris\Services\HrisDisiplinService;
use App\Modules\Hris\Services\HrisKpiService;

require_once __DIR__.'/helpers.php';

/*
 * Discipline (S4) and team KPI (K1): writes HR-gated, reads scoped; KPI
 * input stays behind kpiInputAktif until the mentor confirms the items.
 */

it('records and cancels violations as HR', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $id = test()->postJson('/api/v1/hris/pelanggaran',
        ['user' => 'u-dwipa', 'tanggal' => '2026-09-07', 'jenis' => 'Terlambat apel',
            'tingkat' => 'RINGAN', 'keterangan' => 'uji'], $h)->assertCreated()->json('data.id');

    $list = test()->getJson('/api/v1/hris/pelanggaran?dari=2026-09-01&sampai=2026-09-30', $h)
        ->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['tingkat'])->toBe('RINGAN');

    test()->postJson("/api/v1/hris/pelanggaran/{$id}/batal", [], $h)->assertOk()->assertJsonPath('data.status', 'DIBATALKAN');
    test()->postJson('/api/v1/hris/pelanggaran',
        ['user' => 'u-dwipa', 'tanggal' => '2026-09-07', 'tingkat' => 'FATAL'], $h)->assertStatus(422);
});

it('scopes violations to the crew member', function () {
    hrisMakeHr('u-admin');
    app(HrisDisiplinService::class)->create(
        ['user_id' => 'u-andry', 'tanggal' => '2026-09-07', 'tingkat' => 'RINGAN'], 'test');

    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/pelanggaran', ['user' => 'u-dwipa', 'tanggal' => '2026-09-07'], $h)
        ->assertStatus(403);
    $list = test()->getJson('/api/v1/hris/pelanggaran', $h)->assertOk()->json('data');
    expect($list)->toBe([]);
});

it('shows empty KPI without a template', function () {
    hrisSettings();
    $t = hrisToken('u-arif');
    $h = ['Authorization' => 'Bearer '.$t];

    // The bar head sees bar (crew only their own division: a 403 otherwise).
    $r = test()->getJson('/api/v1/hris/kpi?bulan=2026-09&divisi=bar', $h)->assertOk()->json('data');
    expect($r)->toMatchArray(['divisi' => 'bar', 'bulan' => '2026-09', 'skor' => null, 'item' => []]);
});

it('keeps KPI input behind the mentor gate', function () {
    hrisSettings(['kpiInputAktif' => false]);
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');

    test()->postJson('/api/v1/hris/kpi/templates',
        ['divisi' => 'bar', 'berlaku_dari' => '2026-01-01',
            'item' => [['nama' => 'Omzet', 'target' => 100, 'arah' => 'NAIK', 'bobot' => 1]]],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(403)->assertJsonPath('error.code', 'fitur_nonaktif');
});

it('computes clamped weighted achievement', function () {
    expect(HrisKpiService::capaian(100, 110, 'NAIK'))->toBe(110.0);
    expect(HrisKpiService::capaian(10, 5, 'TURUN'))->toBe(120.0); // 200 clamped
    expect(HrisKpiService::capaian(0, 5, 'NAIK'))->toBe(0.0);
    expect(HrisKpiService::capaian(10, 0, 'TURUN'))->toBe(0.0);
});

it('writes templates and realisation when input opens', function () {
    hrisSettings(['kpiInputAktif' => true]);
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $id = test()->postJson('/api/v1/hris/kpi/templates',
        ['divisi' => 'bar', 'berlaku_dari' => '2026-01-01', 'item' => [
            ['nama' => 'Omzet', 'target' => 100, 'arah' => 'NAIK', 'bobot' => 1],
            ['nama' => 'Komplain', 'target' => 10, 'arah' => 'TURUN', 'bobot' => 1],
        ]], $h)->assertCreated()->json('data.id');

    $items = app(HrisKpiService::class)->nilai('bar', '2026-09')['item'];
    expect($items)->toHaveCount(2);

    foreach ($items as $it) {
        $nilai = $it['nama'] === 'Omzet' ? 110 : 5;
        test()->postJson('/api/v1/hris/kpi/realisasi',
            ['item_id' => $it['id'], 'bulan' => '2026-09', 'nilai' => $nilai], $h)->assertOk();
    }
    $r = test()->getJson('/api/v1/hris/kpi?bulan=2026-09&divisi=bar', $h)->assertOk()->json('data');
    // (110 + 120) / 2.
    expect($r['skor'])->toEqual(115)->and($r['kelengkapan'])->toEqual(100);
    expect($id)->not->toBe('');
});
