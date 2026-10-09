<?php

use App\Erp\Kas\Models\ArusKas;
use App\Erp\Kas\Models\KasKecil;
use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Kas\Models\Setoran;
use App\Erp\Kas\Models\SetoranHari;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-kas against the restored legacy Finance and Kompas
 * (docs/erp/kas.md "Pemetaan lama → v2"). Money must match the legacy rows to
 * the rupiah.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
});

function arusTotal(string $sebab, string $arah): int
{
    return (int) ArusKas::where('sebab', $sebab)->where('arah', $arah)->sum('nominal');
}

function legacyBlob(string $connection, string $table, string $path): array
{
    $d = json_decode((string) DB::connection($connection)->table($table)->value('data'), true);

    return (array) data_get($d, $path, []);
}

it('books Kas Kecil per pos to the rupiah, idempotently', function () {
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $first = ArusKas::query()->orderBy('id')->pluck('version', 'id')->all();
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $pos = DB::connection('legacy_finance')->table('kk_trx_pos');

    expect(ArusKas::query()->orderBy('id')->pluck('version', 'id')->all())->toBe($first)
        ->and(KasKecil::count())->toBe(DB::connection('legacy_finance')->table('kk_trx')->count())
        ->and(arusTotal('kas_kecil', 'masuk'))->toBe((int) (clone $pos)->sum('debet'))
        ->and(arusTotal('kas_kecil', 'keluar'))->toBe((int) (clone $pos)->sum('kredit'));
});

it('moves every deposit from the brankas to its bank, split per day', function () {
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $legacy = legacyBlob('legacy_kompas', 'app_state', 'rekap_setoran');
    $total = (int) collect($legacy)->sum(fn ($s) => round((float) $s['nominal']));

    expect(Setoran::count())->toBe(count($legacy))
        ->and(arusTotal('setoran', 'keluar'))->toBe($total)
        ->and((int) SetoranHari::sum('nominal'))->toBe($total);
});

it('pays capital back to the investor and keeps manual wallet moves', function () {
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $returns = collect(legacyBlob('legacy_finance', 'bk_state', 'investor'))->flatMap(fn ($i) => $i['returns'] ?? []);
    $mutasi = collect(legacyBlob('legacy_finance', 'bk_state', 'mutasi'));

    expect((int) DB::connection('core')->table('pengembalian_modal')->sum('nominal'))->toBe((int) $returns->sum(fn ($r) => round((float) $r['amount'])))
        ->and(DB::connection('core')->table('mutasi_dompet')->count())->toBe($mutasi->count());
});

it('maps the Report Daily payment methods to the Brankas wallets', function () {
    $this->artisan('core:import', ['module' => 'erp-kas'])->assertSuccessful();
    $dompet = fn (string $kode) => MetodeBayar::where('kode', $kode)->first()?->dompet_id
        ? DB::connection('core')->table('dompet')->where('id', MetodeBayar::where('kode', $kode)->value('dompet_id'))->value('kode') : null;

    expect($dompet('cash'))->toBe('cash')
        ->and($dompet('qris_bri'))->toBe('bri')
        ->and($dompet('qris_esb'))->toBe('uob')
        ->and($dompet('compliment'))->toBeNull();
});
