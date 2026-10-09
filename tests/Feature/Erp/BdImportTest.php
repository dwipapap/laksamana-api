<?php

use App\Core\Models\LogAktivitas;
use App\Erp\Proyek\Models\PengajuanPembelianPenyetuju;
use App\Erp\Proyek\Models\PoProyek;
use App\Erp\Proyek\Models\Proyek;
use App\Erp\Proyek\Models\TugasTim;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-po-proyek and erp-kerja-tim against the restored legacy BD
 * (and the per-module logs). docs/erp/po-proyek.md, docs/erp/kerja-tim.md.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-po-proyek'])->assertSuccessful();
});

it('imports every project, PR and PO with the money to the rupiah, idempotently', function () {
    $first = PoProyek::query()->orderBy('id')->pluck('version', 'id')->all();
    $this->artisan('core:import', ['module' => 'erp-po-proyek'])->assertSuccessful();
    $po = DB::connection('legacy_bd')->table('purchase_orders')->get()->map(fn ($r) => json_decode($r->data, true));

    expect(PoProyek::query()->orderBy('id')->pluck('version', 'id')->all())->toBe($first)
        ->and(Proyek::count())->toBe(DB::connection('legacy_bd')->table('projects')->count())
        ->and(PoProyek::count())->toBe($po->count())
        ->and((int) PoProyek::sum('nominal'))->toBe((int) $po->sum(fn ($d) => round((float) ($d['amount'] ?? 0))))
        ->and((int) PoProyek::sum('realisasi'))->toBe((int) $po->sum(fn ($d) => ($d['realisasi'] ?? '') === '' ? 0 : round((float) $d['realisasi'])));
});

it('keeps each PR approver as a User with the approval date', function () {
    $legacy = DB::connection('legacy_bd')->table('purchase_requests')->get()
        ->sum(fn ($r) => count((array) (json_decode($r->data, true)['approvals'] ?? [])));

    expect(PengajuanPembelianPenyetuju::count())->toBe($legacy)
        ->and(PengajuanPembelianPenyetuju::whereNull('user_id')->count())->toBe(0);
});

it('imports BD tasks and every module log into the one shared log', function () {
    $this->artisan('core:import', ['module' => 'erp-kerja-tim'])->assertSuccessful();
    $logs = 0;
    foreach (['legacy_marketing' => 'activities', 'legacy_konten' => 'logs', 'legacy_akademi' => 'activity',
        'legacy_reservasi' => 'audit', 'legacy_hr' => 'audit', 'legacy_stock' => 'activity_log'] as $conn => $table) {
        $logs += DB::connection($conn)->table($table)->count();
    }

    expect(TugasTim::count())->toBe(DB::connection('legacy_bd')->table('tasks')->count())
        ->and(LogAktivitas::count())->toBe($logs);
});
