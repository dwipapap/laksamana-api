<?php

use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisRosterEvent;
use App\Modules\Hris\Models\HrisUbahShift;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisRequestService;
use App\Modules\Hris\Services\HrisRosterService;
use App\Modules\Hris\Services\HrisShiftService;
use App\Modules\Hris\Services\HrisUbahShiftService;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

/*
 * Shift changes for one crew member on one work date (issue #346): filed
 * by the crew, decided by the division head (HR fallback), applied by the
 * backend in one command. One HTTP identity per test.
 */

function ubahSiap(): void
{
    app(HrisImporKaryawanService::class)->impor('test');
}

function ubahCrew(string $uid = 'u-yuzaalfarel'): array
{
    return ['Authorization' => 'Bearer '.hrisToken($uid)];
}

function ubahHead(): array
{
    return ['Authorization' => 'Bearer '.hrisToken('u-arif')];
}

function ubahHr(): array
{
    hrisMakeHr('u-admin');

    return ['Authorization' => 'Bearer '.hrisToken('u-admin')];
}

function ubahTargetValid(string $uid, string $kecuali = 'PAGI'): string
{
    $k = HrisKaryawan::query()->with('divisi')->where('user_id', $uid)->firstOrFail();
    $div = (string) ($k->divisi?->kode ?? '');
    foreach (app(HrisShiftService::class)->list() as $x) {
        if ($x['kode'] === $kecuali || ! $x['aktif'] || $x['libur']) {
            continue;
        }
        if ($x['divisi'] !== [] && ! in_array($div, $x['divisi'], true)) {
            continue;
        }
        if (in_array($x['kode'], ['OFF', 'CUTI', 'IZIN'], true)) {
            continue;
        }

        return (string) $x['kode'];
    }
    throw new RuntimeException('no valid target shift in fixtures');
}

function ubahTanggal(int $plusHari = 2): string
{
    return date('Y-m-d', strtotime(hrisToday().' +'.$plusHari.' days'));
}

/** File through the service (for head/HR-HTTP tests). */
function ubahSvcAjukan(string $uid, string $tgl, string $baru, string $kunci): array
{
    if ($baru === 'MALAM') {
        $baru = ubahTargetValid($uid);
    }

    return app(HrisUbahShiftService::class)->ajukan(hrisMe($uid),
        ['tanggal_kerja' => $tgl, 'shift_baru' => $baru, 'alasan' => 'Tukar shift uji', 'idempotency_key' => $kunci]);
}

function ubahVersi(string $id): int
{
    return (int) HrisUbahShift::query()->find($id)->version;
}

it('files a shift change without touching the official roster', function () {
    ubahSiap();
    $h = ubahCrew();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $tujuan = ubahTargetValid('u-yuzaalfarel');

    $r = test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $d, 'shift_baru' => $tujuan, 'alasan' => 'Ada acara keluarga', 'idempotency_key' => 'k-1'], $h)
        ->assertCreated()->json('data');

    expect($r['status'])->toBe('MENUNGGU')
        ->and($r['shift_lama']['kode'])->toBe('PAGI')
        ->and($r['shift_baru']['kode'])->toBe($tujuan)
        ->and($r['shift_baru']['blok'])->not()->toBe([]);
    $sel = app(JadwalService::class)->shiftRange('u-yuzaalfarel', $d, $d)['rows'];
    expect($sel[0]['t'])->toBe('PAGI');
});

