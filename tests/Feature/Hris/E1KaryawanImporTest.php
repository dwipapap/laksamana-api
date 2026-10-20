<?php

use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisJabatan;
use App\Modules\Hris\Models\HrisJabatanLevel;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisMasterBawaan;
use Illuminate\Database\QueryException;

require_once __DIR__.'/helpers.php';

/*
 * E1 (ADR-0012): HRIS master data tables, their seeds, and the idempotent
 * import from the Office users, the legacy HR panel and Jadwal.
 */

function karyawanOf(string $uid): ?HrisKaryawan
{
    return HrisKaryawan::query()->with(['divisi', 'jabatan.level'])->where('user_id', $uid)->first();
}

it('seeds the five shift Divisi and the six draft levels once', function () {
    app(HrisMasterBawaan::class)->pastikan();
    HrisJabatanLevel::query()->where('urutan', 6)->first()->delete(); // HR removes a level
    app(HrisMasterBawaan::class)->pastikan();

    expect(HrisDivisi::query()->where('jenis', 'shift')->orderBy('urutan')->pluck('kode')->all())
        ->toBe(['bar', 'kitchen', 'floor', 'cashier', 'cleaning'])
        ->and(HrisJabatanLevel::query()->orderBy('urutan')->pluck('nama')->all())
        ->toBe(['Helper/Trainee', 'Crew', 'Senior', 'Captain/Supervisor', 'Head/Kepala']);
});

it('imports active Users with HR data and places them in a Divisi and Jabatan', function () {
    $r = app(HrisImporKaryawanService::class)->impor('test');

    expect($r['dibuat'])->toBeGreaterThan(30)
        ->and($r['nonaktif'])->toBeGreaterThan(0);

    $adit = karyawanOf('u-adit');
    expect($adit->divisi->kode)->toBe('kitchen')
        ->and($adit->jabatan->nama)->toBe('Head Chef')
        ->and($adit->jabatan->level->urutan)->toBe(5)
        ->and($adit->status_kerja)->toBe('KONTRAK')
        ->and($adit->tanggal_gabung->format('Y-m-d'))->toBe('2025-08-21');

    expect(karyawanOf('u-ayunita')->divisi->kode)->toBe('cleaning')
        ->and(karyawanOf('u-aldialfayat')->status_kerja)->toBe('PROBATION')
        ->and(karyawanOf('u-aldialfayat')->jabatan->level->urutan)->toBe(1)
        ->and(karyawanOf('u-mputraariza')->jabatan->level->urutan)->toBe(4)
        ->and(karyawanOf('u-anggi'))->toBeNull(); // inactive: left before HRIS

    // Legacy HR divisions that are not shift Divisi become kantor Divisi.
    expect(HrisDivisi::query()->where('jenis', 'kantor')->pluck('nama')->all())->toContain('Finance');
});

it('is idempotent and never overwrites an edited Karyawan', function () {
    $svc = app(HrisImporKaryawanService::class);
    $svc->impor('test');
    $jabatan = HrisJabatan::query()->count();
    karyawanOf('u-adit')->fill(['status_kerja' => 'TETAP'])->save();

    $r = $svc->impor('test');
    expect($r['dibuat'])->toBe(0)
        ->and($r['sudah_ada'])->toBeGreaterThan(30)
        ->and(HrisJabatan::query()->count())->toBe($jabatan)
        ->and(karyawanOf('u-adit')->status_kerja)->toBe('TETAP');
});

it('saves nothing on a dry run', function () {
    $r = app(HrisImporKaryawanService::class)->impor('test', coba: true);

    expect($r['dibuat'])->toBeGreaterThan(30)
        ->and(HrisKaryawan::query()->count())->toBe(0)
        ->and(HrisDivisi::query()->where('jenis', 'kantor')->count())->toBe(0);
});

it('generates a free Divisi kode from the name', function () {
    app(HrisMasterBawaan::class)->pastikan();
    $svc = app(HrisImporKaryawanService::class);
    expect($svc->kodeBaru('Marketing & Digital'))->toBe('marketing-digital')
        ->and($svc->kodeBaru('Kitchen'))->toBe('kitchen-2');
});

it('rejects an exit date without a reason and an unknown status kerja', function () {
    expect(fn () => HrisKaryawan::query()->create(['user_id' => 'u-x1', 'tanggal_keluar' => '2026-10-01']))
        ->toThrow(QueryException::class);
    expect(fn () => HrisKaryawan::query()->create(['user_id' => 'u-x2', 'status_kerja' => 'HARIAN']))
        ->toThrow(QueryException::class);
});
