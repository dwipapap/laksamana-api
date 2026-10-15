<?php

use App\Modules\Hris\Models\HrisPengajuan;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisRequestService;
use App\Modules\Jadwal\Services\JadwalService;

/*
 * CUTI/PH/IZIN requests (L1): crew file, Kepala Divisi first, HRD second;
 * approval deducts FIFO and writes Jadwal cells; cancelling an approved
 * request refunds. One HTTP identity per test.
 */

function hrisRequest(array $me, array $in): HrisPengajuan
{
    return app(HrisRequestService::class)->create($me, $in);
}

it('files a CUTI request after a balance grant', function () {
    hrisSettings();
    app(HrisBalanceService::class)->adjust('test', 'u-dwipa', 'CUTI', 12, 'Saldo awal Talenta');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->postJson('/api/v1/hris/requests',
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-04', 'alasan' => 'Keluarga'],
        $h)->assertCreated()->json('data');

    expect($r)->toMatchArray(['jenis' => 'CUTI', 'hari' => 3, 'status' => 'MENUNGGU']);
    // Filing locks nothing: the balance is deducted at approval.
    expect(app(HrisBalanceService::class)->saldo('u-dwipa', 'CUTI'))->toBe(12);

    $list = test()->getJson('/api/v1/hris/requests', $h)->assertOk()->json('data');
    expect(array_column($list, 'id'))->toContain($r['id']);
    test()->getJson("/api/v1/hris/requests/{$r['id']}", $h)->assertOk()->assertJsonPath('data.hari', 3);
});

it('refuses requests without balance or without IZIN proof', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/requests',
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-04', 'alasan' => 'Keluarga'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'saldo_kurang');
    test()->postJson('/api/v1/hris/requests',
        ['jenis' => 'IZIN', 'dari' => '2026-11-02', 'alasan' => 'Sakit'], $h)
        ->assertStatus(422)->assertJsonPath('error.message', 'Pengajuan IZIN wajib melampirkan bukti.');

    $r = test()->postJson('/api/v1/hris/requests',
        ['jenis' => 'IZIN', 'dari' => '2026-11-02', 'alasan' => 'Sakit',
            'lampiran' => base64_encode('SURATDOKTER'), 'lampiran_nama' => 'surat.txt'], $h)
        ->assertCreated()->json('data');
    expect($r)->toMatchArray(['jenis' => 'IZIN', 'status' => 'MENUNGGU', 'ada_lampiran' => true]);
});

it('walks CUTI from filing to Jadwal cells', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    hrisMakeHr('u-admin');
    app(HrisBalanceService::class)->adjust('test', $crew, 'CUTI', 12, 'Saldo awal Talenta');

    $req = hrisRequest(hrisMe($crew),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-03', 'alasan' => 'Keluarga']);

    // Step 1: the Kepala Divisi (u-arif heads bar).
    $t = hrisToken('u-arif');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI', 'nota' => 'ok'], $h)->assertOk()->json('data');
    expect($r['status'])->toBe('MENUNGGU_HRD');
    $req = HrisPengajuan::query()->find($req->id);
    expect($req->head_oleh_id)->toBe('u-arif');
});

it('finishes HRD approval with deduction and cells', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    hrisMakeHr('u-admin');
    app(HrisBalanceService::class)->adjust('test', $crew, 'CUTI', 12, 'Saldo awal Talenta');
    $req = hrisRequest(hrisMe($crew),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-03', 'alasan' => 'Keluarga']);
    app(HrisRequestService::class)->decide(hrisMe('u-arif'), $req, 'DISETUJUI', 'ok');

    // Step 2: HRD, through HTTP (one identity).
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI', 'nota' => 'acc'], $h)->assertOk()->json('data');
    expect($r['status'])->toBe('DISETUJUI');

    expect(app(HrisBalanceService::class)->saldo($crew, 'CUTI'))->toBe(10);
    $cells = app(JadwalService::class)->shiftRange($crew, '2026-11-02', '2026-11-03')['rows'] ?? [];
    expect(array_column($cells, 't'))->toBe(['CUTI', 'CUTI']);
});

it('writes OFF cells with a PH note for approved PH', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    hrisMakeHr('u-admin');
    app(HrisBalanceService::class)->adjust('test', $crew, 'PH', 2, 'Saldo awal Talenta');
    $req = hrisRequest(hrisMe($crew),
        ['jenis' => 'PH', 'dari' => '2026-12-28', 'sampai' => '2026-12-28', 'alasan' => 'Ganti libur']);
    app(HrisRequestService::class)->decide(hrisMe('u-arif'), $req, 'DISETUJUI', 'ok');

    $t = hrisToken('u-admin');
    test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI'], ['Authorization' => 'Bearer '.$t])->assertOk();

    expect(app(HrisBalanceService::class)->saldo($crew, 'PH'))->toBe(1);
    $cells = app(JadwalService::class)->shiftRange($crew, '2026-12-28', '2026-12-28')['rows'] ?? [];
    expect($cells[0]['t'])->toBe('OFF');
});

