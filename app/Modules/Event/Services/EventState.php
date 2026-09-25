<?php

namespace App\Modules\Event\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The whole EMS state — port of baca_state(), save_all(), events_hari() and
 * stats() from event-mysql/lib_event_mysql.php.
 *
 * Rules kept exactly (see docs/modules/event.md):
 *  - Table collections upsert with the updated_at guard only: no
 *    baseUpdatedAt check, no cap bump, rows stored verbatim (RowSync
 *    'keepBase'). A collection not sent is not touched.
 *  - Deletion of rows missing from the payload is bounded by the newest
 *    updatedAt IN the payload ('maxUpd'): a row another crew created after
 *    this client loaded is never deleted by it. A payload without any stamp
 *    deletes without a bound; an empty list deletes nothing.
 *  - checkins are append-only (INSERT IGNORE, never updated or deleted) and
 *    getAll returns only the newest 2000.
 *  - eventDetails is a map event_id => detail; missing entries are deleted
 *    without a bound (legacy passes no max there).
 *  - settings: entertainmentRules, role, layoutTemplates (one JSON value each).
 *  - saveAll runs under GET_LOCK('<db>:ems_save') and in one transaction.
 *
 * The EMS database is shared with ticketing: nothing here touches
 * seat_holds or the tix_* tables.
 */
class EventState
{
    public const CHECKIN_LIMIT = 2000;

    private function db(): ConnectionInterface
    {
        return Modules::db('event');
    }

    /** Exactly the object DB the app uses. */
    public function read(): array
    {
        $db = $this->db();
        $out = [];

        foreach (EventSchema::collections() as $name => $c) {
            $order = ! empty($c['created']) ? 'created_at ASC, id ASC' : 'id ASC';
            $out[$name] = $this->rows($db->select("SELECT data FROM `{$c['table']}` ORDER BY $order"));
        }

        // checkins: append-only, newest first (capped so the blob stays small)
        $out['checkins'] = $this->rows($db->select('SELECT data FROM checkins ORDER BY checked_in_at DESC LIMIT '.self::CHECKIN_LIMIT));

        // eventDetails: map event_id -> detail (PHP array keys: "12" becomes int, like legacy)
        $ed = [];
        foreach ($db->select('SELECT event_id, data FROM event_details') as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                $ed[$row->event_id] = $r;
            }
        }
        $out['eventDetails'] = $ed;

        foreach (EventSchema::SETTINGS as $k => $default) {
            $out[$k] = $this->setting($k, $default);
        }

