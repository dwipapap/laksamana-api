<?php

namespace App\Modules\Dw\Services;

use App\Modules\Event\Services\EventState;
use App\Modules\Marketing\Services\MarketingState;
use App\Modules\Radar\Services\RadarRules;
use App\Modules\Reservasi\Services\ReservasiRecords;
use RuntimeException;

/**
 * Daily guest pax for the DW Kalender Tamu — port of muatTamu() +
 * tamuPasti() + paxAcaraTgl() + paxRsvTgl() in
 * laksamana-office/deploy/dw/index.html, the twin of satukan() in
 * deploy/radar/index.html.
 *
 * The calendar never copies guest numbers into the DW database (they belong
 * to Marketing/Event/Reservasi and change daily); it reads them live, one
 * way. This service does the same in-process: no HTTP hop, so a `dw` holder
 * needs none of the three source modules.
 *
 * Rules, exactly as the old page:
 * - Marketing events count only when they will surely happen: `deal`,
 *   `confirmed`, `event done` (compared lowercase). Sales-pipeline stages
 *   (Lead, Prospect, Negotiation) would summon daily workers for events
 *   that may never happen — money out for nothing.
 * - Reservasi VIP (kept by the marketing module) counts its UPPER bound
 *   (`paxMax`, falling back to `paxMin`): preparing too little costs more
 *   than preparing too much. Cancelled ones (`batalAt`, like vip_hari() in
 *   marketing-mysql) never count.
 * - Event times are UTC instants and are shifted to WIB before taking the
 *   date; cutting the raw string moves night events to the previous day's
 *   box and the DW request lands on the wrong day.
 * - Reservasi counts its local `date` as-is, without the WIB shift.
 *   Cancelled bookings (`cancel`, keeping `batal` for old rows) never
 *   count; no-shows count 0 pax but keep their row.
 */
class DwGuests
{
    /** Marketing statuses that mean the event will happen (tamuPasti). */
    private const MKT_JALAN = ['deal', 'confirmed', 'event done'];

    /**
     * Event-module statuses that mean the event will happen (tamuPasti) — the
     * same list as Radar (RadarRules::EVT_JALAN), so the Event status migration
     * of Oct 2026 is followed in one place.
     */
    private const EVT_JALAN = RadarRules::EVT_JALAN;

    public function __construct(
        private readonly MarketingState $marketing,
        private readonly EventState $event,
        private readonly ReservasiRecords $reservasi,
    ) {}

    /**
     * Pax sums per (day, source) in [dari, sampai]:
     * {rows: [{tgl, sumber, pax}], dari, sampai}.
     *
     * `sumber` is `marketing` (events + Reservasi VIP, both owned by the
     * marketing module), `event` or `reservasi`. Only days with pax > 0 are
     * listed, ordered by day then source.
     */
    public function range(string $dari, string $sampai): array
    {
        $a = DwService::tglValid($dari);
        $b = DwService::tglValid($sampai);
        if ($a === '' || $b === '') {
            throw new RuntimeException('daftar tamu butuh dari & sampai (YYYY-MM-DD)');
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }

        $sum = [];
        $add = function (string $tgl, string $sumber, int $pax) use (&$sum): void {
            if ($pax <= 0) {
                return;
            }
            $k = $tgl."\0".$sumber;
            $sum[$k] = [$tgl, $sumber, ($sum[$k][2] ?? 0) + $pax];
        };

        $mkt = $this->marketing->read();
        foreach (is_array($mkt['events'] ?? null) ? $mkt['events'] : [] as $e) {
            if (! is_array($e)) {
                continue;
            }
            $tgl = substr((string) ($e['tanggal'] ?? ''), 0, 10);
            if (! self::inRange($tgl, $a, $b)) {
                continue;
            }
            if (! in_array(mb_strtolower(trim((string) ($e['status'] ?? ''))), self::MKT_JALAN, true)) {
                continue;
            }
            $add($tgl, 'marketing', (int) ($e['pax'] ?? 0));
        }
        foreach (is_array($mkt['vip'] ?? null) ? $mkt['vip'] : [] as $v) {
            if (! is_array($v) || ! empty($v['batalAt'])) {
                continue;
            }
            $tgl = substr((string) ($v['tanggal'] ?? ''), 0, 10);
            if (! self::inRange($tgl, $a, $b)) {
                continue;
            }
            if (self::isCancelled($v) || self::isNoShow($v)) {
                continue;
            }
            // Upper bound of the range estimate (paxVipAngka): preparing too
            // little costs more than preparing too much.
            $add($tgl, 'marketing', (int) ($v['paxMax'] ?? 0) ?: (int) ($v['paxMin'] ?? 0));
        }

        $evt = $this->event->read();
        foreach (is_array($evt['events'] ?? null) ? $evt['events'] : [] as $e) {
            if (! is_array($e)) {
                continue;
            }
            if (! in_array(mb_strtolower(trim((string) ($e['status'] ?? ''))), self::EVT_JALAN, true)) {
                continue;
            }
            $mulai = self::splitTime($e['start_datetime'] ?? ($e['tanggal'] ?? null));
            if ($mulai === null || ! self::inRange($mulai['tgl'], $a, $b)) {
                continue;
            }
            $add($mulai['tgl'], 'event', (int) ($e['capacity'] ?? 0));
        }

        foreach ($this->reservasi->list($a, $b) as $r) {
            if (! is_array($r) || self::isCancelled($r)) {
                continue;
            }
            $tgl = substr((string) ($r['date'] ?? ''), 0, 10);
            if (! self::inRange($tgl, $a, $b)) {
                continue;
            }
            // No-shows count 0 pax — nobody came — but the row stays, so the
            // booking count does not shrink with it.
            $add($tgl, 'reservasi', self::isNoShow($r) ? 0 : (int) ($r['pax'] ?? 0));
        }

        $rows = array_map(
            fn (array $s): array => ['tgl' => $s[0], 'sumber' => $s[1], 'pax' => $s[2]],
            array_values($sum)
        );
        usort($rows, fn (array $x, array $y): int => [$x['tgl'], $x['sumber']] <=> [$y['tgl'], $y['sumber']]);

        return ['rows' => $rows, 'dari' => $a, 'sampai' => $b];
    }

    private static function inRange(string $tgl, string $a, string $b): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) === 1 && $tgl >= $a && $tgl <= $b;
    }

    /**
     * pecahWaktu(): an instant with a zone marker (the Event module stores
     * UTC ending in Z) is shifted to WIB; a value without a zone is already
     * local (Marketing stores plain dates).
     *
     * @return array{tgl:string,jam:string}|null
     */
    private static function splitTime(mixed $dt): ?array
    {
        $s = trim((string) ($dt ?? ''));
        if ($s === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s) === 1) {
            $t = strtotime($s);
            if ($t === false) {
                return null;
            }
            $w = $t + 7 * 3600;

            return ['tgl' => gmdate('Y-m-d', $w), 'jam' => gmdate('H:i', $w)];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $s, $m) === 1) {
            return ['tgl' => $m[1], 'jam' => $m[2] ?? ''];
        }

        return null;
    }

    /** resBatal(): "batal" kept for old rows; the Reservasi word is "cancel". */
    private static function isCancelled(array $r): bool
    {
        return preg_match('/cancel|batal/i', (string) ($r['status'] ?? '')) === 1;
    }

    private static function isNoShow(array $r): bool
    {
        return preg_match('/no.?show/i', (string) ($r['status'] ?? '')) === 1;
    }
}
