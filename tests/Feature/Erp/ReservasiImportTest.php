<?php

use App\Erp\Tamu\Models\Meja;
use App\Erp\Tamu\Models\Reservasi;
use App\Erp\Tamu\Models\ReservasiDp;
use App\Erp\Tamu\Models\ReservasiKedatangan;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-reservasi against the restored legacy reservasi
 * (docs/erp/reservasi.md "Pemetaan lama → v2").
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-reservasi'])->assertSuccessful();
});

function legacyReservations()
{
    return DB::connection('legacy_reservasi')->table('reservations')->get()->map(fn ($r) => json_decode($r->data, true));
}

it('imports every reservation, arrival and DP instalment, idempotently', function () {
    $first = ReservasiDp::query()->orderBy('id')->pluck('version', 'id')->all();
    $this->artisan('core:import', ['module' => 'erp-reservasi'])->assertSuccessful();
    $legacy = legacyReservations();
    $dps = $legacy->flatMap(fn ($d) => $d['dps'] ?? []);

    expect(ReservasiDp::query()->orderBy('id')->pluck('version', 'id')->all())->toBe($first)
        ->and(Reservasi::count())->toBe($legacy->count())
        ->and(ReservasiKedatangan::count())->toBe($legacy->sum(fn ($d) => count($d['arrivals'] ?? [])))
        ->and(ReservasiDp::count())->toBe($dps->count())
        ->and((int) ReservasiDp::sum('nominal'))->toBe((int) $dps->sum(fn ($p) => round((float) ($p['amount'] ?? 0))));
});

it('keeps the legacy table text and links only an exact single table', function () {
    $r = Reservasi::whereNotNull('meja_id')->with([])->first();

    expect(Meja::count())->toBeGreaterThan(0)
        ->and(Reservasi::whereNull('meja_impor')->whereNotNull('meja_id')->count())->toBe(0)
        ->and($r === null || strcasecmp(Meja::find($r->meja_id)->kode, $r->meja_impor) === 0)->toBeTrue();
});

it('folds the legacy status spellings into the five v2 states', function () {
    expect(Reservasi::whereNotIn('status', ['pending', 'confirmed', 'datang', 'cancelled', 'no_show'])->count())->toBe(0)
        ->and(Reservasi::where('status', 'datang')->count())
        ->toBe(legacyReservations()->filter(fn ($d) => in_array($d['status'] ?? '', ['Datang', 'Checked-in', 'Completed'], true))->count());
});
