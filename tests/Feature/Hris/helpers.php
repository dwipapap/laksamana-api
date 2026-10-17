<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisSettingsService;
use App\Modules\Hris\Services\PunchRules;
use App\Modules\Jadwal\Services\JadwalService;
use Carbon\CarbonImmutable;

/*
 * Shared fixtures for the HRIS B1 tests. HRIS is greenfield on `core`;
 * Jadwal/account reads go through their own services on whatever storage
 * they are on. One Sanctum identity per test (the guard caches its user),
 * so cross-identity setup goes through the services directly.
 */

/** Grant `hris` and return a Sanctum token for the user. */
function hrisToken(string $uid): string
{
    app(AccountService::class)->setModuleAccessCore('test', $uid, 'hris', true);

    return loginAs(officeUser($uid));
}

/** Make the user HR: a module admin of hris (plus the module grant). */
function hrisMakeHr(string $uid): void
{
    app(AccountService::class)->setModuleAccessCore('test', $uid, 'hris', true);
    app(AccountRepository::class)->grantAdmin($uid, 'hris');
    app(OfficeAccess::class)->forgetUser($uid);
}

/** Reset HRIS settings to known defaults, with overrides. */
function hrisSettings(array $over = []): array
{
    return app(HrisSettingsService::class)->save($over + [
        'awalMenit' => 180, 'akhirMenit' => 180, 'toleransiTelat' => 5,
        'akurasiMaks' => 100, 'pulangLewatMenit' => 180,
        'durasiKerja' => ['default' => 540, 'orang' => []],
        'tanpaShiftBoleh' => false, 'hr' => [],
    ]);
}

/**
 * Deterministic per-user face descriptor. Each user takes the next row of
 * the 16x16 Sylvester-Hadamard matrix (entries repeated 8x to 128 dims):
 * the same user always matches (distance 0) while two users agree in
 * exactly half the entries (cosine distance 1.0, far above the 0.4
 * threshold), so the enrol duplicate check never misfires in tests.
 */
function hrisWajahSeed(string $uid): array
{
    static $idx = [];
    if (! isset($idx[$uid])) {
        if (count($idx) >= 16) {
            throw new RuntimeException('hrisWajahSeed: pattern pool exhausted');
        }
        $idx[$uid] = count($idx);
    }
    $k = $idx[$uid];
    $out = [];
    for ($j = 0; $j < 16; $j++) {
        $v = 1;
        for ($b = 0; $b < 4; $b++) {
            if ((($k >> $b) & 1) === 1 && ((($j >> $b) & 1) === 1)) {
                $v = -$v;
            }
        }
        for ($r = 0; $r < 8; $r++) {
            $out[] = $v === 1 ? 0.5 : -0.5;
        }
    }

    return $out;
}

/** Enrol the user once (no-op when already enrolled). Needs Storage::fake('local') first. */
function hrisEnrolOnce(string $uid): void
{
    $svc = app(HrisAttendanceService::class);
    if ($svc->faceEnrolled($uid)) {
        return;
    }
    $svc->enrol(hrisMe($uid), ['descriptor' => hrisWajahSeed($uid),
        'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
}

/** Punch input carrying the user's own (matching) face. */
function hrisWajahPunch(string $uid, string $arah = 'MASUK', array $extra = []): array
{
    return ['arah' => $arah, 'descriptor' => hrisWajahSeed($uid), 'model' => 'sface-2021dec-int8'] + $extra;
}

/** A constant face descriptor (same person) or its negation (another person). */
function hrisDescriptor(float $v = 0.5, int $n = 128): array
{
    return array_fill(0, $n, $v);
}

/** Epoch ms of a WIB wall-clock time (deterministic punch times). */
function hrisMs(int $y, int $mo, int $d, int $h, int $mi): int
{
    return (int) (CarbonImmutable::create($y, $mo, $d, $h, $mi, 0, 'Asia/Jakarta')->timestamp * 1000);
}

/** Y-m-d of a WIB wall-clock time. */
function hrisDate(int $y, int $mo, int $d): string
{
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Plant a Jadwal cell as an admin (fabricated admin identity, pure array check). */
function hrisPlantShift(string $uid, string $date, string $kode = 'PAGI', string $m = '08:00', string $s = '17:00'): void
{
    app(JadwalService::class)->saveCells(
        [['u' => $uid, 'd' => $date, 't' => $kode, 'm' => $m, 's' => $s]],
        [],
        'test',
        ['id' => 'u-admin', 'name' => 'Admin', 'adminModules' => ['*']],
    );
}

/** Remove any Jadwal cell of the user on the date. */
function hrisClearShift(string $uid, string $date): void
{
    app(JadwalService::class)->saveCells(
        [],
        [['u' => $uid, 'd' => $date]],
        'test',
        ['id' => 'u-admin', 'name' => 'Admin', 'adminModules' => ['*']],
    );
}

/** A crew member in u-arif's bar division (or null when the dump has none). */
function hrisBarCrew(): ?string
{
    foreach (app(JadwalService::class)->rosterWithDivisi() as $x) {
        $x = (array) $x;
        if (($x['divisi'] ?? '') === 'bar' && ($x['id'] ?? '') !== '' && $x['id'] !== 'u-arif') {
            return (string) $x['id'];
        }
    }

    return null;
}

/** Clear every roster cell of the user in the month, so tests own the denominator. */
function hrisClearMonth(string $uid, string $bulan): void
{
    $rows = app(JadwalService::class)
        ->shiftRange($uid, $bulan.'-01', date('Y-m-t', strtotime($bulan.'-01')))['rows'] ?? [];
    foreach ($rows as $r) {
        hrisClearShift($uid, (string) ($r['d'] ?? ''));
    }
}

/** Where the punch of the token holder lands, for service-level calls. */
function hrisMe(string $uid): array
{
    $u = officeUser($uid);

    return ['id' => $uid, 'name' => $u['name']];
}

/** Today's WIB date on the server clock. */
function hrisToday(): string
{
    return PunchRules::tglWib(PunchRules::ms());
}

/** A tiny base64 "photo": enrolment requires one (A3). Fake the disk first. */
function hrisFoto(): string
{
    return base64_encode("\xFF\xD8\xFF\xE0test-jpeg\xFF\xD9");
}
