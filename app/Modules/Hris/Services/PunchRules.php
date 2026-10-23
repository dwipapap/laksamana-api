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
     * Inside the attendance area (A13): within the radius AND with a sane
     * fix, 1 <= akurasi <= akurasiMaks. A missing or zero accuracy is
     * outside — there is no `jarak - akurasi` slack.
     */
    public static function dalamArea(int $jarakM, int $radiusM, mixed $akurasi, int $akurasiMaks): bool
    {
        if (! is_numeric($akurasi)) {
            return false;
        }
        $akurasi = (int) $akurasi;

        return $jarakM <= $radiusM && $akurasi >= 1 && $akurasi <= $akurasiMaks;
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
     * Is a MASUK punch inside its VALID window (A20, A21):
     * [mulai - awalMenit, selesai], overnight-aware. Late inside the shift
     * stays VALID (see telatMenit); earlier than the window or after
     * `selesai` is LUAR_SHIFT.
     */
    /**
     * Blocks of a shift definition (J4, ADR-0012): `blok` [{m, s}, …] with
     * at least two valid entries, else [] (a plain one-block shift).
     *
     * @return array<int,array{mulai:string,selesai:string}>
     */
    public static function blokDari(mixed $def): array
    {
        $raw = is_array($def) && is_array($def['blok'] ?? null) ? $def['blok'] : [];
        $out = [];
        foreach ($raw as $b) {
            $b = is_array($b) ? $b : [];
            $m = self::s($b['m'] ?? '');
            $sl = self::s($b['s'] ?? '');
            if (self::jamKeMenit($m) < 0 || self::jamKeMenit($sl) < 0) {
                return [];
            }
            $out[] = ['mulai' => $m, 'selesai' => $sl];
        }

        return count($out) >= 2 ? $out : [];
    }

    /**
     * Which block a MASUK at $menit belongs to: the boundary between two
     * blocks is the middle of the gap between them. 1-based; 1 without blocks.
     *
     * @param  array<int,array{mulai:string,selesai:string}>  $blok
     */
    public static function blokUntuk(array $blok, int $menit): int
    {
        for ($i = 0; $i < count($blok) - 1; $i++) {
            $akhir = self::jamKeMenit($blok[$i]['selesai']);
            $mulaiBerikut = self::jamKeMenit($blok[$i + 1]['mulai']);
            if ($menit < intdiv($akhir + $mulaiBerikut, 2)) {
                return $i + 1;
            }
        }

        return max(1, count($blok));
    }

    public static function masukDalamJendela(int $menitDatang, mixed $mulai, mixed $selesai, int $awalMenit): bool
    {
        $m = self::jamKeMenit($mulai);
        $s = self::jamKeMenit($selesai);
        if ($m < 0 || $s < 0) {
            return false;
        }
        $akhir = $s <= $m ? $s + 1440 : $s;
        $awal = $m - $awalMenit;
        if ($menitDatang >= $awal && $menitDatang <= $akhir) {
            return true;
        }
        $datang2 = $menitDatang + 1440;

        return $datang2 >= $awal && $datang2 <= $akhir;
    }

    /**
     * Minutes late vs mulai + toleransiTelat (A21), overnight-aware: a
     * post-midnight arrival belongs to the evening shift that is still
     * running. Early arrivals count 0, never negative.
     */
    public static function telatMenit(int $menitDatang, mixed $mulai, int $toleransiTelat): int
    {
        $m = self::jamKeMenit($mulai);
        if ($m < 0) {
            return 0;
        }
        if ($menitDatang + 720 < $m) {
            $menitDatang += 1440;
        }

        return max(0, $menitDatang - ($m + $toleransiTelat));
    }

    /** Shift length in minutes (+1440 past midnight), or -1 when unreadable. */
    public static function durasiShiftMenit(mixed $mulai, mixed $selesai): int
    {
        $m = self::jamKeMenit($mulai);
        $s = self::jamKeMenit($selesai);
        if ($m < 0 || $s < 0) {
            return -1;
        }

        return $s <= $m ? $s + 1440 - $m : $s - $m;
    }

    /**
     * Work-duration target in minutes (A22), from the MASUK row's shift
     * snapshot — never a re-read. Order: the JADWAL snapshot duration,
     * else the per-person override, else the BAWAAN snapshot duration,
     * else the configured default.
     *
     * @param  array{default:int,orang:array<string,int>}|mixed  $durasiKerja
     */
    public static function targetDurasiMenit(?string $sumber, mixed $mulai, mixed $selesai, mixed $durasiKerja, string $userId): int
    {
        $kerja = is_array($durasiKerja) ? $durasiKerja : [];
        $default = (int) ($kerja['default'] ?? 540);
        if ($default <= 0) {
            $default = 540;
        }
        $orang = (isset($kerja['orang']) && is_array($kerja['orang'])) ? $kerja['orang'] : [];
        if ($sumber === 'JADWAL') {
            $d = self::durasiShiftMenit($mulai, $selesai);
            if ($d > 0) {
                return $d;
            }
        } elseif (isset($orang[$userId]) && (int) $orang[$userId] >= 60) {
            return (int) $orang[$userId];
        }
        if ($sumber === 'BAWAAN') {
            $d = self::durasiShiftMenit($mulai, $selesai);
            if ($d > 0) {
                return $d;
            }
        }

        return $default;
    }

    /** The rule branch that supplies one target, with the same priority as targetDurasiMenit. */
    public static function targetDurasiAsal(?string $sumber, mixed $mulai, mixed $selesai, mixed $durasiKerja, string $userId): string
    {
        $kerja = is_array($durasiKerja) ? $durasiKerja : [];
        $d = self::durasiShiftMenit($mulai, $selesai);
        $orang = isset($kerja['orang']) && is_array($kerja['orang']) ? $kerja['orang'] : [];
        if ($sumber === 'JADWAL' && $d > 0) {
            return 'ROSTER';
        }
        if ($sumber !== 'JADWAL' && (int) ($orang[$userId] ?? 0) >= 60) {
            return 'PER_ORANG';
        }
        if ($sumber === 'BAWAAN' && $d > 0) {
            return 'SHIFT_BAWAAN';
        }

        return 'CADANGAN';
    }

    /**
     * Judge a PULANG duration (A23, A24).
     *
     * @return array{terlambat:bool,pulang_awal:bool,kurang_menit:int}
     */
    public static function nilaiPulang(int $durasiMenit, int $targetMenit, int $pulangLewatMenit): array
    {
        if ($durasiMenit > $targetMenit + $pulangLewatMenit) {
            return ['terlambat' => true, 'pulang_awal' => false, 'kurang_menit' => 0];
        }
        if ($durasiMenit < $targetMenit) {
            return ['terlambat' => false, 'pulang_awal' => true, 'kurang_menit' => $targetMenit - $durasiMenit];
        }

        return ['terlambat' => false, 'pulang_awal' => false, 'kurang_menit' => 0];
    }

    /**
     * Suspicion flags for one punch (A16). Pure: the caller supplies the
     * matched location, the person's previous punch and whether another
     * user sent the same point within ±5 minutes. NEVER a queue reason —
     * HR eyes only.
     *
     * @param  array{lat:float,lng:float}|null  $lokasi
     * @param  array{lat:float,lng:float}|null  $sebelumnya
     * @return array<int,string>
     */
    public static function tandaCuriga(float $lat, float $lng, int $akurasi, ?array $lokasi, ?array $sebelumnya, bool $kembar): array
    {
        $out = [];
        if ($sebelumnya !== null
            && abs($lat - (float) ($sebelumnya['lat'] ?? 0)) < 1e-9
            && abs($lng - (float) ($sebelumnya['lng'] ?? 0)) < 1e-9) {
            $out[] = 'KOORDINAT_SAMA';
        }
        if ($lokasi !== null && self::haversineMeters($lat, $lng, $lokasi['lat'], $lokasi['lng']) === 0) {
            $out[] = 'TITIK_PUSAT';
        }
        if ($akurasi <= 1 || $akurasi % 100 === 0) {
            $out[] = 'AKURASI_JANGGAL';
        }
        if ($kembar) {
            $out[] = 'KOORDINAT_KEMBAR';
        }

        return $out;
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
     * Which queue reason, if any — '' means the punch is VALID. Pure so the
     * precedence is unit-tested on fixed inputs.
     *
     * Order: LUAR_AREA → WAJAH (enrolled but mismatched) → TANPA_MASUK
     * (PULANG with no MASUK ≤ 18 h earlier) → roster unreadable (recorded,
     * never queued) → TANPA_SHIFT → HARI_LIBUR → LUAR_SHIFT → WAJAH_KOSONG
     * (never enrolled: an admin chore, not suspicion).
     *
     * WAJAH and TANPA_MASUK are decided BEFORE the roster-unreadable
     * short-circuit: with shift === false we know nothing about the roster,
     * but a stranger's face or a missing MASUK is known regardless.
     *
     * HARI_LIBUR means the person's OWN schedule says off that day (the
     * shift's libur flag). A public holiday in the HRIS calendar is NOT a
     * queue reason: crew routinely work on holidays, and that work earns PH
     * (L3) instead of waiting for an HR decision.
     *
     * $luarWaktu is the time rule for the punch direction: for MASUK,
     * outside [mulai - awalMenit, selesai]; for PULANG, longer than
     * target + pulangLewatMenit.
     *
     * @param  array{kode:string,mulai:string,selesai:string,sumber:string,libur:int}|null|false  $shift
     */
    public static function tentukanAlasan(
        bool $dalamArea,
        array|null|false $shift,
        bool $wajahHalangi,
        bool $wajahTerdaftar,
        bool $tanpaMasuk,
        bool $tanpaShiftBoleh,
        bool $luarWaktu,
    ): string {
        if (! $dalamArea) {
            return 'LUAR_AREA';
        }
        // An enrolled face that does NOT match is fraud suspicion: surface it
        // ahead of rostering reasons, or HR approves a stranger's punch as a
        // mere scheduling issue.
        if ($wajahHalangi && $wajahTerdaftar) {
            return 'WAJAH';
        }
        if ($tanpaMasuk) {
            return 'TANPA_MASUK';
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
        } elseif ($luarWaktu) {
            return 'LUAR_SHIFT';
        }
        // Whoever was never enrolled queues last, as an admin chore.
        if ($wajahHalangi) {
            return 'WAJAH_KOSONG';
        }

        return '';
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }
}
