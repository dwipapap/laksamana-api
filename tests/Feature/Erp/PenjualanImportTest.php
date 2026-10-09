<?php

use App\Erp\Kas\Models\ArusKas;
use App\Erp\Kas\Models\SetoranHari;
use App\Erp\Penjualan\Models\Bon;
use App\Erp\Penjualan\Models\Compliment;
use App\Erp\Penjualan\Models\LaporanKasirBayar;
use App\Erp\Penjualan\Models\OmsetHarian;
use App\Erp\Penjualan\Models\OmsetPorsi;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-penjualan against the restored legacy Kompas blob
 * (docs/erp/penjualan-harian.md "Pemetaan lama → v2"). Every amount must
 * match the legacy rows to the rupiah.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-penjualan'])->assertSuccessful();
});

function kompas(string $path): array
{
    $d = json_decode((string) DB::connection('legacy_kompas')->table('app_state')->value('data'), true);

    return (array) data_get($d, $path, []);
}

it('keeps every sales day and its amounts, idempotently', function () {
    $first = OmsetPorsi::query()->orderBy('id')->pluck('version', 'id')->all();
    $this->artisan('core:import', ['module' => 'erp-penjualan'])->assertSuccessful();
    $daily = collect(kompas('daily'));

    expect(OmsetPorsi::query()->orderBy('id')->pluck('version', 'id')->all())->toBe($first)
        ->and(OmsetHarian::count())->toBe($daily->count())
        ->and((int) OmsetHarian::sum('food'))->toBe((int) $daily->sum(fn ($d) => round((float) ($d['food'] ?? 0))))
        ->and((int) OmsetHarian::sum('pajak'))->toBe((int) $daily->sum(fn ($d) => round((float) ($d['tax'] ?? 0))));
});

it('credits the breakdown to the same PICs as the legacy screen', function () {
    $mkt = collect(kompas('daily'))->flatMap(fn ($d) => $d['bd']['marketing'] ?? []);
    $kasir = collect(kompas('daily'))->flatMap(fn ($d) => $d['bd']['kasir'] ?? []);

    expect((int) OmsetPorsi::where('sumber', 'marketing')->sum(DB::raw('nominal + pajak + service')))
        ->toBe((int) $mkt->sum(fn ($r) => round((float) ($r['amount'] ?? 0)) + round((float) ($r['tax'] ?? 0)) + round((float) ($r['service'] ?? 0))))
        ->and((int) OmsetPorsi::where('sumber', 'kasir')->sum('nominal'))->toBe((int) $kasir->sum(fn ($r) => round((float) ($r['amount'] ?? 0))));
});

it('keeps the Report Daily per method and lands takings in the wallets', function () {
    $pay = collect(kompas('reports'))->flatMap(fn ($r) => array_values((array) ($r['pay'] ?? [])));

    expect((int) LaporanKasirBayar::sum('nominal_pos'))->toBe((int) $pay->sum(fn ($p) => round((float) ($p['pos'] ?? 0))))
        ->and((int) LaporanKasirBayar::sum('nominal_aktual'))->toBe((int) $pay->sum(fn ($p) => round((float) ($p['actual'] ?? 0))))
        ->and(ArusKas::where('sebab', 'omset')->where('arah', '!=', 'masuk')->count())->toBe(0)
        ->and(ArusKas::where('sebab', 'omset')->count())->toBeGreaterThan(0)
        // a deposited day points at its Report Daily
        ->and(SetoranHari::whereNull('laporan_kasir_id')->count())->toBe(0);
});

it('keeps compliments and guest bons with their settlement', function () {
    $bon = collect(kompas('piutang'));

    expect(Compliment::count())->toBe(count(kompas('compliments')))
        ->and((int) Bon::sum('nominal'))->toBe((int) $bon->sum(fn ($b) => round((float) $b['nominal'])))
        ->and(Bon::where('status', 'lunas')->count())->toBe($bon->where('status', 'lunas')->count());
});
