<?php

declare(strict_types=1);

namespace App\Modules\Automation\Feeds;

use App\Modules\Automation\Support\ReadDb;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Public event + talent schedule for the guest chatbot (n8n).
 *
 * Event DB ONLY — the marketing database (corporate/private bookings such as
 * Devani) is never touched here. Guests asking "event apa minggu ini?" or
 * "siapa DJ hari ini?" only see venue-run events (EMS).
 *
 * READ-ONLY by construction: every statement below is a SELECT. The source DB
 * comes from ReadDb::for('event') — the `automation_ro` connection when
 * pinned, the module connection otherwise — never from request input.
 *
 * The queries read the legacy (prod) table layout the automation database
 * uses (`events`, `talents`, `schedules`), so the result does not depend on
 * which connection the dev server itself uses.
 *
 * Output carries no phone numbers, no fees and no photo blobs.
 */
class EventPublik
{
    private const TZ = 'Asia/Jakarta';

    /** Today in Asia/Jakarta. */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    /** Today + N days in Asia/Jakarta (the chatbot asks for "minggu ini"). */
    public static function plusDays(int $n): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))
            ->modify("+{$n} days")->format('Y-m-d');
    }

    /**
     * Public events whose WIB start date falls in [from, to] (inclusive).
     *
     * @return array{data:array{from:string,to:string,events:list<array>},errors:array<string,string>}
     */
    public function mingguan(string $from, string $to): array
    {
        $this->validDate($from);
        $this->validDate($to);
        if ($to < $from) {
            throw new InvalidArgumentException('to must be on or after from.');
        }

        try {
            $events = $this->eventsBetween($from, $to);
        } catch (Throwable $e) {
            return [
                'data' => ['from' => $from, 'to' => $to, 'events' => []],
                'errors' => ['event' => $e->getMessage()],
            ];
        }

        return [
            'data' => ['from' => $from, 'to' => $to, 'events' => $events],
            'errors' => [],
        ];
    }

    /**
     * Confirmed talent appearances (DJ/Band) on one date.
     *
     * @return array{data:array{date:string,rows:list<array>},errors:array<string,string>}
     */
    public function talentHarian(string $date): array
    {
        $this->validDate($date);

        try {
            $rows = $this->talentsOn($date);
        } catch (Throwable $e) {
            return [
                'data' => ['date' => $date, 'rows' => []],
                'errors' => ['talent' => $e->getMessage()],
            ];
        }

        return [
            'data' => ['date' => $date, 'rows' => $rows],
            'errors' => [],
        ];
    }

    // ── Events ───────────────────────────────────────────────────────

    /** Scheduled, non-draft events in the range. Mirrors InfoPagi::eventHari(). */
    private function eventsBetween(string $from, string $to): array
    {
        $db = ReadDb::for('event');
        $rows = $db->select(
            'SELECT id, title, category, status, venue, start_datetime, end_datetime, is_ticketed, data'
            .' FROM `events` WHERE DATE(start_datetime) BETWEEN ? AND ?'
            ." AND status NOT IN ('Planning', 'Draft', 'Cancelled')"
            .' ORDER BY start_datetime, title',
            [$from, $to]
        );

        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) ($r->data ?? ''), true);
            if (! is_array($d)) {
                $d = [];
            }
            $mulai = (string) ($r->start_datetime ?? '');
            $selesai = (string) ($r->end_datetime ?? '');
            $out[] = [
                'id' => (string) ($r->id ?? ''),
                'nama' => (string) ($r->title ?? ''),
                'kategori' => (string) ($r->category ?? ''),
                'status' => (string) ($r->status ?? ''),
                'venue' => (string) ($r->venue ?? ''),
                'mulai' => strlen($mulai) >= 16 ? substr($mulai, 0, 16) : $mulai,
                'selesai' => strlen($selesai) >= 16 ? substr($selesai, 0, 16) : $selesai,
                'deskripsi' => (string) ($d['description'] ?? ''),
                'bertiket' => ! empty($r->is_ticketed),
            ];
        }

        return $out;
    }

    // ── Talents ──────────────────────────────────────────────────────

    /**
     * Confirmed schedules of the day with talent + event names resolved.
     * LEFT JOINs so a schedule still answers when its talent/event row is
     * gone; names fall back to the stored ids.
     */
    private function talentsOn(string $date): array
    {
        $db = ReadDb::for('event');
        $rows = $db->select(
            'SELECT s.talent_id, s.event_id, s.tanggal, s.start_time, s.end_time,'
            .' s.performance_type, s.status,'
            .' t.name AS talent_name, t.category AS talent_category,'
            .' e.title AS event_title, e.venue AS event_venue, e.start_datetime AS event_mulai'
            .' FROM `schedules` s'
            .' LEFT JOIN `talents` t ON t.id = s.talent_id'
            .' LEFT JOIN `events` e ON e.id = s.event_id'
            .' WHERE s.tanggal = ? AND s.status IN (\'Scheduled\',\'Confirmed\',\'Done\')'
            .' ORDER BY s.start_time, t.name',
            [$date]
        );

        $out = [];
        foreach ($rows as $r) {
            $jam = trim(implode(' - ', array_filter([
                substr((string) ($r->start_time ?? ''), 0, 5),
                substr((string) ($r->end_time ?? ''), 0, 5),
            ], fn ($v) => $v !== '' && $v !== '--:--')));
            $eventMulai = (string) ($r->event_mulai ?? '');
            $out[] = [
                'talent' => (string) ($r->talent_name ?? '') !== ''
                    ? (string) $r->talent_name
                    : (string) ($r->talent_id ?? ''),
                'kategori' => (string) ($r->talent_category ?? ''),
                'event' => (string) ($r->event_title ?? '') !== ''
                    ? (string) $r->event_title
                    : (string) ($r->event_id ?? ''),
                'venue' => (string) ($r->event_venue ?? ''),
                'tanggal' => (string) ($r->tanggal ?? $date),
                'jam' => $jam,
                'tipe' => (string) ($r->performance_type ?? ''),
                'mulaiEvent' => strlen($eventMulai) >= 16 ? substr($eventMulai, 0, 16) : $eventMulai,
            ];
        }

        return $out;
    }

    private function validDate(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('date must be YYYY-MM-DD.');
        }

        return $date;
    }
}
