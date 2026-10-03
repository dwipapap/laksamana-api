<?php

namespace App\Modules\Reservasi\Services;

use App\Support\Modules;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Daily recap for the 17:00 WIB automation (n8n "Laksamana Rekap Reservasi
 * 17 WIB"). READ-ONLY by construction: every statement below is a SELECT.
 *
 * Two data sources, chosen by env (never by request input):
 *
 * - default  : Modules::db('reservasi') — the normal module connection
 *              (dev DB on dev-api, prod DB on the prod deploy).
 * - prod pin : set RESERVASI_RECAP_PROD_* (host/database/username/password)
 *              to read the PROD reservasi database even when the app itself
 *              runs against dev. The pinned connection reuses the same table
 *              layout via ReservasiState::t()/idCol(), so core/legacy
 *              differences do not matter to the caller.
 *
 * Output is aggregate + per-row lines WITHOUT phone numbers: name, time,
 * tables, pax, status and DP total only. Photo blobs (@f: refs, base64) are
 * never returned.
 */
class ReservasiRecap
{
    private const TZ = 'Asia/Jakarta';

    public function __construct(private readonly ReservasiState $state) {}

    /** True when the prod-pin env is fully set (then ?source=prod is allowed). */
    public static function prodPinned(): bool
    {
        return (string) env('RESERVASI_RECAP_PROD_HOST', '') !== ''
            && (string) env('RESERVASI_RECAP_PROD_DATABASE', '') !== ''
            && (string) env('RESERVASI_RECAP_PROD_USERNAME', '') !== '';
    }

    /**
     * @return array{date:string,source:string,rows:list<array>,total:array}
     */
    public function daily(string $date, string $source = 'default'): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        }
        $db = $source === 'prod' ? $this->prodDb() : Modules::db('reservasi');

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
                'table' => self::tables($d), // alias: n8n Code node reads `table`
                'pax' => (int) ($d['pax'] ?? 0),
                'status' => $status,
                'dpStatus' => (string) ($d['dpStatus'] ?? ''),
                'dpAmount' => $dp,
                'dps' => self::dps($d),
            ];
        }

        return [
            'date' => $date,
            'source' => $source,
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

    /** Today + N days in Asia/Jakarta (the automation asks for 0, 1, 2). */
    public static function plusDays(int $n): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))
            ->modify("+{$n} days")->format('Y-m-d');
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

    /**
     * A second Laravel connection pinned at the PROD reservasi DB. Built
     * ad-hoc (not in config/database.php) so a missing env simply means
     * "not pinned" instead of breaking every other connection. SELECT-only
     * is enforced by code review (this class has no write method) and by
     * the DB user, which must be GRANT SELECT only (see .env.example).
     */
    private function prodDb(): ConnectionInterface
    {
        if (! self::prodPinned()) {
            throw new InvalidArgumentException('Prod source is not configured (RESERVASI_RECAP_PROD_*).');
        }
        $capsule = new Manager;
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => (string) env('RESERVASI_RECAP_PROD_HOST'),
            'port' => (int) env('RESERVASI_RECAP_PROD_PORT', 3306),
            'database' => (string) env('RESERVASI_RECAP_PROD_DATABASE'),
            'username' => (string) env('RESERVASI_RECAP_PROD_USERNAME'),
            'password' => (string) env('RESERVASI_RECAP_PROD_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => null, // like the legacy connections: leave sql_mode to the server
        ], 'reservasi_recap_prod');

        return $capsule->getConnection('reservasi_recap_prod');
    }
}
