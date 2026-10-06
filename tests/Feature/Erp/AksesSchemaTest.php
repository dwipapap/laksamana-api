<?php

use App\Erp\Akses\Models\Halaman;
use App\Erp\Akses\Models\Kewenangan;
use App\Erp\Akses\Models\PenempatanPeran;
use App\Erp\Akses\Models\Peran;
use App\Erp\Akses\Models\PeranHalaman;
use App\Erp\Akses\Models\PeranKewenangan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Access inside a Modul (docs/erp/akses.md): the rules the database
 * enforces on its own.
 */

function aksesModul(string $kunci): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('modul')->insert(['id' => $id, 'kunci' => $kunci, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function aksesUser(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => 'Kru '.$id, 'pin' => '0000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function aksesFixture(): array
{
    $kas = aksesModul('uji_kas');
    $stock = aksesModul('uji_stock');
    $staf = Peran::create(['modul_id' => $kas, 'kunci' => 'staf', 'nama' => 'Staf', 'bawaan' => true]);
    $bayar = Halaman::create(['modul_id' => $kas, 'kunci' => 'bayar', 'nama' => 'Planning Pembayaran']);
    $opname = Halaman::create(['modul_id' => $stock, 'kunci' => 'opname', 'nama' => 'Opname']);
    $koreksi = Kewenangan::create(['modul_id' => $stock, 'kunci' => 'stok.koreksi', 'nama' => 'Koreksi stok']);

    return compact('kas', 'stock', 'staf', 'bayar', 'opname', 'koreksi');
}

it('gives a Peran a Tingkat on a page of its own Modul only', function () {
    $f = aksesFixture();
    PeranHalaman::create(['modul_id' => $f['kas'], 'peran_id' => $f['staf']->id, 'halaman_id' => $f['bayar']->id, 'tingkat' => 1]);

    // a Kas role on a Stock page, whichever modul_id is claimed
    expect(fn () => PeranHalaman::create(['modul_id' => $f['kas'], 'peran_id' => $f['staf']->id, 'halaman_id' => $f['opname']->id, 'tingkat' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => PeranHalaman::create(['modul_id' => $f['stock'], 'peran_id' => $f['staf']->id, 'halaman_id' => $f['opname']->id, 'tingkat' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => PeranKewenangan::create(['modul_id' => $f['kas'], 'peran_id' => $f['staf']->id, 'kewenangan_id' => $f['koreksi']->id]))
        ->toThrow(QueryException::class);
});

it('refuses a Tingkat or Lingkup outside the agreed values', function () {
    $f = aksesFixture();
    $row = ['modul_id' => $f['kas'], 'peran_id' => $f['staf']->id, 'halaman_id' => $f['bayar']->id];

    expect(fn () => PeranHalaman::create($row + ['tingkat' => 3]))->toThrow(QueryException::class)
        ->and(fn () => PeranHalaman::create($row + ['tingkat' => 2, 'lingkup' => 'tim']))->toThrow(QueryException::class);
});

it('keeps one default Peran per Modul, and one Peran per User per Modul', function () {
    $f = aksesFixture();
    $manajemen = Peran::create(['modul_id' => $f['kas'], 'kunci' => 'manajemen', 'nama' => 'Manajemen']);
    $user = aksesUser();
    PenempatanPeran::create(['user_id' => $user, 'modul_id' => $f['kas'], 'peran_id' => $manajemen->id]);

    expect(fn () => Peran::create(['modul_id' => $f['kas'], 'kunci' => 'viewer', 'nama' => 'Viewer', 'bawaan' => true]))
        ->toThrow(QueryException::class)
        ->and(fn () => PenempatanPeran::create(['user_id' => $user, 'modul_id' => $f['kas'], 'peran_id' => $f['staf']->id]))
        ->toThrow(QueryException::class)
        // a Stock placement cannot point at a Kas role
        ->and(fn () => PenempatanPeran::create(['user_id' => $user, 'modul_id' => $f['stock'], 'peran_id' => $f['staf']->id]))
        ->toThrow(QueryException::class)
        // a role still held by someone cannot be dropped
        ->and(fn () => $manajemen->forceDelete())->toThrow(QueryException::class);
});

it('drops a role\'s matrix rows with it, and a deleted User\'s placements', function () {
    $f = aksesFixture();
    $viewer = Peran::create(['modul_id' => $f['kas'], 'kunci' => 'viewer', 'nama' => 'Viewer']);
    PeranHalaman::create(['modul_id' => $f['kas'], 'peran_id' => $viewer->id, 'halaman_id' => $f['bayar']->id, 'tingkat' => 1]);
    $user = aksesUser();
    PenempatanPeran::create(['user_id' => $user, 'modul_id' => $f['kas'], 'peran_id' => $f['staf']->id]);

    $viewer->forceDelete();
    DB::connection('core')->table('user')->where('id', $user)->delete();

    expect(PeranHalaman::where('peran_id', $viewer->id)->count())->toBe(0)
        ->and(PenempatanPeran::where('user_id', $user)->count())->toBe(0);
});
