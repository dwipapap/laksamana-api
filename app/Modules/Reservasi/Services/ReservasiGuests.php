<?php

namespace App\Modules\Reservasi\Services;

use App\Support\Modules;

/**
 * Guest summary over the WHOLE history (G-07).
 *
 * The Loyal / blacklist / autofill profile on the old screens is computed
 * over every reservation ever made, but the Vue client loads a date window,
 * so it cannot compute it. Legacy computed this server-side as `ringkasTamu`
 * (laksamana-office reservasi-mysql/lib_reservasi_mysql.php:713-748, served
 * as `?action=ringkasTamu&sebelum=` by api.php:85-87): the summary of the
 * reservations dated BEFORE the window, which the client merges with its own
 * window rows (deploy/reservasi/index.html: ringkasTamu/profilGabung).
 *
 * The rules below are a twin of that PHP: the phone normalisation
 * (rsv_norm_hp, twin of the client's normPhone), the statuses
 * (rsv_norm_status, twin of normStatus) and the entry order (created_at,
 * then id). READ-ONLY: not one write in this file.
 *
 * One entry per phone number, deliberately a compact array, not a named
 * object: in production that is thousands of entries.
 *   [0] n  [1] datang  [2] noshow  [3] member  [4] memberNo  [5] vip
 *   [6] kunjungan terakhir  [7] jumlah pax  [8] nama pertama  [9] nama terakhir
 */
final class ReservasiGuests
{
    /**
     * @return array{sebelum: ?string, tamu: array<string, array>}
     *                                                             Keys carry the legacy `k` prefix: without it PHP would turn phone
     *                                                             numbers into integer keys (and sequential ones would collapse the
     *                                                             JSON shape into an array).
     */
    public function summary(?string $before, ?string $phone = null): array
    {
        $w = ['tanggal IS NOT NULL'];
        $a = [];
        if ($before !== null && $before !== '') {
            $w[] = 'tanggal < ?';
            $a[] = $before;
        }
        $want = $phone !== null && $phone !== '' ? 'k'.self::normPhone($phone) : null;

        $out = [];
        $db = Modules::db('reservasi');
        $rows = $db->select('SELECT data FROM '.ReservasiState::t('reservations').' WHERE '.implode(' AND ', $w).' ORDER BY created_at ASC, '.ReservasiState::idCol().' ASC', $a);
        foreach ($rows as $row) {
            $r = json_decode((string) $row->data, true);
            if (! is_array($r)) {
                continue;
            }
            // The `k` prefix keeps phone numbers from becoming integer keys —
            // numbers past PHP_INT_MAX, or coincidentally sequential ones,
            // would change the JSON shape into an array.
            $k = 'k'.self::normPhone($r['phone'] ?? '');
            if ($want !== null && $k !== $want) {
                continue;
            }
            if (! isset($out[$k])) {
                $out[$k] = [0, 0, 0, 0, '', 0, '', 0, '', ''];
            }
            $st = self::normStatus(self::s($r, 'status'));
            $out[$k][0]++;
            if ($st === 'Datang') {
                $out[$k][1]++;
                $d = self::s($r, 'date');
                if (strcmp($d, $out[$k][6]) > 0) {
                    $out[$k][6] = $d;
                }
            }
            if ($st === 'No-show') {
                $out[$k][2]++;
            }
            if (! empty($r['member'])) {
                $out[$k][3] = 1;
                if ($out[$k][4] === '' && self::s($r, 'memberNo') !== '') {
                    $out[$k][4] = self::s($r, 'memberNo');
                }
            }
            if (! empty($r['vip'])) {
                $out[$k][5] = 1;
            }
            $out[$k][7] += self::jsNum($r['pax'] ?? 0);
            if ($out[$k][0] === 1) {
                $out[$k][8] = self::s($r, 'name');
            }
            $nm = trim(self::s($r, 'name'));
            if ($nm !== '') {
                $out[$k][9] = $nm;
            }
        }

        return ['sebelum' => $before !== null && $before !== '' ? $before : null, 'tamu' => $out];
    }

    /** Twin of rsv_norm_hp() (and the client's normPhone()). */
    public static function normPhone(mixed $p): string
    {
        $x = preg_replace('/[^0-9]/', '', (string) $p);
        if ($x === '' || $x === null) {
            return '';
        }
        if ($x[0] === '0') {
            return '62'.substr($x, 1);
        }
        if (str_starts_with($x, '62')) {
            return $x;
        }

        return '62'.$x;
    }

    /** Twin of rsv_norm_status() (and the client's normStatus()). */
    public static function normStatus(string $s): string
    {
        if ($s === 'Checked-in' || $s === 'Completed') {
            return 'Datang';
        }
        if ($s === 'Booking') {
            return 'Confirmed';
        }

        return $s;
    }

    private static function s(array $r, string $k): string
    {
        return isset($r[$k]) && $r[$k] !== null ? (string) $r[$k] : '';
    }

    /** Twin of rsv_js_num(). */
    private static function jsNum(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0;
    }
}
