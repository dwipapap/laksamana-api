<?php

declare(strict_types=1);

namespace App\Modules\Automation\Feeds;

use App\Modules\Automation\Support\ReadDb;
use App\Modules\Reservasi\Services\ReservasiState;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Daily recap for the 17:00 WIB automation (n8n "Laksamana Rekap Reservasi
 * 17 WIB") — the /api/v1/automation/reservasi-harian feed, moved from
 * App\Modules\Reservasi\Services\ReservasiRecap (#234).
 *
 * READ-ONLY by construction: every statement below is a SELECT. The source DB
 * comes from ReadDb::for('reservasi') — the `automation_ro` connection when
 * pinned, the module connection otherwise — never from request input. There is
 * no ?source= parameter any more.
 *
 * Output is aggregate + per-row lines WITHOUT phone numbers: name, time,
 * tables, pax, status and DP total only. Photo blobs (@f: refs, base64) are
 * never returned.
 */
class ReservasiHarian
{
    private const TZ = 'Asia/Jakarta';

    /** Today + N days in Asia/Jakarta (the automation asks for 0, 1, 2). */
    public static function plusDays(int $n): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))
            ->modify("+{$n} days")->format('Y-m-d');
    }

    /**
     * One entry per requested day (base … base+days). A day that fails is
     * reported in `errors` instead of dropping the whole feed.
     *
     * @return array{data:list<array{date:string,rows:list<array>,total:array}>,errors:array<string,string>}
     */
    public function forDates(string $date, int $days = 0): array
    {
        $base = new DateTimeImmutable($this->validDate($date));
        $data = [];
        $errors = [];
        for ($i = 0; $i <= $days; $i++) {
            $hari = $base->modify("+{$i} days")->format('Y-m-d');
            try {
                $data[] = $this->daily($hari);
            } catch (Throwable $e) {
                $errors[$hari] = $e->getMessage();
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @return array{date:string,rows:list<array>,total:array{reservasi:int,pax:int,batal:int,dpMasuk:int,perStatus:array<string,int>}}
     */
    public function daily(string $date): array
    {
        $this->validDate($date);
        $db = ReadDb::for('reservasi');

        $t = ReservasiState::t('reservations');
        $idCol = ReservasiState::idCol();
        // Indexed columns only — the big `data` JSON is decoded in PHP.
        $raw = $db->select(
            "SELECT data FROM {$t} WHERE tanggal = ? ORDER BY jam ASC, {$idCol} ASC",
            [$date]
        );

        $rows = [];
        $batal = 0;
        $pax = 0;
        $dpMasuk = 0;
        $perStatus = [];
        foreach ($raw as $r) {
            $d = json_decode((string) $r->data, true);
            if (! is_array($d)) {
                continue;
            }
            $status = (string) ($d['status'] ?? '');
            $perStatus[$status] = ($perStatus[$status] ?? 0) + 1;
            if ($status === 'Cancelled') {
                $batal++;

                continue;
            }
            $dp = self::dpTotal($d);
            $dpMasuk += $dp;
            $pax += (int) ($d['pax'] ?? 0);
            $rows[] = [
                'id' => (string) ($d['id'] ?? ''),
                'name' => (string) ($d['name'] ?? '-'),
                'time' => substr((string) ($d['time'] ?? $d['jam'] ?? '--:--'), 0, 5),
                'tables' => self::tables($d),
                'pax' => (int) ($d['pax'] ?? 0),
                'status' => $status,
                'dpStatus' => (string) ($d['dpStatus'] ?? ''),
                'dpAmount' => $dp,
                'dps' => self::dps($d),
            ];
        }

        return [
            'date' => $date,
            'rows' => $rows,
            'total' => [
                'reservasi' => count($rows),
                'pax' => $pax,
                'batal' => $batal,
                'dpMasuk' => $dpMasuk,
                'perStatus' => $perStatus,
            ],
        ];
    }

    private function validDate(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        }

        return $date;
    }

    /** DP total of one reservation: dps[] rows, legacy dpAmount fallback. */
    private static function dpTotal(array $d): int
    {
        $sum = 0;
        if (is_array($d['dps'] ?? null)) {
            foreach ($d['dps'] as $p) {
                $sum += (int) (is_array($p) ? ($p['amount'] ?? 0) : 0);
            }
        }
        // Legacy single-DP shape (lib_reservasi_mysql.php rsv_dps()).
        if ($sum === 0 && ($d['dpStatus'] ?? '') === 'Sudah') {
            $sum = (int) ($d['dpAmount'] ?? 0);
        }

        return $sum;
    }

    /** DP rows without proof blobs (amount + method only). */
    private static function dps(array $d): array
    {
        $out = [];
        if (is_array($d['dps'] ?? null)) {
            foreach ($d['dps'] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $out[] = ['amount' => (int) ($p['amount'] ?? 0), 'method' => (string) ($p['method'] ?? '')];
            }
        }
        if ($out === [] && ($d['dpStatus'] ?? '') === 'Sudah' && (int) ($d['dpAmount'] ?? 0) > 0) {
            $out[] = ['amount' => (int) $d['dpAmount'], 'method' => (string) ($d['dpMethod'] ?? '')];
        }

        return $out;
    }

    /** Table list: `table` may be a comma string ("VIP 1, VIP 2") or an array. */
    private static function tables(array $d): string
    {
        $t = $d['table'] ?? $d['tables'] ?? '';
        if (is_array($t)) {
            $t = implode(', ', array_map(strval(...), $t));
        }
        $parts = array_values(array_filter(array_map(trim(...), explode(',', (string) $t))));

        return $parts === [] ? '-' : implode(', ', $parts);
    }
}