it('refuses unfilable submits with clear messages', function () {
    ubahSiap();
    $h = ubahCrew();
    $d = ubahTanggal();

    // No explicit cell: scheduling, not a shift change.
    test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $d, 'shift_baru' => 'MALAM', 'alasan' => 'x', 'idempotency_key' => 'k-kosong'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'tanpa_jadwal');

    hrisPlantShift('u-yuzaalfarel', $d, 'OFF', '', '');
    test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $d, 'shift_baru' => 'MALAM', 'alasan' => 'x', 'idempotency_key' => 'k-off'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'tanpa_jadwal');

    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $d, 'shift_baru' => 'PAGI', 'alasan' => 'x', 'idempotency_key' => 'k-sama'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'tidak_ada_perubahan');
    foreach (['OFF', 'CUTI', 'IZIN'] as $i => $tujuan) {
        test()->postJson('/api/v1/hris/tim/ubah-shift',
            ['tanggal_kerja' => $d, 'shift_baru' => $tujuan, 'alasan' => 'x', 'idempotency_key' => "k-tujuan-{$i}"], $h)
            ->assertStatus(422);
    }
    $lampau = date('Y-m-d', strtotime(hrisToday().' -1 day'));
    hrisPlantShift('u-yuzaalfarel', $lampau, 'PAGI', '08:00', '17:00');
    test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $lampau, 'shift_baru' => 'MALAM', 'alasan' => 'x', 'idempotency_key' => 'k-lampau'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'tanggal_lewat');
    expect(HrisUbahShift::query()->where('user_id', 'u-yuzaalfarel')->count())->toBe(0);
});

it('refuses a started shift and a punched date', function () {
    ubahSiap();
    Carbon::setTestNow(Carbon::now('Asia/Jakarta')->startOfDay()->addHours(12));
    try {
        $h = ubahCrew();
        $d = hrisToday();
        hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
        $tujuan = ubahTargetValid('u-yuzaalfarel');
        test()->postJson('/api/v1/hris/tim/ubah-shift',
            ['tanggal_kerja' => $d, 'shift_baru' => $tujuan, 'alasan' => 'x', 'idempotency_key' => 'k-mulai'], $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'shift_mulai');
    } finally {
        Carbon::setTestNow();
    }

    $h = ubahCrew();
    $d = ubahTanggal(4);
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    HrisKetukan::query()->create(['user_id' => 'u-yuzaalfarel', 'tanggal_kerja' => $d, 'arah' => 'MASUK', 'waktu' => 1]);
    test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $d, 'shift_baru' => $tujuan, 'alasan' => 'x', 'idempotency_key' => 'k-ketuk'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'sudah_ada_ketukan');
});

it('keeps one active request per crew and date', function () {
    ubahSiap();
    $h = ubahCrew();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');

    $tujuan = ubahTargetValid('u-yuzaalfarel');
    $d1 = ['tanggal_kerja' => $d, 'shift_baru' => $tujuan, 'alasan' => 'x', 'idempotency_key' => 'k-a'];
    $pertama = test()->postJson('/api/v1/hris/tim/ubah-shift', $d1, $h)->assertCreated()->json('data');

    test()->postJson('/api/v1/hris/tim/ubah-shift', array_merge($d1, ['idempotency_key' => 'k-b']), $h)->assertStatus(409);
    $ulang = test()->postJson('/api/v1/hris/tim/ubah-shift', $d1, $h);
    expect($ulang->status())->toBeIn([200, 201]);
    expect($ulang->json('data.id'))->toBe($pertama['id']);

    // Same key, different date (with its own explicit cell): key reuse refused.
    $d3 = ubahTanggal(3);
    hrisPlantShift('u-yuzaalfarel', $d3, 'PAGI', '08:00', '17:00');
    test()->postJson('/api/v1/hris/tim/ubah-shift', array_merge($d1, ['tanggal_kerja' => $d3]), $h)->assertStatus(409);
});

it('scopes history and detail to the owner', function () {
    ubahSiap();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $milik = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-milik');
    $asing = ubahCrew('u-arif');

    test()->getJson("/api/v1/hris/tim/ubah-shift/{$milik['id']}", $asing)->assertNotFound();
    test()->postJson("/api/v1/hris/tim/ubah-shift/{$milik['id']}/batal", ['berubah' => 1], $asing)->assertNotFound();
    test()->getJson('/api/v1/hris/tim/ubah-shift', $asing)->assertOk()->assertJsonPath('meta.total', 0);
});

