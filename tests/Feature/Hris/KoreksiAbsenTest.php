<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisKoreksiAbsen;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisKoreksiAbsenService;

require_once __DIR__.'/helpers.php';

/*
 * Attendance corrections: a crew member files a missing MASUK/PULANG for a
 * past work date with a reason; the division head decides (HR fallback),
 * and approval writes the punch row. Nothing lands in hris_ketukan while
 * MENUNGGU. One HTTP identity per test.
 */

function koreksiSiap(): void
{
    app(HrisImporKaryawanService::class)->impor('test');
}

function koreksiCrew(string $uid = 'u-yuzaalfarel'): array
{
    return ['Authorization' => 'Bearer '.hrisToken($uid)];
}

function koreksiHead(): array
{
    return ['Authorization' => 'Bearer '.hrisToken('u-arif')];
}

function koreksiHr(): array
{
    hrisMakeHr('u-admin');

    return ['Authorization' => 'Bearer '.hrisToken('u-admin')];
}

function koreksiKemarin(int $mundur = 1): string
{
    return date('Y-m-d', strtotime(hrisToday()." -{$mundur} day"));
}

function koreksiAjukan(array $h, string $tgl, string $arah = 'PULANG', string $kunci = 'k-1'): array
{
    return test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => $tgl, 'arah' => $arah, 'jam' => '18:05',
            'alasan' => 'Lupa clock-out, HP mati', 'idempotency_key' => $kunci], $h)->json('data');
}

/** File through the service (for head/HR-HTTP tests): one HTTP identity per test. */
function koreksiSvcAjukan(string $uid, string $tgl, string $arah, string $jam, string $kunci): array
{
    return app(HrisKoreksiAbsenService::class)->ajukan(hrisMe($uid),
        ['tanggal_kerja' => $tgl, 'arah' => $arah, 'jam' => $jam,
            'alasan' => 'Lupa clock-out uji', 'idempotency_key' => $kunci]);
}

function koreksiVersi(string $id): int
{
    return (int) HrisKoreksiAbsen::query()->find($id)->version;
}

it('files a correction without touching punches', function () {
    koreksiSiap();
    $h = koreksiCrew();
    $tgl = koreksiKemarin();

    $r = test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => $tgl, 'arah' => 'PULANG', 'jam' => '18:05',
            'alasan' => 'Lupa clock-out', 'idempotency_key' => 'k-1'], $h)
        ->assertCreated()->json('data');

    expect($r['status'])->toBe('MENUNGGU')
        ->and($r['tanggal_kerja'])->toBe($tgl)
        ->and($r['arah'])->toBe('PULANG')
        ->and($r['jam'])->toBe('18:05');
    expect(HrisKetukan::query()->where('user_id', 'u-yuzaalfarel')->where('tanggal_kerja', $tgl)->count())->toBe(0);
});

it('refuses unfilable correction submits with clear messages', function () {
    koreksiSiap();
    $h = koreksiCrew();

    // Future dates are not corrections.
    test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => date('Y-m-d', strtotime(hrisToday().' +1 day')), 'arah' => 'PULANG',
            'jam' => '18:05', 'alasan' => 'x', 'idempotency_key' => 'k-depan'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'tanggal_masa_depan');

    // Older than the 7-day window goes to HR directly.
    test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => date('Y-m-d', strtotime(hrisToday().' -8 days')), 'arah' => 'PULANG',
            'jam' => '18:05', 'alasan' => 'x', 'idempotency_key' => 'k-lama'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'terlalu_lama');

    // Bad direction and clock time.
    test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => koreksiKemarin(), 'arah' => 'ISTIRAHAT',
            'jam' => '18:05', 'alasan' => 'x', 'idempotency_key' => 'k-arah'], $h)
        ->assertStatus(422);
    test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => koreksiKemarin(), 'arah' => 'PULANG',
            'jam' => '25:70', 'alasan' => 'x', 'idempotency_key' => 'k-jam'], $h)
        ->assertStatus(422);

    // A recorded punch settles the date+direction first.
    $tgl = koreksiKemarin();
    HrisKetukan::query()->create(['user_id' => 'u-yuzaalfarel', 'tanggal_kerja' => $tgl, 'arah' => 'PULANG', 'waktu' => 1]);
    test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => $tgl, 'arah' => 'PULANG',
            'jam' => '18:05', 'alasan' => 'x', 'idempotency_key' => 'k-ketuk'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'sudah_ada_ketukan');
    expect(HrisKoreksiAbsen::query()->where('user_id', 'u-yuzaalfarel')->count())->toBe(0);
});

