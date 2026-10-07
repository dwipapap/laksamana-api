<?php

declare(strict_types=1);

namespace App\Modules\Automation\Feeds;

use App\Modules\Automation\Support\ReadDb;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Morning briefing for the 07:00 WIB automation (n8n "Laksamana Info Pagi
 * 07 WIB") — the /api/v1/automation/info-pagi feed, moved from
 * App\Modules\InfoPagi\Services\InfoPagi (#234).
 *
 * Combines two sources for one calendar date:
 *  - Event (EMS): scheduled events of the day — name, start time, venue, PIC.
 *  - Marketing: deal / done events touching the day (multi-day aware) + VIP.
 *
 * READ-ONLY by construction: every statement below is a SELECT. The source DBs
 * come from ReadDb::for('event'|'marketing') — the `automation_ro` connection
 * when pinned, the module connections otherwise — never from request input.
 * There is no ?source= parameter any more.
 *
 * The queries read the legacy (prod) table layout the automation database
 * uses (`events`, `settings` key `extra:vip`), so the result does not depend
 * on which connection the dev server itself uses.
 *
 * Output carries no phone numbers and no photo blobs.
 */
class InfoPagi
{
    private const TZ = 'Asia/Jakarta';

    /** Today in Asia/Jakarta. */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    /**
     * Each section is isolated: one source failing must not drop the whole
     * briefing, and the failure is reported in `errors` (not a bare 500) so
     * n8n/server logs stay diagnosable without cPanel access.
     *
     * @return array{data:array{event:list<array>,marketing:array{events:list<array>,vip:list<array>}},errors:array<string,string>}
     */
    public function briefing(string $date): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        }

        $errors = [];
        try {
            $event = $this->eventHari($date);
        } catch (Throwable $e) {
            $event = [];
            $errors['event'] = $e->getMessage();
        }
        try {
            $marketing = $this->marketingHari($date);
        } catch (Throwable $e) {
            $marketing = ['events' => [], 'vip' => []];
            $errors['marketing'] = $e->getMessage();
        }

        return [
            'data' => ['event' => $event, 'marketing' => $marketing],
            'errors' => $errors,
        ];
    }

    // ── Event (EMS) ──────────────────────────────────────────────

    /** Scheduled, non-draft events of one day. Mirrors EventState::eventsHari(). */
    private function eventHari(string $date): array
    {
        $db = ReadDb::for('event');
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
    private function marketingHari(string $date): array
    {
        $db = ReadDb::for('marketing');
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

        return ['events' => $events, 'vip' => $this->vipOn($db, $date)];
    }

    /** Assisted, not-cancelled VIP rows of one day. Mirrors MarketingQueries::vipOn(). */
    private function vipOn(ConnectionInterface $db, string $date): array
    {
        // Automation DB layout (prod): JSON list under settings key extra:vip.
        $row = $db->selectOne('SELECT v FROM `settings` WHERE k = ?', ['extra:vip']);
        $list = $row ? (json_decode((string) $row->v, true) ?: []) : [];
        $rows = array_map(fn ($v) => (object) ['data' => json_encode($v)], is_array($list) ? $list : []);
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
}
