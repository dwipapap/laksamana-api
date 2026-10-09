<?php

namespace App\Modules\Reservasi\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Server-side paging, filters and CSV export for the reservation and audit
 * tables (G-08).
 *
 * The old screens page Daftar Reservasi, Dana Masuk and Audit Log from the
 * server (`?action=halRecap/halDana/halAudit`, "Muat berjendela") because the
 * whole list is megabytes; v1 `GET /reservations?from&to` had no paging. The
 * filter rules here are twins of the client they replace, kept in the same
 * order the screens apply them:
 *   reservations <-> applyFilter() + recapList()
 *   audit        <-> auditCocok()
 * (laksamana-office deploy/reservasi/index.html). READ-ONLY: not one write.
 *
 * Without `page`/`perPage` the list answers exactly like before (every
 * matching row in `created_at, id` order); the paged mode sorts display
 * order (date+time, stable) like the Recap screen, so page N on the server
 * is page N on the screen.
 */
final class ReservasiBrowse
{
    public function __construct(
        private readonly ReservasiRecords $records,
        private readonly ReservasiState $state,
    ) {}

    /**
     * Reservations in range, narrowed by the Recap search and status.
     *
     * `q` matches name, phone or table number (case-insensitive; the twin of
     * the screen's DASH_FILTER.q over name/phone plus its HARIH_Q over
     * tables). `status` compares against the NORMALISED status
     * (Checked-in/Completed → Datang, Booking → Confirmed) and, when one is
     * asked for anything but `Cancelled`, drops Cancelled rows while counting
     * them as `batal` — the twin of rsv_hal_recap(). Without a status every
     * row stays, like the unfiltered v1 answer always did. Rows stay in
     * `created_at, id` order; call sortDisplay() for screen order.
     *
     * @return array{rows: array, total: int, batal: int}
     */
    public function filterReservations(?string $from, ?string $to, ?string $q, ?string $status): array
    {
        $q = $q !== null ? self::lower(trim($q)) : '';
        $status = $status ?? '';
        // Rows stay RAW (the export prints the stored status); the normalised
        // status exists only for comparison, like rsv_semua_res() prepares it.
        $pre = [];
        foreach ($this->records->list($from, $to) as $r) {
            if ($q !== '' && ! self::rowMatches($r, $q)) {
                continue;
            }
            $pre[] = [$r, self::normStatus(self::s($r, 'status'))];
        }
        // The Recap screen's hidden-cancelled count: Cancelled rows matching
        // from/to/q that the status rule hides. Legacy halRecap() counts
        // post-status-filter (always 0 with a status); counting pre-filter
        // keeps the key useful, and it only appears with a status.
        $batal = 0;
        if ($status !== '' && $status !== 'Cancelled') {
            foreach ($pre as [, $st]) {
                if ($st === 'Cancelled') {
                    $batal++;
                }
            }
        }
        $list = [];
        foreach ($pre as [$r, $st]) {
            if ($status !== '' && $st !== $status) {
                continue;
            }
            if ($status !== '' && $status !== 'Cancelled' && $st === 'Cancelled') {
                continue;
            }
            $list[] = $r;
        }

        return ['rows' => $list, 'total' => count($list), 'batal' => $batal];
    }

    /**
     * Display order, twin of recapList()'s sort: date+time ascending, STABLE.
     * usort is stable since PHP 8.0, but the explicit index twin
     * (rsv_urut_stabil) keeps rows with equal date+time from swapping pages.
     */
    public function sortDisplay(array $rows): array
    {
        $tmp = [];
        foreach (array_values($rows) as $i => $x) {
            $tmp[] = [self::s($x, 'date').self::s($x, 'time'), $i, $x];
        }
        usort($tmp, fn ($a, $b) => strcmp($a[0], $b[0]) !== 0 ? strcmp($a[0], $b[0]) : ($a[1] - $b[1]));

        return array_map(fn ($t) => $t[2], $tmp);
    }