        return $out;
    }

    private function rows(array $result): array
    {
        $rows = [];
        foreach ($result as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    public function setting(string $k, mixed $default): mixed
    {
        $row = $this->db()->selectOne('SELECT v FROM settings WHERE k = ? LIMIT 1', [$k]);
        if (! $row) {
            return $default;
        }
        $v = json_decode((string) $row->v, true);

        return $v === null ? $default : $v;
    }

    /** Run $fn under the legacy ems_save lock, with the legacy busy message. */
    public static function locked(\Closure $fn): mixed
    {
        try {
            return NamedLock::run('event', EventSchema::LOCK, $fn);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'server sibuk, coba lagi') {
                throw new RuntimeException(EventSchema::BUSY);
            }
            throw $e;
        }
    }

    /** save_all(): the lock is taken BEFORE the payload is checked, like legacy. */
    public function saveAll(mixed $state): array
    {
        return self::locked(fn () => $this->saveAllLocked($state));
    }

    private function saveAllLocked(mixed $state): array
    {
        if (! is_array($state)) {
            throw new RuntimeException('Payload data kosong/invalid');
        }
        $db = $this->db();
        $hitung = $db->transaction(function () use ($db, $state) {
            $hitung = [];
            $bentrok = [];
            $versi = [];

            foreach (EventSchema::collections() as $name => $c) {
                if (! array_key_exists($name, $state)) {
                    continue; // not sent -> untouched
                }
                $rows = is_array($state[$name]) ? $state[$name] : [];
                $ids = RowSync::upsertCollection($db, $c, $rows, $name, ['keepBase' => true], $bentrok, $versi);
                RowSync::deleteMissing($db, $c['table'], 'id', $ids, 'maxUpd', self::maxStamp($rows));
                $hitung[$name] = count($ids);
            }

            // checkins: append-only, never overwritten or deleted
            if (isset($state['checkins']) && is_array($state['checkins'])) {
                $n = 0;
                foreach ($state['checkins'] as $ci) {
                    if (! is_array($ci) || empty($ci['id'])) {
                        continue;
                    }
                    $this->insertCheckin($ci);
                    $n++;
                }
                $hitung['checkins'] = $n;
            }

            // eventDetails: one row per event
            if (isset($state['eventDetails']) && is_array($state['eventDetails'])) {
                $ids = [];
                foreach ($state['eventDetails'] as $eid => $d) {
                    if (! is_array($d)) {
                        continue;
                    }
                    $ids[] = (string) $eid;
                    $this->upsertDetail((string) $eid, $d);
                }
                RowSync::deleteMissing($db, 'event_details', 'event_id', $ids, 'notIn');
                $hitung['eventDetails'] = count($ids);
            }

            foreach (array_keys(EventSchema::SETTINGS) as $k) {
                if (isset($state[$k])) {
                    RowSync::putSetting($db, $k, $state[$k]);
                }
            }

            return $hitung;
        });

        return ['saved' => true, 'jumlah' => $hitung, 'backend' => 'laravel', 'ts' => gmdate('c')];
    }

    /** Newest updatedAt among the rows that are actually written (id present). */
    private static function maxStamp(array $rows): int
    {
        $max = 0;
        foreach ($rows as $r) {
            if (is_array($r) && ! empty($r['id'])) {
                $max = max($max, RowSync::ms($r['updatedAt'] ?? 0));
            }
        }

        return $max;
    }

    /** INSERT IGNORE: an existing check-in is never touched. Returns true when inserted. */
    public function insertCheckin(array $ci): bool
    {
        return $this->db()->affectingStatement('INSERT IGNORE INTO checkins
                (id, ticket_id, checked_in_at, staff, gate, result, data) VALUES (?,?,?,?,?,?,?)', [
            (string) $ci['id'],
            RowSync::ambil($ci, 'ticket_id', 'strRaw'),
            RowSync::ambil($ci, 'checked_in_at', 'datetimeWib'),
            RowSync::ambil($ci, 'staff', 'strRaw'),
            RowSync::ambil($ci, 'gate', 'strRaw'),
            RowSync::ambil($ci, 'result', 'strRaw'),
            RowSync::enc($ci),
        ]) > 0;
    }

    /** One event_details row with the updated_at guard. */
    public function upsertDetail(string $eventId, array $d): void
    {
        $this->db()->statement('INSERT INTO event_details (event_id, updated_at, data) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE
              data       = IF(VALUES(updated_at) >= updated_at, VALUES(data),       data),
              updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)',
            [$eventId, RowSync::ms($d['updatedAt'] ?? 0), RowSync::enc($d)]);
    }

    /**
     * events_hari(): the events of one date that actually happen (read by
     * Finance > Omset > Breakdown Sumber). Filter is a DROP list on purpose:
     * old rows still carry pre-migration statuses (Today, Finished…).
     */
    public function eventsHari(string $tgl): array
    {
        if (RowSync::tanggal($tgl) === null) {
            throw new RuntimeException('tanggal tidak sah: '.$tgl);
        }
        $out = [];
        foreach ($this->db()->select(
            "SELECT id, title, status, venue, pic, start_datetime, data
               FROM events
              WHERE DATE(start_datetime) = ?
                AND status NOT IN ('Planning', 'Draft', 'Cancelled')
              ORDER BY start_datetime, title", [$tgl]) as $r) {
            // Events born before 2026-09-04 have no createdBy: '' (not null), and a
            // broken blob reads as empty instead of dropping the whole day.
            $d = json_decode((string) $r->data, true);
            if (! is_array($d)) {
                $d = [];
            }
            $out[] = [
                'id' => $r->id,
                'nama' => $r->title,
                'status' => $r->status,
                'venue' => $r->venue,
                'picName' => $r->pic,
                'inputOleh' => isset($d['createdBy']) ? RowSync::strRaw($d['createdBy']) : '',
                'inputOlehId' => isset($d['createdById']) ? RowSync::strRaw($d['createdById']) : '',
                'mulai' => $r->start_datetime,
            ];
        }

        return ['events' => $out];
    }

    public function identity(): array
    {
        return ['env' => Modules::envLabel(), 'db' => Modules::databaseName('event'), 'versi' => EventSchema::LIB_VERSI];
    }

    public function stats(): array
    {
        $db = $this->db();
        $out = array_merge(['backend' => 'laravel'], $this->identity());
        foreach (EventSchema::STATS_TABLES as $t) {
            $out[$t] = (int) $db->selectOne("SELECT COUNT(*) AS c FROM `$t`")->c;
        }
        $blob = strlen(RowSync::enc($this->read()));
        $out['blobChars'] = $blob;
        $out['blobMB'] = round($blob / 1048576, 3);
        $out['ts'] = gmdate('c');

        return $out;
    }
}