it('replays idempotency keys and blocks a second pending request', function () {
    koreksiSiap();
    $h = koreksiCrew();
    $tgl = koreksiKemarin();

    $satu = koreksiAjukan($h, $tgl, 'PULANG', 'k-sama');
    $dua = test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => $tgl, 'arah' => 'PULANG', 'jam' => '18:05',
            'alasan' => 'Lupa clock-out', 'idempotency_key' => 'k-sama'], $h)
        ->assertOk()->json('data');
    expect($dua['id'])->toBe($satu['id']);

    test()->postJson('/api/v1/hris/tim/koreksi-absen',
        ['tanggal_kerja' => $tgl, 'arah' => 'PULANG', 'jam' => '19:00',
            'alasan' => 'Lain', 'idempotency_key' => 'k-lain'], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'sudah_menunggu');
});

it('lets the head approve, writing the punch row, and rejects without a note', function () {
    koreksiSiap();
    $h = koreksiHead();
    $tgl = koreksiKemarin();
    $r = koreksiSvcAjukan('u-yuzaalfarel', $tgl, 'PULANG', '18:05', 'k-putus');

    // Rejection requires a note.
    test()->postJson("/api/v1/hris/tim/koreksi-absen/{$r['id']}/putuskan",
        ['status' => 'DITOLAK', 'berubah' => $r['berubah']], $h)
        ->assertStatus(422);

    // The head may not decide outside scope (u-adit heads elsewhere).
    $lain = koreksiSvcAjukan('u-adit', $tgl, 'PULANG', '18:06', 'k-luar-scope');
    test()->postJson("/api/v1/hris/tim/koreksi-absen/{$lain['id']}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => $lain['berubah']], $h)
        ->assertStatus(403);

    $hasil = test()->postJson("/api/v1/hris/tim/koreksi-absen/{$r['id']}/putuskan",
        ['status' => 'DISETUJUI', 'catatan' => 'Sesuai absen', 'berubah' => $r['berubah']], koreksiHead())
        ->assertOk()->json('data');
    expect($hasil['status'])->toBe('DISETUJUI');

    $ketuk = HrisKetukan::query()->where('user_id', 'u-yuzaalfarel')
        ->where('tanggal_kerja', $tgl)->where('arah', 'PULANG')->firstOrFail();
    // VALID, so the meal allowance and Kehadiran count the corrected day.
    expect($ketuk->status)->toBe('VALID');
    // Clock time lands on the requested date in WIB.
    expect(date('Y-m-d H:i', (int) ($ketuk->waktu / 1000) + 7 * 3600))->toBe("{$tgl} 18:05");

    // Decided twice with the same verdict answers the stored row.
    test()->postJson("/api/v1/hris/tim/koreksi-absen/{$r['id']}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => $hasil['berubah']], $h)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
});

it('refuses approval over a punch recorded in between', function () {
    koreksiSiap();
    $h = koreksiHead();
    $tgl = koreksiKemarin();
    $r = koreksiSvcAjukan('u-yuzaalfarel', $tgl, 'MASUK', '08:00', 'k-tengah');

    HrisKetukan::query()->create(['user_id' => 'u-yuzaalfarel', 'tanggal_kerja' => $tgl, 'arah' => 'MASUK', 'waktu' => 1]);
    test()->postJson("/api/v1/hris/tim/koreksi-absen/{$r['id']}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => $r['berubah']], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'sudah_ada_ketukan');
    expect(HrisKoreksiAbsen::query()->find($r['id'])->status)->toBe('MENUNGGU');
});

it('lets the requester cancel their own pending correction', function () {
    koreksiSiap();
    $h = koreksiCrew();
    $tgl = koreksiKemarin();
    $r = koreksiSvcAjukan('u-yuzaalfarel', $tgl, 'PULANG', '18:05', 'k-batal');

    $batal = test()->postJson("/api/v1/hris/tim/koreksi-absen/{$r['id']}/batal",
        ['berubah' => $r['berubah']], $h)->assertOk()->json('data');
    expect($batal['status'])->toBe('DIBATALKAN');

    // A cancelled row cannot be decided anymore (final verdict stands).
    $segar = HrisKoreksiAbsen::query()->find($r['id']);
    try {
        app(HrisKoreksiAbsenService::class)->putuskan(hrisMe('u-arif'), $r['id'],
            ['status' => 'DISETUJUI', 'berubah' => (int) $segar->version]);
        expect(false)->toBeTrue('a cancelled correction must stay final');
    } catch (HrisError $e) {
        expect($e->status)->toBe(409)->and($e->errorCode)->toBe('already_decided');
    }
});

it('exposes the head queue and hides it from plain crew', function () {
    koreksiSiap();
    $tgl = koreksiKemarin();
    koreksiSvcAjukan('u-yuzaalfarel', $tgl, 'PULANG', '18:05', 'k-antre');

    $kepala = test()->getJson('/api/v1/hris/tim/persetujuan', koreksiHead())->assertOk()->json('data');
    expect(collect($kepala['koreksi'])->pluck('tanggal_kerja'))->toContain($tgl);
    expect($kepala['ubah_shift'])->toBeArray();

    // Plain crew sees nothing (service-level: the HTTP identity stays head).
    $kru = app(HrisKoreksiAbsenService::class)->antreanKepala(hrisMe('u-dwipa'));
    expect($kru)->toBe([]);
});

it('lets HR list and decide when the requester is a head themself', function () {
    koreksiSiap();
    // u-adit heads a division, so no head may decide — not even self.
    $tgl = koreksiKemarin();
    $r = koreksiSvcAjukan('u-adit', $tgl, 'MASUK', '08:00', 'k-hr');

    try {
        app(HrisKoreksiAbsenService::class)->putuskan(hrisMe('u-adit'), $r['id'],
            ['status' => 'DISETUJUI', 'berubah' => $r['berubah']]);
        expect(false)->toBeTrue('self-approval must fail');
    } catch (HrisError $e) {
        expect($e->status)->toBe(403)->and($e->errorCode)->toBe('forbidden');
    }

    $hr = koreksiHr();
    $daftar = test()->getJson('/api/v1/hris/kelola/koreksi-absen', $hr)->assertOk()->json();
    expect($daftar['data'])->not()->toBe([]);
    expect($daftar['meta']['menunggu_hr'])->not()->toBe([]);

    $hasil = test()->postJson("/api/v1/hris/kelola/koreksi-absen/{$r['id']}/putuskan",
        ['status' => 'DISETUJUI', 'berubah' => $r['berubah']], $hr)
        ->assertOk()->json('data');
    expect($hasil['status'])->toBe('DISETUJUI');
    expect(HrisKetukan::query()->where('user_id', 'u-adit')->where('tanggal_kerja', $tgl)->count())->toBe(1);
});

it('flags an unclosed yesterday MASUK in me/today', function () {
    koreksiSiap();
    $tgl = koreksiKemarin();
    HrisKetukan::query()->create(['user_id' => 'u-yuzaalfarel', 'tanggal_kerja' => $tgl,
        'arah' => 'MASUK', 'waktu' => (int) (strtotime("{$tgl} 22:00:00") * 1000) - 7 * 3600 * 1000]);

    $today = test()->getJson('/api/v1/hris/me/today', koreksiCrew())->assertOk()->json('data');
    expect($today['sisaKemarin']['tanggal_kerja'])->toBe($tgl);
    expect($today['sisaKemarin']['arah'])->toBe('PULANG');

    // Closing it clears the flag.
    HrisKetukan::query()->create(['user_id' => 'u-yuzaalfarel', 'tanggal_kerja' => $tgl, 'arah' => 'PULANG', 'waktu' => 2]);
    $today = test()->getJson('/api/v1/hris/me/today', koreksiCrew())->assertOk()->json('data');
    expect($today['sisaKemarin'])->toBeNull();
});
