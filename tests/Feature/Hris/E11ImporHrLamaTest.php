<?php

declare(strict_types=1);

use App\Modules\Hris\Models\HrisKpiItem;
use App\Modules\Hris\Models\HrisKpiRealisasi;
use App\Modules\Hris\Models\HrisKpiTemplate;
use App\Modules\Hris\Models\HrisPelanggaran;
use App\Modules\Hris\Models\HrisPenilaianAtasan;
use App\Modules\Hris\Services\HrisImporHrLamaService;
use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/*
 * E11 (ADR-0012, Q22/Q32): the remaining legacy HR data moves into the HRIS
 * tables that already exist. Runs against the restored local `hr` dump; the
 * tests never touch dev or production (CLAUDE.md §0).
 */
it('imports violations, KPI and reviews from the local HR dump', function () {
    $svc = app(HrisImporHrLamaService::class);

    // --coba rolls the whole core transaction back.
    $coba = $svc->impor('uji', true);
    expect($coba['pelanggaran_dibuat'])->toBeGreaterThan(0)
        ->and(HrisPelanggaran::query()->count())->toBe(0)
        ->and(HrisKpiTemplate::query()->count())->toBe(0)
        ->and(HrisPenilaianAtasan::query()->count())->toBe(0);

    $r = $svc->impor('uji');

    // Violation vi_jqlcr662nx6: u-adit (talentaId 118825), SP1, Ringan.
    $p = HrisPelanggaran::query()->where('user_id', 'u-adit')->first();
    expect($p)->not->toBeNull()
        ->and($p->tanggal->format('Y-m-d'))->toBe('2026-07-18')
        ->and($p->jenis)->toBe('Terlambat')
        ->and($p->tingkat)->toBe('RINGAN')
        ->and($p->sp_ke)->toBe(1)
        ->and($p->status)->toBe('AKTIF');

    // Kitchen template: five items, one July realisation of 30 for Food Cost.
    $t = HrisKpiTemplate::query()->where('divisi', 'kitchen')->first();
    expect($t)->not->toBeNull();
    $item = HrisKpiItem::query()->where('template_id', $t->id)->where('nama', 'Food Cost')->first();
    expect($item)->not->toBeNull()
        ->and((float) $item->target)->toBe(30.0)
        ->and($item->arah)->toBe('TURUN')
        ->and((float) $item->bobot)->toBe(30.0);
    expect((float) HrisKpiRealisasi::query()->where('item_id', $item->id)->where('bulan', '2026-07')->value('nilai'))->toEqual(30.0);

    // Review rv_7jloyy6tioa: u-adit, 2026-07, 1-7 scale mapped to 1-5.
    $n = HrisPenilaianAtasan::query()->where('user_id', 'u-adit')->where('bulan', '2026-07')->first();
    expect($n)->not->toBeNull()
        ->and($n->penilai_id)->toBe('u-wandi')
        ->and($n->aspek['Skill'])->toBe(3)       // 4
        ->and($n->aspek['Knowledge'])->toBe(2)   // 2
        ->and($n->aspek['Creativity'])->toBe(5); // 7
    expect($r['kpi_realisasi_tanpa_item'])->toBe([]);
});

it('is idempotent and never overwrites an existing HRIS row', function () {
    $svc = app(HrisImporHrLamaService::class);
    $svc->impor('uji');

    $p = HrisPelanggaran::query()->where('user_id', 'u-adit')->firstOrFail();
    $p->fill(['keterangan' => 'sudah diubah HR'])->save();
    $t = HrisKpiTemplate::query()->where('divisi', 'kitchen')->firstOrFail();
    $item = HrisKpiItem::query()->where('template_id', $t->id)->where('nama', 'Food Cost')->firstOrFail();
    $item->fill(['target' => 999])->save();
    $real = HrisKpiRealisasi::query()->where('item_id', $item->id)->firstOrFail();
    $real->fill(['nilai' => 999])->save();
    $n = HrisPenilaianAtasan::query()->where('user_id', 'u-adit')->firstOrFail();
    $n->fill(['aspek' => ['X' => 1]])->save();

    $sebelum = [
        'pelanggaran' => HrisPelanggaran::query()->count(),
        'template' => HrisKpiTemplate::query()->count(),
        'item' => HrisKpiItem::query()->count(),
        'realisasi' => HrisKpiRealisasi::query()->count(),
        'penilaian' => HrisPenilaianAtasan::query()->count(),
    ];

    $r = $svc->impor('uji');

    expect($r['pelanggaran_dibuat'])->toBe(0)
        ->and($r['kpi_template_dibuat'])->toBe(0)
        ->and($r['kpi_item_dibuat'])->toBe(0)
        ->and($r['kpi_realisasi_dibuat'])->toBe(0)
        ->and($r['penilaian_dibuat'])->toBe(0)
        ->and(HrisPelanggaran::query()->count())->toBe($sebelum['pelanggaran'])
        ->and(HrisKpiTemplate::query()->count())->toBe($sebelum['template'])
        ->and(HrisKpiItem::query()->count())->toBe($sebelum['item'])
        ->and(HrisKpiRealisasi::query()->count())->toBe($sebelum['realisasi'])
        ->and(HrisPenilaianAtasan::query()->count())->toBe($sebelum['penilaian']);

    // The rows the importer found first stay exactly as HR left them.
    expect(HrisPelanggaran::query()->find($p->id)->keterangan)->toBe('sudah diubah HR')
        ->and((float) HrisKpiItem::query()->find($item->id)->target)->toBe(999.0)
        ->and((float) HrisKpiRealisasi::query()->find($real->id)->nilai)->toBe(999.0)
        ->and(HrisPenilaianAtasan::query()->find($n->id)->aspek)->toBe(['X' => 1]);
});

it('reports people, scales and months it cannot map instead of guessing', function () {
    // A review with an out-of-scale value and a violation for an unknown person.
    Modules::db('hr')->table('reviews')->insert([
        'id' => 'rv_uji_skala', 'emp_id' => 'u-adit', 'bulan' => '2026-08', 'status' => '',
        'data' => json_encode([
            'id' => 'rv_uji_skala', 'empId' => 'u-adit', 'month' => '2026-08',
            'layers' => ['hr' => ['aspects' => ['Skill' => 9], 'by' => 'u-wandi']],
        ]),
    ]);
    Modules::db('hr')->table('violations')->insert([
        'id' => 'vi_uji_orang', 'emp_id' => 'u-tidak-ada', 'tanggal' => '2026-08-01',
        'jenis' => 'Terlambat', 'severity' => 'Ringan', 'sp' => 'SP1', 'status' => 'Tercatat',
        'data' => json_encode(['id' => 'vi_uji_orang', 'empId' => 'u-tidak-ada', 'date' => '2026-08-01',
            'type' => 'Terlambat', 'severity' => 'Ringan', 'sp' => 'SP1', 'note' => '', 'status' => 'Tercatat']),
    ]);

    $r = app(HrisImporHrLamaService::class)->impor('uji');

    expect($r['penilaian_tidak_valid'])->not->toBeEmpty()
        ->and($r['pelanggaran_tanpa_orang'])->not->toBeEmpty()
        ->and(HrisPenilaianAtasan::query()->where('bulan', '2026-08')->exists())->toBeFalse()
        ->and(HrisPelanggaran::query()->where('tanggal', '2026-08-01')->exists())->toBeFalse();
});
