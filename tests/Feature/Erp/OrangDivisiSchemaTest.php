<?php

use App\Erp\Master\Models\Karyawan;
use App\Erp\Master\Models\Klien;
use App\Erp\Master\Models\PekerjaHarian;
use App\Erp\Master\Models\PekerjaHarianDivisi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Talent;
use App\Erp\Master\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * People and Divisi (docs/erp/orang-divisi.md): the rules the database
 * enforces on its own.
 */

function orangUser(string $nama): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => $nama, 'pin' => '0000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function orangDivisi(string $kode, string $jenis): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('divisi')->insert([
        'id' => $id, 'kode' => $kode, 'nama' => ucfirst($kode), 'jenis' => $jenis,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('gives a User one HR record in a kantor Divisi, with a superior', function () {
    $finance = orangDivisi('uji_finance', 'kantor');
    $atasan = orangUser('Atasan Uji');
    $user = orangUser('Karyawan Uji');

    $k = Karyawan::create([
        'user_id' => $user, 'divisi_id' => $finance, 'atasan_id' => $atasan,
        'tanggal_lahir' => '1999-02-01', 'akhir_kontrak' => '2027-01-31',
    ]);

    expect($k->version)->toBe(1)
        ->and($k->akhir_kontrak->format('Y-m-d'))->toBe('2027-01-31')
        ->and(fn () => Karyawan::create(['user_id' => $user]))->toThrow(QueryException::class);
});

it('refuses a Divisi kind other than shift or kantor, and defaults to shift', function () {
    expect(fn () => orangDivisi('uji_gudang', 'gudang'))->toThrow(QueryException::class);

    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('divisi')->insert(['id' => $id, 'kode' => 'uji_lama', 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::connection('core')->table('divisi')->where('id', $id)->value('jenis'))->toBe('shift');
});

it('keeps a User with an HR record from being hard-deleted', function () {
    $user = orangUser('Tetap Ada');
    Karyawan::create(['user_id' => $user]);

    expect(fn () => DB::connection('core')->table('user')->where('id', $user)->delete())->toThrow(QueryException::class);
});

it('clears an optional superior or Marketing PIC when that User is deleted', function () {
    $atasan = orangUser('Atasan Pergi');
    $user = orangUser('Bawahan');
    Karyawan::create(['user_id' => $user, 'atasan_id' => $atasan]);
    $klien = Klien::create(['pihak_id' => Pihak::create(['nama' => 'Klien Uji'])->id, 'pic_marketing_id' => $atasan]);

    DB::connection('core')->table('user')->where('id', $atasan)->delete();

    expect(Karyawan::find($user)->atasan_id)->toBeNull()
        ->and($klien->fresh()->pic_marketing_id)->toBeNull();
});

it('lets one Pihak hold several roles, but each role once', function () {
    $pihak = Pihak::create(['nama' => 'Band Sekaligus Sound', 'email' => 'band@contoh.id']);
    Vendor::create(['pihak_id' => $pihak->id]);
    Talent::create(['pihak_id' => $pihak->id, 'tarif_bawaan' => 1500000]);

    expect($pihak->fresh()->vendor)->not->toBeNull()
        ->and($pihak->fresh()->talent->tarif_bawaan)->toBe('1500000')
        ->and(fn () => Talent::create(['pihak_id' => $pihak->id]))->toThrow(QueryException::class)
        ->and(fn () => Talent::create(['pihak_id' => Pihak::create(['nama' => 'Minus'])->id, 'tarif_bawaan' => -1]))
        ->toThrow(QueryException::class);
});

it('identifies a Pekerja Harian by phone and lets them work in several Divisi', function () {
    $bar = orangDivisi('uji_bar', 'shift');
    $kitchen = orangDivisi('uji_kitchen', 'shift');
    $dw = PekerjaHarian::create(['pihak_id' => Pihak::create(['nama' => 'DW Uji'])->id, 'no_hp' => '0812000111']);
    PekerjaHarianDivisi::create(['pihak_id' => $dw->pihak_id, 'divisi_id' => $bar]);
    PekerjaHarianDivisi::create(['pihak_id' => $dw->pihak_id, 'divisi_id' => $kitchen]);

    expect($dw->divisi()->count())->toBe(2)
        ->and(fn () => PekerjaHarian::create(['pihak_id' => Pihak::create(['nama' => 'DW Kembar'])->id, 'no_hp' => '0812000111']))
        ->toThrow(QueryException::class)
        ->and(fn () => PekerjaHarianDivisi::create(['pihak_id' => $dw->pihak_id, 'divisi_id' => $bar]))
        ->toThrow(QueryException::class)
        // a Pihak with a role cannot be hard-deleted
        ->and(fn () => DB::connection('core')->table('pihak')->where('id', $dw->pihak_id)->delete())
        ->toThrow(QueryException::class);
});
