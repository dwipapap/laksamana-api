<?php

use App\Erp\Tamu\Models\Event;
use App\Erp\Tamu\Models\JadwalTalent;
use App\Erp\Tamu\Models\Kursi;
use App\Erp\Tamu\Models\PembayaranTalent;
use App\Erp\Tamu\Models\PesananTiket;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-event against the restored legacy EMS
 * (docs/erp/event-tiket.md "Pemetaan lama → v2").
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-event'])->assertSuccessful();
});

it('imports events, seats and orders with the money to the rupiah, idempotently', function () {
    $first = Kursi::query()->orderBy('id')->pluck('version', 'id')->all();
    $this->artisan('core:import', ['module' => 'erp-event'])->assertSuccessful();
    $ems = DB::connection('legacy_ems');

    expect(Kursi::query()->orderBy('id')->pluck('version', 'id')->all())->toBe($first)
        ->and(Event::count())->toBe($ems->table('events')->count())
        ->and(Kursi::count())->toBe($ems->table('seats')->count())
        ->and(PesananTiket::count())->toBe($ems->table('orders')->count())
        ->and((int) PesananTiket::sum('total'))->toBe((int) $ems->table('orders')->sum('total'));
});

it('keeps talent schedules at their fee and monthly pay', function () {
    $ems = DB::connection('legacy_ems');

    expect(JadwalTalent::count())->toBe($ems->table('schedules')->count())
        ->and((int) JadwalTalent::sum('tarif'))->toBe((int) $ems->table('schedules')->sum('fee'))
        ->and((int) PembayaranTalent::sum('total'))->toBe((int) $ems->table('talent_payments')->sum('total_amount'));
});
