<?php

use App\Erp\Master\Imports\OrangImporter;
use App\Erp\Master\Models\Karyawan;
use App\Erp\Master\Models\Klien;
use App\Erp\Master\Models\Kol;
use App\Erp\Master\Models\PekerjaHarian;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Talent;
use Illuminate\Support\Facades\DB;

/*
 * core:import erp-orang against the restored legacy HR, DW, EMS, Marketing,
 * Konten and BD (docs/erp/orang-divisi.md "Pemetaan daftar lama"). Users come
 * from `core:import account` first. Counts come from the legacy rows.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
});

function orangSnapshot(): array
{
    return [
        'karyawan' => Karyawan::query()->orderBy('user_id')->pluck('version', 'user_id')->all(),
        'pihak' => Pihak::query()->orderBy('id')->pluck('version', 'id')->all(),
    ];
}

it('imports every legacy party role once, idempotently', function () {
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $first = orangSnapshot();
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();

    expect(orangSnapshot())->toBe($first)
        ->and(PekerjaHarian::count())->toBe(DB::connection('legacy_dw')->table('dw_pekerja')->count())
        ->and(Talent::count())->toBe(DB::connection('legacy_ems')->table('talents')->count())
        ->and(Klien::count())->toBe(DB::connection('legacy_marketing')->table('clients')->count())
        ->and(Kol::count())->toBe(DB::connection('legacy_konten')->table('kols')->count());
});

it('gives every HR employee with an Office account one Karyawan, and reports the rest', function () {
    $importer = app(OrangImporter::class);
    $importer->import();
    $tanpaUser = count($importer->issues()['karyawan_tanpa_user'] ?? []);

    expect(Karyawan::count() + $tanpaUser)->toBe(DB::connection('legacy_hr')->table('employees')->count());

    // the HR id is the Office user id wherever both exist
    $e = DB::connection('legacy_hr')->table('employees')->whereIn('id', DB::connection('core')->table('user')->pluck('legacy_id'))->first();
    $userId = DB::connection('core')->table('user')->where('legacy_id', $e->id)->value('id');
    expect(Karyawan::find($userId)?->legacy_id)->toBe((string) $e->id);
});

it('points each Klien at its Marketing PIC by Office id', function () {
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $c = DB::connection('legacy_marketing')->table('clients')->where('mkt_pic', '<>', '')->first();
    $pic = DB::connection('core')->table('user')->where('legacy_id', $c->mkt_pic)->value('id');

    expect(Klien::where('legacy_id', $c->id)->value('pic_marketing_id'))->toBe($pic);
});

it('creates the kantor Divisi and keeps the shift codes v1 reads', function () {
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $jenis = DB::connection('core')->table('divisi')->pluck('jenis', 'kode')->all();

    foreach (OrangImporter::DIVISI as $kode => [, $j]) {
        expect($jenis[$kode] ?? null)->toBe($j);
    }
});

it('keeps a daily worker\'s Divisi list and payout account', function () {
    $this->artisan('core:import', ['module' => 'erp-orang'])->assertSuccessful();
    $p = DB::connection('legacy_dw')->table('dw_pekerja')->where('divisi', 'like', '%,%')->where('bayar_nomor', '<>', '')->first()
        ?? DB::connection('legacy_dw')->table('dw_pekerja')->where('bayar_nomor', '<>', '')->first();
    $dw = PekerjaHarian::where('legacy_id', $p->id)->with('divisi', 'pihak.rekening')->firstOrFail();

    expect($dw->divisi)->toHaveCount(count(array_filter(explode(',', (string) $p->divisi))))
        ->and($dw->pihak->rekening->pluck('nomor')->all())->toContain(trim((string) $p->bayar_nomor));
});
