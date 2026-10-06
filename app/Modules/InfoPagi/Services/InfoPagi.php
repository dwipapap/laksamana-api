<?php

namespace App\Modules\InfoPagi\Services;

use App\Support\Modules;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Morning briefing for the 07:00 WIB automation (n8n "Laksamana Info Pagi
 * 07 WIB"). READ-ONLY by construction: every statement below is a SELECT.
 *
 * Combines two sources for one calendar date:
 *  - Event (EMS): scheduled events of the day — name, start time, venue, PIC.
 *  - Marketing: deal / done events touching the day (multi-day aware) + VIP.
 *
 * Ported from EventState::eventsHari() and MarketingQueries::eventsOn() but
 * reading the legacy (prod-layout) tables directly, so the result does not
 * depend on which connection (legacy vs core) the dev server uses, and the
 * n8n caller can pin ?source=prod to read the PROD databases.
 *
 * Data sources, chosen by env (never by request input):
 *  - default : the module connections (dev DB on dev-api, prod DB on prod).
 *  - prod pin: set EVENT_INFO_PROD_* (lakk5493_db_ems) and
 *    MARKETING_INFO_PROD_* (lakk5493_db_marketing) to read the PROD
 *    databases even when the app itself runs against dev. Reuse ONE
 *    SELECT-only DB user for all three pins (reservasi recap + these two).
 *
 * Output carries no phone numbers and no photo blobs.
 */
class InfoPagi
{
    private const TZ = 'Asia/Jakarta';

    /** True when BOTH prod pins are fully set (then ?source=prod is allowed). */
    public static function prodPinned(): bool
    {
        return (string) env('EVENT_INFO_PROD_HOST', '') !== ''
            && (string) env('EVENT_INFO_PROD_DATABASE', '') !== ''
            && (string) env('EVENT_INFO_PROD_USERNAME', '') !== ''
            && (string) env('MARKETING_INFO_PROD_HOST', '') !== ''
            && (string) env('MARKETING_INFO_PROD_DATABASE', '') !== ''
            && (string) env('MARKETING_INFO_PROD_USERNAME', '') !== '';
    }

    /** Today in Asia/Jakarta. */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    /**
     * @return array{date:string,source:string,event:array,marketing:array,errors:array}
     */
    public function briefing(string $date, string $source = 'default'): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        }

        // Each section is isolated: one source failing must not drop the
        // whole briefing, and the failure is reported (not a bare 500) so
        // n8n/server logs stay diagnosable without cPanel access.
        $errors = [];
        try {
            $event = $this->eventHari($date, $source);
        } catch (\Throwable $e) {
            $event = [];
            $errors['event'] = $e->getMessage();
        }
        try {
            $marketing = $this->marketingHari($date, $source);
        } catch (\Throwable $e) {
            $marketing = ['events' => [], 'vip' => []];
            $errors['marketing'] = $e->getMessage();
        }

        return [
            'date' => $date,
            'source' => $source,
            'event' => $event,
            'marketing' => $marketing,
            'errors' => $errors,
        ];
    }

    // ── Event (EMS) ──────────────────────────────────────────────

    /** Scheduled, non-draft events of one day. Mirrors EventState::eventsHari(). */
    private function eventHari(string $date, string $source): array
    {
        $db = $this->conn('event', $source);
        $rows = $db->select(
            'SELECT id, title, status, venue, pic, start_datetime, data'
            .' FROM `events` WHERE DATE(start_datetime) = ?'
            ." AND status NOT IN ('Planning', 'Draft', 'Cancelled')"
            .' ORDER BY start_datetime, title',
            [$date]
        );
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) ($r->data ?? ''), true);
            if (! is_array($d)) {
                $d = [];
            }
            $mulai = (string) ($r->start_datetime ?? '');
            $out[] = [
                'id' => (string) ($r->id ?? ''),
                'nama' => (string) ($r->title ?? ''),
                'status' => (string) ($r->status ?? ''),
                'venue' => (string) ($r->venue ?? ''),
                'picName' => (string) ($r->pic ?? ''),
                'mulai' => strlen($mulai) >= 16 ? substr($mulai, 0, 16) : $mulai,
            ];
        }

        return $out;
    }

    // ── Marketing ────────────────────────────────────────────────

    /**
     * Deal / Event Done events touching $date (multi-day aware) + Assisted
     * VIP of the day. Mirrors MarketingQueries::eventsOn()/vipOn() minus
     * settings (serviceCharge/pb1 are irrelevant to a chat briefing).
     */
    private function marketingHari(string $date, string $source): array
    {
        $db = $this->conn('marketing', $source);
        $batas = date('Y-m-d', strtotime($date.' 00:00:00 -60 days'));
        // No JOIN: the users table layout differs per environment; PIC names
        // resolve from the decoded data blob (mktPIC) where present, else ''.
        $rows = $db->select(
            'SELECT id, nama, tanggal, status, pax, mkt_pic, data'
            .' FROM `events` WHERE status IN (\'Deal\', \'Event Done\') AND tanggal <= ? AND tanggal >= ?'
            .' ORDER BY nama',
            [$date, $batas]
        );
        $events = [];
        foreach ($rows as $r) {
            $d = json_decode((string) ($r->data ?? ''), true);
            if (! is_array($d)) {
                $d = [];
            }
            $mulai = (string) ($r->tanggal ?? '');
            $selesai = isset($d['tanggalSelesai']) ? (string) $d['tanggalSelesai'] : '';
            if ($mulai !== $date && ! ($selesai !== '' && $selesai >= $date)) {
                continue;
            }
            $hari = 1;
            $totalHari = 1;
            if ($selesai !== '' && $selesai > $mulai) {
                $hb = strtotime($mulai.' 00:00:00');
                $totalHari = (int) floor((strtotime($selesai.' 00:00:00') - $hb) / 86400) + 1;
                $hari = (int) floor((strtotime($date.' 00:00:00') - $hb) / 86400) + 1;
            }
            $events[] = [
                'id' => (string) ($r->id ?? ''),
                'nama' => (string) ($r->nama ?? ''),
                'status' => (string) ($r->status ?? ''),
                'pax' => (int) ($r->pax ?? 0),
                'picName' => isset($d['mktPICName']) ? (string) $d['mktPICName'] : '',
                'menuFix' => isset($d['menuFix']) ? (string) $d['menuFix'] : '',
                'selesai' => $selesai,
                'hari' => $hari,
                'totalHari' => $totalHari,
            ];
        }

        return ['events' => $events, 'vip' => $this->vipOn($db, $date, false, $source)];
    }

    /** Assisted, not-cancelled VIP rows of one day. Mirrors MarketingQueries::vipOn(). */
    private function vipOn(ConnectionInterface $db, string $date, bool $core, string $source): array
    {
        if ($core) {
            $rows = $db->select('SELECT data FROM `marketing_vip` ORDER BY urutan');
        } else {
            // Legacy layout (dev or PROD pin): JSON list under settings key extra:vip.
            $row = $db->selectOne('SELECT v FROM `settings` WHERE k = ?', ['extra:vip']);
            $list = $row ? (json_decode((string) $row->v, true) ?: []) : [];
            $rows = array_map(fn ($v) => (object) ['data' => json_encode($v)], is_array($list) ? $list : []);
        }
        $pick = [];
        foreach ($rows as $row) {
            $v = json_decode((string) ($row->data ?? ''), true);
            if (! is_array($v)) {
                continue;
            }
            if (($v['tanggal'] ?? '') !== $date || ! empty($v['batalAt']) || ($v['jenis'] ?? '') !== 'Assisted') {
                continue;
            }
            $pick[] = $v;
        }
        if ($pick === []) {
            return [];
        }
        // No lookups: users/clients layouts differ per environment; the VIP
        // row already carries perusahaan + PIC name where the app stored them.
        $out = [];
        foreach ($pick as $v) {
            $out[] = [
                'nama' => (string) ($v['nama'] ?? ''),
                'perusahaan' => (string) ($v['perusahaan'] ?? ''),
                'jam' => trim(implode(' - ', array_filter([(string) ($v['jamMulai'] ?? ''), (string) ($v['jamSelesai'] ?? '')]))),
                'pax' => (int) (! empty($v['paxMax']) ? $v['paxMax'] : ($v['paxMin'] ?? 0)),
                'meja' => isset($v['meja']) && is_array($v['meja']) ? array_values($v['meja']) : [],
                'nominal' => (int) round((float) ($v['nominal'] ?? 0)),
                'picName' => (string) ($v['mktPICName'] ?? ''),
                'menuFix' => isset($v['menuFix']) ? (string) $v['menuFix'] : '',
            ];
        }

        return $out;
    }

    // ── connections ─────────────────────────────────────────────

    private function conn(string $module, string $source): ConnectionInterface
    {
        if ($source !== 'prod') {
            return Modules::db($module);
        }

        return self::prodDb($module);
    }

    /**
     * A second Laravel connection pinned at a PROD module DB. Built ad-hoc
     * (not in config/database.php) so a missing env simply means "not
     * pinned" instead of breaking every other connection. SELECT-only is
     * enforced by code review (this class has no write method) and by the
     * DB user, which must be GRANT SELECT only (reuse the recap_ro user,
     * extended to the ems + marketing databases).
     */
    private static function prodDb(string $module): ConnectionInterface
    {
        if (! self::prodPinned()) {
            throw new InvalidArgumentException('Prod source is not configured (EVENT_INFO_PROD_* / MARKETING_INFO_PROD_*).');
        }
        $prefix = $module === 'event' ? 'EVENT_INFO_PROD_' : 'MARKETING_INFO_PROD_';
        $capsule = new Manager;
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => (string) env($prefix.'HOST'),
            'port' => (int) env($prefix.'PORT', 3306),
            'database' => (string) env($prefix.'DATABASE'),
            'username' => (string) env($prefix.'USERNAME'),
            'password' => (string) env($prefix.'PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => null, // like the legacy connections: leave sql_mode to the server
        ], 'info_pagi_prod_'.$module);

        return $capsule->getConnection('info_pagi_prod_'.$module);
    }
}
