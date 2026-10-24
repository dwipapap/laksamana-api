<?php

use App\Modules\Hris\Models\HrisPeriode;
use App\Modules\Hris\Models\HrisRosterEvent;
use App\Modules\Hris\Models\HrisRosterSimpan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisKaryawanService;
use App\Modules\Hris\Services\HrisKoreksiAbsenService;
use App\Modules\Hris\Services\HrisRosterService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * The official schedule (owner decisions 2026-10-24): the roster cell is the
 * only schedule of shift-Divisi crew, the clock-out goal is the schedule's
 * end, a night shift is one work date, corrections follow koreksiMaksHari
 * and a locked period, and every roster save is logged for HR.
 */

it('keeps the clock-out goal at shift end when crew arrive three hours early', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    $svc = app(HrisAttendanceService::class);
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'SIANG', '14:00', '23:00');

    $masuk = $svc->recordPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 20, 11, 0));
    expect($masuk['punch']['status'])->toBe('VALID');

    // Nine hours after MASUK is still three hours before the shift ends.
    $pulang = $svc->recordPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 20, 20, 0));
    expect($pulang['punch'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => true, 'kurang_menit' => 180]);

    $tepat = $svc->recordPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 20, 23, 0));
    expect($tepat['punch'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => false, 'kurang_menit' => 0]);

    hrisClearShift('u-dwipa', hrisDate(2026, 10, 20));
});

it('serves the scheduled end as Boleh pulang in me/today', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    $tgl = hrisToday();
    [$y, $m, $d] = array_map(intval(...), explode('-', $tgl));
    hrisPlantShift('u-dwipa', $tgl, 'SIANG', '14:00', '23:00');
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa'), hrisMs($y, $m, $d, 11, 30));

    $r = app(HrisAttendanceService::class)->meToday(hrisMe('u-dwipa'));
    expect($r['bolehPulang'])->toBe('23:00')->and($r['targetAsal'])->toBe('ROSTER')
        ->and($r['aturan']['koreksiMaksHari'])->toBe(7);

    hrisClearShift('u-dwipa', $tgl);
});

it('books a MASUK after midnight on the night shift that started the day before', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    $svc = app(HrisAttendanceService::class);
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'MALAM', '19:00', '04:00');
    hrisClearShift('u-dwipa', hrisDate(2026, 10, 21));

    $masuk = $svc->recordPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 21, 0, 30));
    expect($masuk['punch'])->toMatchArray(['tanggal_kerja' => '2026-10-20', 'status' => 'VALID',
        'shift_kode' => 'MALAM', 'telat_menit' => 325]);

    // The next day has no cell: the PULANG is still judged by the night shift.
    $pulang = $svc->recordPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 21, 4, 0));
    expect($pulang['punch'])->toMatchArray(['tanggal_kerja' => '2026-10-20', 'status' => 'VALID',
        'shift_kode' => 'MALAM', 'pulang_awal' => false]);

    hrisClearShift('u-dwipa', hrisDate(2026, 10, 20));
});

