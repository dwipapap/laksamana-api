<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Modules\Hris\Services\HrisSettingsService;
use App\Modules\Hris\Services\PunchRules;
use App\Modules\Jadwal\Services\JadwalService;

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
        'awalMenit' => 60, 'akhirMenit' => 180, 'toleransiTelat' => 5,
        'tanpaShiftBoleh' => false, 'wajahWajib' => false, 'hr' => [],
    ]);
}

/** A constant face descriptor (same person) or its negation (another person). */
function hrisDescriptor(float $v = 0.5, int $n = 128): array
{
    return array_fill(0, $n, $v);
}

/** Epoch ms of a WIB wall-clock time (deterministic punch times). */
function hrisMs(int $y, int $mo, int $d, int $h, int $mi): int
{
    return (int) (Carbon\CarbonImmutable::create($y, $mo, $d, $h, $mi, 0, 'Asia/Jakarta')->timestamp * 1000);
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
