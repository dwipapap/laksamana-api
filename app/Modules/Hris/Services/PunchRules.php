<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Pure punch rules, copied as concepts from AbsensiService (its own logic —
 * HRIS keeps its own punches and must not import that class).
 *
 * Everything here is static and side-effect free so it is unit-tested on
 * fixed inputs: haversine radius, the shift window with tolerances, overnight
 * shifts, the 18-hour PULANG lookup, and the queue-reason precedence.
 *
 * Time is server time rendered as WIB (Asia/Jakarta), never the phone clock.
 */
final class PunchRules
{
    // ------------------------------------------------------------ time (WIB)

    public static function ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    public static function wibMs(?int $epochMs = null): int
    {
        return ($epochMs ?? self::ms()) + 7 * 3600 * 1000;
    }

    public static function jamWib(int $epochMs): string
    {
        return gmdate('H:i', (int) floor(self::wibMs($epochMs) / 1000));
    }

    public static function tglWib(int $epochMs): string
    {
        return gmdate('Y-m-d', (int) floor(self::wibMs($epochMs) / 1000));
    }

    public static function menitWib(int $epochMs): int
    {
        $d = (int) floor(self::wibMs($epochMs) / 1000);

        return ((int) gmdate('H', $d)) * 60 + ((int) gmdate('i', $d));
    }

    public static function jamKeMenit(mixed $hhmm): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', self::s($hhmm), $m)) {
            return -1;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return -1;
        }

        return $h * 60 + $i;
    }

    /** Strict YYYY-MM-DD (a DATE column would silently coerce '2026-13-45'). */
    public static function tglValid(mixed $v): string
    {
        $v = self::s($v);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : '';
    }

    // ------------------------------------------------------------ location

    /**
     * Earth distance in metres (haversine). Its error is far below phone GPS
     * accuracy, so a fancier formula would not change a single decision.
     */
    public static function haversineMeters(mixed $lat1, mixed $lng1, mixed $lat2, mixed $lng2): int
    {
        $R = 6371000.0;
        $p1 = deg2rad((float) $lat1);
        $p2 = deg2rad((float) $lat2);
        $dp = deg2rad((float) $lat2 - (float) $lat1);
        $dl = deg2rad((float) $lng2 - (float) $lng1);
        $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);

        return (int) round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** Radius clamped to 30–2000 m: phone GPS indoors is rarely better than 30 m. */
    public static function clampRadius(mixed $v): int
    {
        return max(30, min(2000, (int) $v));
    }

    /**
     * Nearest location, skipping placeholder 0,0 rows. null when none is
     * registered yet — distinct from "far from every location".
     *
     * @param  array<int,array{lat:float,lng:float}>  $locations
     * @return array{lokasi:array,jarak:int}|null
     */
    public static function nearestLocation(mixed $lat, mixed $lng, array $locations): ?array
    {
        $best = null;
        foreach ($locations as $l) {
            if ((float) ($l['lat'] ?? 0) == 0 && (float) ($l['lng'] ?? 0) == 0) {
                continue;
            }
            $j = self::haversineMeters($lat, $lng, $l['lat'], $l['lng']);
            if ($best === null || $j < $best['jarak']) {
                $best = ['lokasi' => $l, 'jarak' => $j];
            }
        }

        return $best;
    }

    // ------------------------------------------------------------ shifts

    /**
     * Is $menit inside the shift range + tolerances? A shift ending at or
     * before its start (18:00–02:00) runs past midnight — without this, every
     * night-shift crew queues for approval daily.
     */
    public static function dalamRentangShift(int $menit, mixed $mulai, mixed $selesai, int $awal, int $akhir): bool
    {
        $m = self::jamKeMenit($mulai);
        $s = self::jamKeMenit($selesai);
        if ($m < 0 || $s < 0) {
            return false;
        }
        if ($s <= $m) {
            $s += 1440;
        }
        $a = $m - $awal;
        $b = $s + $akhir;
        if ($menit >= $a && $menit <= $b) {
            return true;
        }
        $menit2 = $menit + 1440;

        return $menit2 >= $a && $menit2 <= $b;
    }

    /**
     * The WORK DATE of a PULANG punch: night-shift crew punch out on the next
     * wall-clock date, but their work day is the date they punched in. The
     * 18-hour lookup itself lives in HrisAttendanceService.
     */
    public static function workDateForPulang(?string $lastMasukTgl, string $hariIni): string
    {
        return ($lastMasukTgl !== null && $lastMasukTgl !== '') ? $lastMasukTgl : $hariIni;
    }

    /**
     * Which queue reason, if any — in legacy precedence order. '' means the
     * punch is VALID. Pure so the precedence is unit-tested on fixed inputs.
     *
     * HARI_LIBUR means the person's OWN schedule says off that day (the
     * shift's libur flag). A public holiday in the HRIS calendar is NOT a
     * queue reason: crew routinely work on holidays, and that work earns PH
     * (L3) instead of waiting for an HR decision.
     *
     * @param  array{kode:string,mulai:string,selesai:string,sumber:string,libur:int}|null|false  $shift
     */
    public static function tentukanAlasan(
        bool $dalamArea,
        array|null|false $shift,
        bool $dalamShift,
        bool $wajahHalangi,
        bool $wajahTerdaftar,
        bool $tanpaShiftBoleh,
    ): string {
        if (! $dalamArea) {
            return 'LUAR_AREA';
        }
        if ($shift === false) {
            // The roster could not be read: we know nothing, so the punch is
            // recorded but NOT queued (a neighbour outage must not send the
            // whole company to HR approval with no reason given).
            return '';
        }
        if ($shift === null) {
            if (! $tanpaShiftBoleh) {
                return 'TANPA_SHIFT';
            }
        } elseif (! empty($shift['libur'])) {
            return 'HARI_LIBUR';
        } elseif (! $dalamShift) {
            return 'LUAR_SHIFT';
        }
        // WAJAH = enrolled but mismatched (HR suspicion); WAJAH_KOSONG =
        // never enrolled (an admin chore, not suspicion).
        if ($wajahHalangi) {
            return $wajahTerdaftar ? 'WAJAH' : 'WAJAH_KOSONG';
        }

        return '';
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }
}