it('queues shift-Divisi crew with an empty roster cell even when they have a default shift', function () {
    hrisSettings();
    app(HrisImporKaryawanService::class)->impor('test');
    app(HrisKaryawanService::class)->update('u-yuzaalfarel', ['shift_bawaan' => 'MIDDLE'], 'test');
    hrisEnrolOnce('u-yuzaalfarel');
    hrisClearShift('u-yuzaalfarel', hrisDate(2026, 10, 20));

    $r = app(HrisAttendanceService::class)->recordPunch(hrisMe('u-yuzaalfarel'),
        hrisWajahPunch('u-yuzaalfarel', 'MASUK', ['alasan' => 'dipanggil mendadak']), hrisMs(2026, 10, 20, 11, 0));
    expect($r['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['TANPA_SHIFT'], 'shift_sumber' => 'NONE']);
});

it('follows the koreksiMaksHari setting and never files into a locked period', function () {
    app(HrisImporKaryawanService::class)->impor('test');
    // Resolved per call: settings are memoized per service instance.
    $ajukan = fn (string $tgl, string $kunci) => app(HrisKoreksiAbsenService::class)->ajukan(hrisMe('u-yuzaalfarel'),
        ['tanggal_kerja' => $tgl, 'arah' => 'PULANG', 'jam' => '18:00', 'alasan' => 'lupa', 'idempotency_key' => $kunci]);
    $mundur = fn (int $n) => date('Y-m-d', strtotime(hrisToday()." -{$n} days"));

    hrisSettings(['koreksiMaksHari' => 2]);
    expect(fn () => $ajukan($mundur(3), 'k-a'))->toThrow(fn (HrisError $e) => expect($e->errorCode)->toBe('terlalu_lama'));
    expect($ajukan($mundur(2), 'k-b')['status'])->toBe('MENUNGGU');

    hrisSettings(['koreksiMaksHari' => 31]);
    $lama = $mundur(31);
    HrisPeriode::query()->updateOrCreate(['bulan' => substr($lama, 0, 7)], ['status' => 'TERKUNCI']);
    if (substr($lama, 0, 7) !== substr(hrisToday(), 0, 7)) {
        expect(fn () => $ajukan($lama, 'k-c'))->toThrow(fn (HrisError $e) => expect($e->errorCode)->toBe('periode_terkunci'));
    }
});

it('lets a Kepala Divisi publish from today on, logs each save for HR, and refuses locked periods', function () {
    app(HrisImporKaryawanService::class)->impor('test');
    $svc = app(HrisRosterService::class);
    $besok = date('Y-m-d', strtotime(hrisToday().' +1 day'));
    $lusa = date('Y-m-d', strtotime(hrisToday().' +2 days'));
    $kemarin = date('Y-m-d', strtotime(hrisToday().' -1 day'));
    hrisClearShift('u-yuzaalfarel', $besok);
    hrisClearShift('u-yuzaalfarel', $lusa);

    $r = $svc->simpan(['bar'], [['u' => 'u-yuzaalfarel', 'd' => $besok, 't' => 'PAGI'],
        ['u' => 'u-yuzaalfarel', 'd' => $lusa, 't' => 'SIANG']], [], 'Arif', 'u-arif', 'Roster minggu depan');
    expect($r['berubah'])->toBe(2);

    $log = HrisRosterSimpan::query()->latest('waktu')->firstOrFail();
    expect($log)->toMatchArray(['aktor' => 'Arif', 'aktor_user_id' => 'u-arif', 'peran' => 'KEPALA',
        'divisi' => ['bar'], 'jumlah_isi' => 2, 'jumlah_berubah' => 2, 'catatan' => 'Roster minggu depan'])
        ->and($log->tanggal_dari->format('Y-m-d'))->toBe($besok)
        ->and($log->tanggal_sampai->format('Y-m-d'))->toBe($lusa)
        ->and(HrisRosterEvent::query()->where('simpan_id', $log->id)->count())->toBe(2);

    // Saving the same cells again changes nothing and logs nothing.
    $n = HrisRosterSimpan::query()->count();
    $svc->simpan(['bar'], [['u' => 'u-yuzaalfarel', 'd' => $besok, 't' => 'PAGI']], [], 'Arif', 'u-arif');
    expect(HrisRosterSimpan::query()->count())->toBe($n);

    // A past date is HR's.
    expect(fn () => $svc->simpan(['bar'], [['u' => 'u-yuzaalfarel', 'd' => $kemarin, 't' => 'PAGI']], [], 'Arif', 'u-arif'))
        ->toThrow(fn (HrisError $e) => expect($e->errorCode)->toBe('tanggal_lewat'));

    // A locked period is nobody's.
    HrisPeriode::query()->updateOrCreate(['bulan' => substr($kemarin, 0, 7)], ['status' => 'TERKUNCI']);
    expect(fn () => $svc->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => $kemarin, 't' => 'PAGI']], [], 'HR', 'u-admin'))
        ->toThrow(fn (HrisError $e) => expect($e->errorCode)->toBe('periode_terkunci'));

    hrisClearShift('u-yuzaalfarel', $besok);
    hrisClearShift('u-yuzaalfarel', $lusa);
});

it('serves the roster activity to HR only', function () {
    app(HrisImporKaryawanService::class)->impor('test');
    $besok = date('Y-m-d', strtotime(hrisToday().' +1 day'));
    hrisClearShift('u-yuzaalfarel', $besok);
    app(HrisRosterService::class)->simpan(['bar'], [['u' => 'u-yuzaalfarel', 'd' => $besok, 't' => 'PAGI']], [], 'Arif', 'u-arif');
    hrisMakeHr('u-admin');

    $d = test()->getJson('/api/v1/hris/kelola/roster/aktivitas?bulan='.substr($besok, 0, 7).'&divisi=bar',
        ['Authorization' => 'Bearer '.hrisToken('u-admin')])->assertOk()->json('data.simpan');
    expect($d[0])->toMatchArray(['aktor' => 'Arif', 'peran' => 'KEPALA', 'divisi' => ['bar'], 'jumlah_berubah' => 1]);

    hrisClearShift('u-yuzaalfarel', $besok);
});
