<?php

use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisJadwalSinkron;
use App\Modules\Hris\Services\HrisKaryawanService;
use App\Modules\Hris\Services\HrisRosterService;
use App\Modules\Hris\Services\HrisShiftService;
use App\Modules\Jadwal\Services\JadwalService;

require_once __DIR__.'/helpers.php';

/*
 * E4 (ADR-0012, J6–J8): the roster in HRIS — grid, writes with the HRIS
 * Divisi check, warnings, recap, Jadwal Saya and the default shift.
 */

function rosterSel(string $uid, string $tgl): ?array
{
    foreach (app(JadwalService::class)->shiftRange($uid, $tgl, $tgl)['rows'] as $r) {
        return $r;
    }

    return null;
}

it('serves the month of one Divisi with only the shifts meant for it', function () {
    app(HrisShiftService::class)->update('PAGICS', ['divisi' => ['cleaning']], 'test');
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];

    $g = test()->getJson('/api/v1/hris/roster?bulan=2026-11&divisi=bar', $h)->assertOk()->json('data');
    expect($g['hari'])->toHaveCount(30)
        ->and(collect($g['kru'])->pluck('user_id'))->toContain('u-yuzaalfarel')
        ->and(collect($g['shift'])->pluck('kode'))->toContain('PAGI')->not->toContain('PAGICS')
        ->and($g['bolehDivisi'])->toBeNull();
});

it('lets a Kepala Divisi write their own crew and nobody else', function () {
    $h = ['Authorization' => 'Bearer '.hrisToken('u-arif')]; // head of bar
    // Heads write from today on only, so the date follows the clock.
    $d = date('Y-m-d', strtotime(hrisToday().' +9 days'));

    test()->putJson('/api/v1/hris/roster', ['isi' => [['u' => 'u-yuzaalfarel', 'd' => $d, 't' => 'PAGI']],
        'hapus' => []], $h)->assertOk()->assertJsonPath('data.isi', 1);
    expect(rosterSel('u-yuzaalfarel', $d)['t'])->toBe('PAGI');

    test()->putJson('/api/v1/hris/roster', ['isi' => [['u' => 'u-aryaputras', 'd' => $d, 't' => 'PAGI']],
        'hapus' => []], $h)->assertStatus(403);
});

it('keeps crew out of the roster but serves their own schedule', function () {
    hrisPlantShift('u-yuzaalfarel', '2026-11-03', 'PAGI', '', '');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-yuzaalfarel')];

    test()->getJson('/api/v1/hris/roster?bulan=2026-11&divisi=bar', $h)->assertStatus(403);
    $saya = test()->getJson('/api/v1/hris/me/jadwal?dari=2026-11-01&sampai=2026-11-30', $h)->assertOk()->json('data');
    expect($saya)->toHaveCount(1)
        ->and($saya[0])->toMatchArray(['tanggal' => '2026-11-03', 'kode' => 'PAGI', 'nama' => 'PAGI', 'mulai' => '09:00']);
});

it('validates cells: block hours come from the master, inactive and foreign shifts are refused', function () {
    $svc = app(HrisRosterService::class);
    app(HrisShiftService::class)->update('SPLIT', ['blok' => [
        ['mulai' => '11:00', 'selesai' => '15:00'], ['mulai' => '18:00', 'selesai' => '23:00']]], 'test');
    app(HrisShiftService::class)->update('SIANGCS', ['divisi' => ['cleaning']], 'test');
    app(HrisShiftService::class)->update('MIDDLE', ['aktif' => false], 'test');

    $svc->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => '2026-11-04', 't' => 'SPLIT', 'm' => '10:00', 's' => '22:00']], [], 'HR');
    expect(rosterSel('u-yuzaalfarel', '2026-11-04'))->toMatchArray(['t' => 'SPLIT', 'm' => '11:00', 's' => '23:00']);

    expect(fn () => $svc->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => '2026-11-05', 't' => 'MIDDLE']], [], 'HR'))
        ->toThrow(HrisError::class, 'nonaktif');
    expect(fn () => $svc->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => '2026-11-05', 't' => 'SIANGCS']], [], 'HR'))
        ->toThrow(HrisError::class, 'bukan untuk Divisi');
});