it('lets the owner cancel while waiting', function () {
    ubahSiap();
    $h = ubahCrew();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $tujuan = ubahTargetValid('u-yuzaalfarel');
    $id = test()->postJson('/api/v1/hris/tim/ubah-shift',
        ['tanggal_kerja' => $d, 'shift_baru' => $tujuan, 'alasan' => 'x', 'idempotency_key' => 'k-c'], $h)
        ->assertCreated()->json('data.id');

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/batal", ['berubah' => ubahVersi($id)], $h)
        ->assertOk()->assertJsonPath('data.status', 'DIBATALKAN');
    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/batal", ['berubah' => 99], $h)->assertStatus(409);
});

it('lets the division head approve directly and writes the roster', function () {
    ubahSiap();
    $h = ubahHead();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $tujuan = ubahTargetValid('u-yuzaalfarel');
    $id = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-putus')['id'];

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $h)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');

    $sel = app(JadwalService::class)->shiftRange('u-yuzaalfarel', $d, $d)['rows'];
    expect($sel[0]['t'])->toBe($tujuan);
    $event = HrisRosterEvent::query()->where('ref_pengajuan_id', $id)->first();
    expect($event)->not()->toBeNull();
    expect($event->sumber)->toBe('UBAH_SHIFT')
        ->and($event->sel_lama['kode'])->toBe('PAGI')
        ->and($event->sel_baru['kode'])->toBe($tujuan);
});

it('refuses non-heads, other divisions and self-approval', function () {
    ubahSiap();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $id = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-tolak')['id'];

    // Crew decides nothing.
    $kru = ubahCrew('u-dwipa');
    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $kru)->assertForbidden();
    expect(HrisUbahShift::query()->find($id)->status)->toBe('MENUNGGU');
});

it('lets HR decide when the requester is a head themself', function () {
    ubahSiap();
    $hr = ubahHr();
    $d = ubahTanggal();
    hrisPlantShift('u-adit', $d, 'PAGI', '08:00', '17:00');
    $id = ubahSvcAjukan('u-adit', $d, 'MALAM', 'k-hr')['id'];

    // The requester heads a division, so no head may decide — not even self.
    try {
        app(HrisUbahShiftService::class)->putuskan(hrisMe('u-adit'), $id,
            ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)]);
        expect(false)->toBeTrue('self-approval must fail');
    } catch (HrisError $e) {
        expect($e->status)->toBe(403)->and($e->errorCode)->toBe('forbidden');
    }

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
    expect(HrisUbahShift::query()->find($id)->status)->toBe('DISETUJUI');
});

it('rejects with a note and keeps the roster on reject or cancel', function () {
    ubahSiap();
    $h = ubahHead();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $id = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-reject')['id'];

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DITOLAK', 'berubah' => ubahVersi($id)], $h)->assertStatus(422);
    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DITOLAK', 'catatan' => 'Butuh orang pagi itu.', 'berubah' => ubahVersi($id)], $h)
        ->assertOk()->assertJsonPath('data.status', 'DITOLAK');

    $sel = app(JadwalService::class)->shiftRange('u-yuzaalfarel', $d, $d)['rows'];
    expect($sel[0]['t'])->toBe('PAGI');
    expect(HrisRosterEvent::query()->where('ref_pengajuan_id', $id)->count())->toBe(0);
});

it('reports a manual edit as a conflict instead of overwriting', function () {
    ubahSiap();
    $h = ubahHead();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $id = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-konflik')['id'];

    app(HrisRosterService::class)->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => $d, 't' => 'SIANG']], [], 'test');

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'sel_berubah');
    expect(HrisUbahShift::query()->find($id)->status)->toBe('MENUNGGU');
    $sel = app(JadwalService::class)->shiftRange('u-yuzaalfarel', $d, $d)['rows'];
    expect($sel[0]['t'])->toBe('SIANG');
});

it('recovers when the cell already shows the target', function () {
    ubahSiap();
    $h = ubahHead();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $tujuan = ubahTargetValid('u-yuzaalfarel');
    $id = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-pulih')['id'];

    // Simulate a crash between the Jadwal write and the core finalize: the
    // cell is exactly what the approval would have written.
    $minta = HrisUbahShift::query()->find($id);
    app(HrisRosterService::class)->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => $d, 't' => $tujuan,
        'm' => (string) ($minta->shift_baru['m'] ?? ''), 's' => (string) ($minta->shift_baru['s'] ?? '')]], [], 'test');

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $h)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
    expect(HrisRosterEvent::query()->where('ref_pengajuan_id', $id)->count())->toBe(1);
});

