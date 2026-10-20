<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisMasterBawaan;
use App\Modules\Hris\Services\HrisMealService;
use App\Modules\Hris\Services\HrisShiftService;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * E3 (ADR-0012, J1–J4): the master shift in the Jadwal setting, and block
 * shifts — one MASUK/PULANG pair per block.
 */

function shiftDef(string $kode): array
{
    return JsonDoc::toArray(app(JadwalService::class)->setting())['shifts'][$kode] ?? [];
}

/** SPLIT as two blocks: 11:00–15:00 and 18:00–23:00. */
function splitDuaBlok(): void
{
    app(HrisShiftService::class)->update('SPLIT', ['blok' => [
        ['mulai' => '11:00', 'selesai' => '15:00'], ['mulai' => '18:00', 'selesai' => '23:00']]], 'test');
}

function punchJam(string $uid, string $arah, int $h, int $mi, array $extra = []): array
{
    return app(HrisAttendanceService::class)->recordPunch(hrisMe($uid),
        hrisWajahPunch($uid, $arah, $extra), hrisMs(2026, 10, 20, $h, $mi))['punch'];
}

it('creates a block shift with a generated code over the API', function () {
    app(HrisMasterBawaan::class)->pastikan();
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];

    $d = test()->postJson('/api/v1/hris/kelola/shift', ['nama' => 'Split Malam', 'warna' => '#123456',
        'blok' => [['mulai' => '10:00', 'selesai' => '14:00'], ['mulai' => '19:00', 'selesai' => '01:00']],
        'divisi' => ['cleaning']], $h)->assertCreated()->json('data');

    expect($d['kode'])->toBe('SPLITMALAM')
        ->and($d['blok'])->toHaveCount(2)
        ->and(shiftDef('SPLITMALAM'))->toMatchArray(['n' => 'Split Malam', 'm' => '10:00', 's' => '01:00',
            'divisi' => ['cleaning']]);
});

it('validates blocks, Divisi and the locked shifts', function () {
    app(HrisMasterBawaan::class)->pastikan();
    $svc = app(HrisShiftService::class);

    expect(fn () => $svc->create(['nama' => 'Tumpuk', 'blok' => [
        ['mulai' => '10:00', 'selesai' => '15:00'], ['mulai' => '14:00', 'selesai' => '20:00']]], 'test'))
        ->toThrow(HrisError::class, 'berurutan');
    expect(fn () => $svc->create(['nama' => 'Asing', 'blok' => [['mulai' => '08:00', 'selesai' => '16:00']],
        'divisi' => ['finance']], 'test'))->toThrow(HrisError::class, 'Divisi shift');
    expect(fn () => $svc->update('OFF', ['aktif' => false], 'test'))->toThrow(HrisError::class);
    expect(fn () => $svc->delete('CUTI', 'test'))->toThrow(HrisError::class);
});

it('deactivates instead of deleting a used shift, and deletes an unused one', function () {
    $svc = app(HrisShiftService::class);
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '09:00', '19:00');

    expect(fn () => $svc->delete('PAGI', 'test'))->toThrow(HrisError::class, 'Nonaktifkan');
    expect($svc->update('PAGI', ['aktif' => false], 'test')['aktif'])->toBeFalse();

    $baru = $svc->create(['nama' => 'Coba Hapus', 'blok' => [['mulai' => '08:00', 'selesai' => '12:00']]], 'test');
    $svc->delete($baru['kode'], 'test');
    expect(shiftDef($baru['kode']))->toBe([]);
});

it('records one MASUK/PULANG pair per block on a SPLIT day', function () {
    hrisSettings(['tanpaShiftBoleh' => false, 'toleransiTelat' => 5]);
    hrisEnrolOnce('u-dwipa');
    splitDuaBlok();
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'SPLIT', '11:00', '23:00');

    $m1 = punchJam('u-dwipa', 'MASUK', 10, 58);
    $p1 = punchJam('u-dwipa', 'PULANG', 15, 0);
    $m2 = punchJam('u-dwipa', 'MASUK', 18, 20);
    $p2 = punchJam('u-dwipa', 'PULANG', 23, 0);

    expect($m1)->toMatchArray(['blok' => 1, 'status' => 'VALID', 'shift_mulai' => '11:00', 'shift_selesai' => '15:00'])
        ->and($p1)->toMatchArray(['blok' => 1, 'status' => 'VALID', 'pulang_awal' => false])
        ->and($m2)->toMatchArray(['blok' => 2, 'status' => 'VALID', 'shift_mulai' => '18:00', 'telat_menit' => 15])
        ->and($p2)->toMatchArray(['blok' => 2, 'status' => 'VALID', 'target_menit' => 300])
        ->and(HrisKetukan::query()->where('user_id', 'u-dwipa')->where('tanggal_kerja', '2026-10-20')->count())->toBe(4)
        // The meal allowance counts the day once.
        ->and(app(HrisMealService::class)->hadirDates('u-dwipa', '2026-10'))->toContain('2026-10-20')
        ->and(array_count_values(app(HrisMealService::class)->hadirDates('u-dwipa', '2026-10'))['2026-10-20'])->toBe(1);

    // A second MASUK in block 2 is a duplicate, not a third block.
    $lagi = app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa', 'MASUK'), hrisMs(2026, 10, 20, 18, 30));
    expect($lagi['duplikat'])->toBeTrue();
});

it('flags a missed PULANG of block 1 and queues a PULANG without its own MASUK', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    splitDuaBlok();
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'SPLIT', '11:00', '23:00');

    $m1 = punchJam('u-dwipa', 'MASUK', 11, 0);
    punchJam('u-dwipa', 'MASUK', 18, 0);
    expect(HrisKetukan::query()->find($m1['id'])->info['pulang_terlewat'] ?? false)->toBeTrue();

    // Another crew member closes block 1, then punches PULANG in block 2 without MASUK.
    hrisEnrolOnce('u-andry');
    hrisPlantShift('u-andry', hrisDate(2026, 10, 20), 'SPLIT', '11:00', '23:00');
    punchJam('u-andry', 'MASUK', 11, 0);
    $p1 = punchJam('u-andry', 'PULANG', 15, 0);
    $p2 = punchJam('u-andry', 'PULANG', 23, 0, ['alasan' => 'lupa masuk']);
    expect($p2)->toMatchArray(['blok' => 2, 'status' => 'MENUNGGU', 'alasan_antrean' => ['TANPA_MASUK']])
        ->and(HrisKetukan::query()->find($p1['id'])->status)->toBe('VALID');
});

it('keeps a plain shift on block 1', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '09:00', '19:00');

    expect(punchJam('u-dwipa', 'MASUK', 9, 0)['blok'])->toBe(1)
        ->and(punchJam('u-dwipa', 'PULANG', 19, 0)['blok'])->toBe(1);
});