it('warns, without refusing, about long streaks and short rests', function () {
    $svc = app(HrisRosterService::class);
    $isi = [];
    foreach (range(9, 15) as $d) {
        $isi[] = ['u' => 'u-yuzaalfarel', 'd' => sprintf('2026-11-%02d', $d), 't' => 'PAGI'];
    }
    $isi[6]['t'] = 'SIANG';
    $isi[] = ['u' => 'u-yuzaalfarel', 'd' => '2026-11-16', 't' => 'PAGI'];

    $r = $svc->simpan(null, $isi, [], 'HR');
    expect($r['isi'])->toBe(8)
        ->and(implode(' | ', $r['peringatan']))->toContain('8 hari masuk beruntun')->toContain('jeda antar shift hanya 9');
});

it('recaps a month per crew member', function () {
    $svc = app(HrisRosterService::class);
    $svc->simpan(null, [
        ['u' => 'u-yuzaalfarel', 'd' => '2026-11-20', 't' => 'PAGI'],
        ['u' => 'u-yuzaalfarel', 'd' => '2026-11-21', 't' => 'OFF'],
    ], [], 'HR');

    $k = collect($svc->rekap('2026-11', 'bar')['kru'])->firstWhere('user_id', 'u-yuzaalfarel');
    expect($k['per_shift'])->toMatchArray(['PAGI' => 1, 'OFF' => 1])
        ->and($k['hari_kerja'])->toBe(1)->and($k['hari_libur'])->toBe(1)->and($k['kosong'])->toBe(28);
});

it('uses the Karyawan default shift only for nonshift staff without a roster', function () {
    app(HrisImporKaryawanService::class)->impor('test');
    // Shift-Divisi crew: the roster cell is the only schedule (owner 2026-10-24).
    app(HrisKaryawanService::class)->update('u-yuzaalfarel', ['shift_bawaan' => 'MIDDLE'], 'test');
    hrisClearShift('u-yuzaalfarel', '2026-11-25');
    expect(app(HrisAttendanceService::class)->shiftFor('u-yuzaalfarel', '2026-11-25'))->toBeNull()
        ->and(HrisKaryawan::query()->where('user_id', 'u-yuzaalfarel')->value('shift_bawaan'))->toBe('MIDDLE');

    // Nonshift staff: the default shift is their fixed schedule.
    app(HrisKaryawanService::class)->update('u-adit', ['shift_bawaan' => 'MIDDLE'], 'test');
    app(HrisJadwalSinkron::class)->tempatkan('u-adit', null, 'test');
    hrisClearShift('u-adit', '2026-11-25');
    expect(app(HrisAttendanceService::class)->shiftFor('u-adit', '2026-11-25'))
        ->toMatchArray(['kode' => 'MIDDLE', 'sumber' => 'BAWAAN']);
    expect(fn () => app(HrisKaryawanService::class)->update('u-yuzaalfarel', ['shift_bawaan' => 'OFF'], 'test'))
        ->toThrow(HrisError::class);
});

it('changes the roster rules as HR only', function () {
    expect(app(HrisRosterService::class)->simpanAturan(['maksBeruntun' => 5, 'jedaMin' => 11], 'HR'))
        ->toBe(['maksBeruntun' => 5, 'jedaMin' => 11]);
    expect(app(HrisRosterService::class)->simpanAturan(['jedaMin' => 0], 'HR'))
        ->toBe(['maksBeruntun' => 5, 'jedaMin' => 0])
        ->and(app(HrisRosterService::class)->aturan()['jedaMin'])->toBe(0);
    expect(fn () => app(HrisRosterService::class)->simpanAturan(['maksBeruntun' => 0], 'HR'))->toThrow(HrisError::class);
});