    /**
     * Cut an already filtered+ordered list to one page, twin of rsv_potong():
     * perPage defaults to 10 and is clamped to 1..100, the page is clamped
     * to the pages that exist (filtering down while on page 8 shows the last
     * page, not an empty table that looks like lost data).
     *
     * @return array{rows: array, total: int, page: int, perPage: int}
     */
    public function page(array $list, ?int $page, ?int $perPage): array
    {
        $per = $perPage ?? 10;
        $per = min(100, max(1, $per));
        $total = count($list);
        $maxPage = max(1, (int) ceil($total / $per));
        $hal = min($maxPage, max(1, $page ?? 1));

        return ['rows' => array_slice(array_values($list), ($hal - 1) * $per, $per),
            'total' => $total, 'page' => $hal, 'perPage' => $per];
    }

    /**
     * Audit rows, newest first, narrowed by the Audit Log search box: user,
     * role label, action and detail (twin of auditCocok() + rsv_hal_audit()).
     * The guest name lives in Detail, so a cancelled or deleted reservation
     * is still found by name — that is the box's main use.
     *
     * @return array<int, array>
     */
    public function filterAudit(?string $q): array
    {
        $q = $q !== null ? self::lower(trim($q)) : '';
        $out = [];
        foreach ($this->state->readAudit() as $a) {
            if ($q === '' || self::auditMatches($a, $q)) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /**
     * Table → floor map from the master layouts the server knows (custom
     * `layouts` templates), twin of lantaiMap()'s merge rule: the first
     * template wins, a table claimed by two floors (0) is unknown and dropped
     * by floorsFor(). Built-in templates (weekday/weekend/…) live only in the
     * browser bundle, so tables known just to them export an empty Lantai.
     *
     * @return array<string, int> 0 = claimed by two floors (unknown)
     */
    public function tableFloors(array $master): array
    {
        $map = [];
        $layouts = isset($master['layouts']) && is_array($master['layouts']) ? $master['layouts'] : [];
        foreach ($layouts as $key => $layout) {
            if (! is_array($layout)) {
                continue;
            }
            $floor = self::BUILTIN_FLOOR[(string) $key] ?? self::floorFromName(
                isset($layout['name']) ? (string) $layout['name'] : (string) $key);
            $tables = isset($layout['tables']) && is_array($layout['tables']) ? $layout['tables'] : [];
            foreach ($tables as $t) {
                $id = (string) (is_array($t) ? ($t['id'] ?? '') : '');
                if ($id === '') {
                    continue;
                }
                if (! array_key_exists($id, $map)) {
                    $map[$id] = $floor;
                } elseif ($map[$id] !== $floor) {
                    $map[$id] = 0;
                }
            }
        }

        return $map;
    }

    /** Floors of one reservation, twin of lantaiRes(): sorted, or null when unknown. */
    public function floorsFor(array $row, array $map): ?array
    {
        $set = [];
        foreach (self::tablesOf($row) as $id) {
            $f = $map[$id] ?? null;
            if ($f) {
                $set[$f] = true;
            }
        }
        if ($set === []) {
            return null;
        }
        $out = array_keys($set);
        sort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * The Recap CSV (exportCSV()), same columns in the same order, with a BOM
     * so Excel opens it as UTF-8. Values are the raw row fields — Lantai and
     * Rekening are derived exactly like the screen derives them
     * (lantaiRes/dpRekeningList); No HP is digits only (displayPhone).
     */
    public function exportCsv(array $rows, array $floors): string
    {
        $cols = ['Nama', 'No HP', 'Tanggal', 'Jam', 'Pax', 'Pax Aktual', 'Meja', 'Lantai', 'Status',
            'DP', 'Nominal DP', 'Metode DP', 'Rekening DP', 'Sumber', 'PIC', 'Member', 'No Member', 'Catatan', 'Diinput Oleh'];
        $lines = [self::csvRow($cols)];
        foreach ($rows as $r) {
            $lt = $this->floorsFor($r, $floors);
            $lines[] = self::csvRow([
                self::s($r, 'name'), self::digits(self::s($r, 'phone')), self::s($r, 'date'), self::s($r, 'time'),
                self::s($r, 'pax'), self::s($r, 'actualPax'), self::s($r, 'table'),
                $lt === null ? '' : implode(' & ', $lt),
                self::s($r, 'status'), self::s($r, 'dpStatus'), self::s($r, 'dpAmount'), self::s($r, 'dpMethod'),
                implode(' & ', self::rekeningList($r)), self::s($r, 'source'), self::s($r, 'picName'),
                ! empty($r['member']) ? 'Ya' : 'Tidak', self::s($r, 'memberNo'),
                self::s($r, 'notes'), self::s($r, 'createdBy'),
            ]);
        }

        return "\u{FEFF}".implode("\n", $lines);
    }

    /** Today in Asia/Jakarta, for the export filename (twin of todayStr()). */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    }

    // ─────────────────────────── twins ──

    private const BUILTIN_FLOOR = ['weekday' => 1, 'weekend' => 1, 'lantai2' => 2, 'lantai2_weekend' => 2];

    /** Twin of ROLE_LABEL (deploy/reservasi/index.html). */
    private const ROLE_LABEL = ['host' => 'Host / Captain', 'marketing' => 'Marketing (PIC)',
        'cashier' => 'Cashier', 'manager' => 'Manajer / Owner', 'viewer' => 'View Only', 'admin' => 'Admin Sistem'];

    /** Twin of lantaiLayout()'s name guess for custom templates (default: floor 1). */
    private static function floorFromName(string $name): int
    {
        if (preg_match('/(?:lantai|lt\.?|floor)\s*(\d+)/i', $name, $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    /** Twin of the screen's q/hq match: name or table contains, phone contains. */
    private static function rowMatches(array $r, string $q): bool
    {
        if (self::contains(self::lower(self::s($r, 'name')), $q) || str_contains(self::s($r, 'phone'), $q)) {
            return true;
        }
        foreach (self::tablesOf($r) as $t) {
            if (self::contains(self::lower($t), $q)) {
                return true;
            }
        }

        return false;
    }

    /** Twin of auditCocok(). */
    private static function auditMatches(array $a, string $q): bool
    {
        $role = self::s($a, 'role');
        foreach ([self::s($a, 'user'), self::ROLE_LABEL[$role] ?? $role, self::s($a, 'action'), self::s($a, 'detail')] as $v) {
            if (self::contains(self::lower($v), $q)) {
                return true;
            }
        }

        return false;
    }

    /** Twin of dpRekeningList(): every instalment's method, legacy dpMethod fallback. */
    private static function rekeningList(array $r): array
    {
        $out = [];
        $dps = isset($r['dps']) && is_array($r['dps']) ? $r['dps'] : [];
        foreach ($dps as $p) {
            $v = trim((string) (is_array($p) ? ($p['method'] ?? '') : ''));
            if ($v !== '' && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        if ($out === []) {
            $v = trim(self::s($r, 'dpMethod'));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** Twin of rsv_norm_status() (and the client's normStatus()). Also twinned in ReservasiGuests (#182); kept here so this change stands alone on main. */
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

    /** Twin of tablesOf(). */
    private static function tablesOf(array $r): array
    {
        $out = [];
        foreach (explode(',', self::s($r, 'table')) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    private static function csvRow(array $cells): string
    {
        return implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) ($c ?? '')).'"', $cells));
    }

    private static function s(array $r, string $k): string
    {
        return isset($r[$k]) && $r[$k] !== null ? (string) $r[$k] : '';
    }

    private static function digits(string $v): string
    {
        return (string) preg_replace('/[^0-9]/', '', $v);
    }

    private static function lower(string $v): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    }

    private static function contains(string $hay, string $q): bool
    {
        return $q === '' || str_contains($hay, $q);
    }
}
