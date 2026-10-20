<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisRequestService;
use App\Support\Modules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * api#309: a repeated PULANG overwrites (never 500), the replaced photo is
 * removed only after the new save lands, and HRD approval stamps the Jadwal
 * cells with the HRD official's name (`HRIS:<nama>`).
 */

it('overwrites a repeated PULANG without a 500', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK'] + hrisWajahPunch('u-dwipa'), $h)->assertCreated();
    $satu = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'PULANG'] + hrisWajahPunch('u-dwipa', 'PULANG'), $h)->assertCreated()->json('data');
    $dua = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'PULANG'] + hrisWajahPunch('u-dwipa', 'PULANG'), $h)->assertCreated()->json('data');

    expect($dua['duplikat'])->toBeFalse()->and($dua['punch']['id'])->toBe($satu['punch']['id']);
    expect(HrisKetukan::query()->where('user_id', 'u-dwipa')->where('arah', 'PULANG')->count())->toBe(1);
});

it('deletes the replaced PULANG photo only after the new save lands', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $svc = app(HrisAttendanceService::class);
    $me = hrisMe('u-dwipa');

    $svc->recordPunch($me, hrisWajahPunch('u-dwipa'), hrisMs(2026, 11, 3, 8, 0));
    $satu = $svc->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG', ['foto' => base64_encode('foto-pertama')]),
        hrisMs(2026, 11, 3, 17, 0));
    $lama = (string) HrisKetukan::query()->find($satu['punch']['id'])->foto_path;
    expect($lama)->not->toBe('');
    expect(Storage::disk('local')->exists('hris-faces/'.$lama))->toBeTrue();

    $dua = $svc->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG', ['foto' => base64_encode('foto-kedua')]),
        hrisMs(2026, 11, 3, 18, 0));
    $baru = (string) HrisKetukan::query()->find($dua['punch']['id'])->foto_path;
    expect($dua['punch']['id'])->toBe($satu['punch']['id']);
    expect($baru)->not->toBe($lama);
    expect(Storage::disk('local')->exists('hris-faces/'.$lama))->toBeFalse();
    expect(Storage::disk('local')->exists('hris-faces/'.$baru))->toBeTrue();
});

it('stamps approved Jadwal cells with the HRD official name', function () {
    Http::fake();
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    hrisMakeHr('u-admin');
    app(HrisBalanceService::class)->adjust('test', $crew, 'CUTI', 12, 'Saldo awal Talenta');
    $req = app(HrisRequestService::class)->create(hrisMe($crew),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-03', 'alasan' => 'Keluarga']);
    app(HrisRequestService::class)->decide(hrisMe('u-arif'), $req, 'DISETUJUI', 'ok');

    $t = hrisToken('u-admin');
    test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI', 'nota' => 'acc'],
        ['Authorization' => 'Bearer '.$t])->assertOk();

    $nama = (string) officeUser('u-admin')['name'];
    expect($nama)->not->toBe('');
    $sel = Modules::db('jadwal')->select(
        'SELECT `updated_by` FROM `jadwal_sel` WHERE `tgl` IN (?, ?) AND `shift` = ?',
        ['2026-11-02', '2026-11-03', 'CUTI']);
    expect($sel)->not->toBe([]);
    foreach ($sel as $r) {
        expect((string) ((array) $r)['updated_by'])->toBe('HRIS:'.$nama);
    }
});