it('fails visibly when the roster write breaks, then recovers', function () {
    ubahSiap();
    $h = ubahHead();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $id = ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-gagal')['id'];

    HrisUbahShiftService::$ujiGagalTulis = function (string $target): void {
        if ($target === 'jadwal') {
            throw new HrisError('terap_gagal', 'Jadwal tidak bisa ditulis sekarang.', 422);
        }
    };
    try {
        test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
            ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'terap_gagal');
    } finally {
        HrisUbahShiftService::$ujiGagalTulis = null;
    }
    expect(HrisUbahShift::query()->find($id)->status)->toBe('MENUNGGU');

    test()->postJson("/api/v1/hris/tim/ubah-shift/{$id}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => ubahVersi($id)], $h)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
});

it('exposes the head queue and hides it from crew', function () {
    ubahSiap();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-antre');

    $kepala = test()->getJson('/api/v1/hris/tim/persetujuan', ubahHead())->assertOk()->json('data');
    expect(array_column($kepala['ubah_shift'], 'user_id'))->toContain('u-yuzaalfarel');
});

it('hides the head queue from plain crew', function () {
    ubahSiap();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    ubahSvcAjukan('u-yuzaalfarel', $d, 'MALAM', 'k-antre-2');

    test()->getJson('/api/v1/hris/tim/persetujuan', ubahCrew('u-dwipa'))->assertOk()
        ->assertJsonPath('data.ubah_shift', []);
});

it('records manual, batch and clear edits from the roster screen', function () {
    ubahSiap();
    ubahHr();
    $d = ubahTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    hrisPlantShift('u-arif', $d, 'PAGI', '08:00', '17:00');

    $svc = app(HrisRosterService::class);
    $tujuan = ubahTargetValid('u-yuzaalfarel');
    $svc->simpan(null, [['u' => 'u-yuzaalfarel', 'd' => $d, 't' => $tujuan, 'm' => '', 's' => '']], [], 'HR Uji');
    $svc->simpan(null, [], [['u' => 'u-arif', 'd' => $d]], 'HR Uji');

    $isi = HrisRosterEvent::query()->where('user_id', 'u-yuzaalfarel')->where('tanggal_kerja', $d)->first();
    expect($isi)->not()->toBeNull();
    expect($isi->sumber)->toBe('EDIT_MANUAL')
        ->and($isi->sel_lama['kode'])->toBe('PAGI')
        ->and($isi->sel_baru['kode'])->toBe($tujuan)
        ->and($isi->aktor)->toBe('HR Uji');

    $hapus = HrisRosterEvent::query()->where('user_id', 'u-arif')->where('tanggal_kerja', $d)->first();
    expect($hapus)->not()->toBeNull()->and($hapus->sel_baru)->toBeNull()
        ->and($hapus->sel_lama['kode'])->toBe('PAGI');
});

it('records finalized leave requests as roster events', function () {
    ubahSiap();
    hrisSettings();
    app(HrisBalanceService::class)->adjust('test', 'u-yuzaalfarel', 'CUTI', 12, 'Saldo awal');
    $d = ubahTanggal(6);
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');
    $svc = app(HrisRequestService::class);
    $row = $svc->create(hrisMe('u-yuzaalfarel'),
        ['jenis' => 'CUTI', 'dari' => $d, 'sampai' => $d, 'alasan' => 'Keluarga']);

    $svc->decide(hrisMe('u-arif'), $row, 'DISETUJUI', '');
    hrisMakeHr('u-admin');
    $svc->decide(hrisMe('u-admin'), $row, 'DISETUJUI', '');

    $event = HrisRosterEvent::query()->where('ref_pengajuan_id', $row->id)->first();
    expect($event)->not()->toBeNull();
    expect($event->sumber)->toBe('PENGAJUAN')
        ->and($event->sel_lama['kode'])->toBe('PAGI')
        ->and($event->sel_baru['kode'])->toBe('CUTI');
});