it('lets HR take the head step when the division has none', function () {
    hrisSettings();
    hrisMakeHr('u-admin');
    // u-andry has an empty Tim (nonshift): no head will ever own the request.
    app(HrisBalanceService::class)->adjust('test', 'u-andry', 'CUTI', 12, 'Saldo awal Talenta');
    $req = hrisRequest(hrisMe('u-andry'),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-02', 'alasan' => 'Urusan']);

    $t = hrisToken('u-admin');
    $r = test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI'], ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($r['status'])->toBe('MENUNGGU_HRD');
});

it('refuses the HRD cell write without Jadwal rights', function () {
    hrisSettings(['hr' => ['u-mella']]);
    app(HrisBalanceService::class)->adjust('test', 'u-andry', 'CUTI', 12, 'Saldo awal Talenta');
    $req = hrisRequest(hrisMe('u-andry'),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-02', 'alasan' => 'Urusan']);

    // u-mella is HR by settings (not a jadwal admin): head step passes (no
    // head for nonshift), the cell write is refused with guidance.
    $t = hrisToken('u-mella');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI'], $h)->assertOk()->assertJsonPath('data.status', 'MENUNGGU_HRD');
    test()->postJson("/api/v1/hris/requests/{$req->id}/decision",
        ['status' => 'DISETUJUI'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'jadwal_tulis_ditolak');
    expect(HrisPengajuan::query()->find($req->id)->status)->toBe('MENUNGGU_HRD');
    expect(app(HrisBalanceService::class)->saldo('u-andry', 'CUTI'))->toBe(12);
});

it('forbids self-approval, ends on rejection, and refuses twice', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    app(HrisBalanceService::class)->adjust('test', 'u-arif', 'CUTI', 12, 'Saldo awal Talenta');
    app(HrisBalanceService::class)->adjust('test', $crew, 'CUTI', 12, 'Saldo awal Talenta');
    $sendiri = hrisRequest(hrisMe('u-arif'),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-02', 'alasan' => 'Cuti head']);
    $orang = hrisRequest(hrisMe($crew),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-02', 'alasan' => 'Keluarga']);

    $t = hrisToken('u-arif');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson("/api/v1/hris/requests/{$sendiri->id}/decision", ['status' => 'DISETUJUI'], $h)
        ->assertStatus(403);
    test()->postJson("/api/v1/hris/requests/{$orang->id}/decision", ['status' => 'DITOLAK', 'nota' => 'Rame'], $h)
        ->assertOk()->assertJsonPath('data.status', 'DITOLAK');
    test()->postJson("/api/v1/hris/requests/{$orang->id}/decision", ['status' => 'DISETUJUI'], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'already_decided');
});

it('lets the requester cancel while waiting, never after approval', function () {
    hrisSettings();
    app(HrisBalanceService::class)->adjust('test', 'u-dwipa', 'CUTI', 12, 'Saldo awal Talenta');
    $req = hrisRequest(hrisMe('u-dwipa'),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-03', 'alasan' => 'Keluarga']);

    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->deleteJson("/api/v1/hris/requests/{$req->id}", [], $h)->assertOk()->json('data');
    expect($r['status'])->toBe('DIBATALKAN');
    expect(app(HrisBalanceService::class)->saldo('u-dwipa', 'CUTI'))->toBe(12);
});

it('lets HR cancel an approved request with refund and cell cleanup', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    hrisMakeHr('u-admin');
    app(HrisBalanceService::class)->adjust('test', $crew, 'CUTI', 12, 'Saldo awal Talenta');
    $req = hrisRequest(hrisMe($crew),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-03', 'alasan' => 'Keluarga']);
    app(HrisRequestService::class)->decide(hrisMe('u-arif'), $req->fresh(), 'DISETUJUI', 'ok');
    app(HrisRequestService::class)->decide(hrisMe('u-admin'), $req->fresh(), 'DISETUJUI', 'acc');
    expect(app(HrisBalanceService::class)->saldo($crew, 'CUTI'))->toBe(10);

    $t = hrisToken('u-admin');
    $r = test()->deleteJson("/api/v1/hris/requests/{$req->id}", [],
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($r['status'])->toBe('DIBATALKAN');
    expect(app(HrisBalanceService::class)->saldo($crew, 'CUTI'))->toBe(12);
    $cells = app(JadwalService::class)->shiftRange($crew, '2026-11-02', '2026-11-03')['rows'] ?? [];
    expect($cells)->toBe([]);
});
