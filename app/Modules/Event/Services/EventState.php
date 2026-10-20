<?php

namespace App\Modules\Event\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The whole EMS state — port of baca_state(), save_all(), events_hari() and
 * stats() from event-mysql/lib_event_mysql.php.
 *
 * Rules kept exactly (see docs/modules/event.md):
 *  - Stale-tab guards (legacy b0a64d5, 1 Oct 2026; #302): a row carrying
 *    `baseUpdatedAt` older than the stored updated_at is REFUSED and reported
 *    in `bentrok` (the device clock used to let a stale tab always win); one
 *    that passes gets its stamp bumped above the server's when needed, written
 *    into `data` and returned in `versi`. `baseUpdatedAt` is never stored.
 *    Rows without it keep the plain updated_at guard. A collection not sent
 *    is not touched.
 *  - Deletion: a client that sends `dikenal` (ids it has seen, per
 *    collection) deletes only dikenal - payload; an empty payload deleting
 *    more than 3 rows is refused. Old clients without `dikenal` keep the old
 *    rule: bounded by the newest updatedAt IN the payload ('maxUpd'); a
 *    payload without any stamp deletes without a bound; an empty list
 *    deletes nothing.
 *  - checkins are append-only (INSERT IGNORE, never updated or deleted) and
 *    getAll returns only the newest 2000.
 *  - eventDetails is a map event_id => detail with the same two guards
 *    (keys 'ed:<id>' in versi); without `dikenal`, missing entries are
 *    deleted without a bound (legacy passes no max there).
 *  - getAll returns each row's updatedAt from the updated_at COLUMN (legacy
 *    5157c03): ticketing bumps the column without touching the JSON, and the
 *    guard compares with the column.
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

        foreach (EventSchema::defs() as $name => $c) {
            $key = $c['id'];
            $order = ! empty($c['created']) ? "created_at ASC, `$key` ASC" : "`$key` ASC";
            $out[$name] = $this->rows($db->select("SELECT data, updated_at FROM `{$c['table']}` ORDER BY $order"), true);
        }

        // checkins: append-only, newest first (capped so the blob stays small)
        $out['checkins'] = $this->rows($db->select('SELECT data FROM `'.EventSchema::table('checkins')
            .'` ORDER BY checked_in_at DESC LIMIT '.self::CHECKIN_LIMIT));

        // eventDetails: map event_id -> detail (PHP array keys: "12" becomes int, like legacy)
        $ed = [];
        foreach ($db->select('SELECT event_id, data FROM `'.EventSchema::table('event_details').'`') as $row) {
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

    private function rows(array $result, bool $stampFromColumn = false): array
    {
        $rows = [];
        foreach ($result as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                // the guard compares with the column, so that is the version returned (5157c03)
                if ($stampFromColumn && (int) ($row->updated_at ?? 0) > 0) {
                    $r['updatedAt'] = (int) $row->updated_at;
                }
                $rows[] = $r;
            }
        }

        return $rows;
    }

    public function setting(string $k, mixed $default): mixed
    {
        $row = $this->db()->selectOne('SELECT v FROM `'.EventSchema::table('settings').'` WHERE k = ? LIMIT 1', [$k]);
        if (! $row) {
            return $default;
        }
        $v = json_decode((string) $row->v, true);

        return $v === null ? $default : $v;
    }

    /** Run $fn under the legacy ems_save lock, with the legacy busy message. */
    public static function locked(\Closure $fn): mixed
    {
        return NamedLock::run('event', EventSchema::LOCK, $fn, 10, EventSchema::BUSY);
    }

    /** save_all(): the lock is taken BEFORE the payload is checked, like legacy. */
    public function saveAll(mixed $state, ?array $dikenal = null): array
    {
        return self::locked(fn () => $this->saveAllLocked($state, $dikenal));
    }

    /**
     * resetData (deploy/event Pengaturan "KOSONGKAN SEMUA DATA", G-13 / #187):
     * the old page sent bawaanKosong() through saveAll — every collection [],
     * no event details, blank entertainment rules, role Director. saveAll never
     * empties a table from an empty payload (RowSync::deleteMissing), so here
     * every collection table and event_details are emptied explicitly, under
     * the ems_save lock in one transaction. Check-ins stay (append-only, never
     * deleted), seat holds and the ticket shop's own tables are not touched, and
     * settings outside bawaanKosong (layout templates) are kept.
     *
     * @return array{jumlah: array<string,int>}
     */
    public function resetAll(): array
    {
        return self::locked(function () {
            $db = $this->db();

            return $db->transaction(function () use ($db) {
                $n = [];
                foreach (EventSchema::defs() as $name => $c) {
                    $n[$name] = $db->delete("DELETE FROM `{$c['table']}`");
                }
                $n['eventDetails'] = $db->delete('DELETE FROM `'.EventSchema::table('event_details').'`');
                $settings = EventSchema::table('settings');
                RowSync::putSetting($db, 'entertainmentRules', array_map(fn ($d) => ['day' => $d, 'rule' => ''],
                    ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']), $settings, EventSchema::onCore());
                RowSync::putSetting($db, 'role', 'Director', $settings, EventSchema::onCore());

                return ['jumlah' => $n];
            });
        });
    }

    private function saveAllLocked(mixed $state, ?array $dikenal): array
    {
        if (! is_array($state)) {
            throw new RuntimeException('Payload data kosong/invalid');
        }
        $db = $this->db();

        // #100 owner decision (fix, not reproduce): a qr_token that already
        // belongs to another ticket refuses the WHOLE save before anything is
        // written. Legacy let the upsert's ON DUPLICATE KEY UPDATE fire on the
        // UNIQUE qr_token, silently rewriting the other ticket's row — and the
        // bounded delete then removed it as "not in the payload" (silent ticket
        // loss). v1 refuses the same case as a 409 duplicate (EventRecords).
        $tickets = EventSchema::defs()['tickets'];
        foreach (is_array($state['tickets'] ?? null) ? $state['tickets'] : [] as $row) {
            $qr = is_array($row) ? RowSync::strRaw($row['qr_token'] ?? null) : null;
            $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
            if ($qr === null || $qr === '' || $id === '') {
                continue;
            }
            if ($db->selectOne("SELECT `{$tickets['id']}` AS id FROM `{$tickets['table']}` WHERE qr_token = ? AND `{$tickets['id']}` <> ?", [$qr, $id])) {
                throw new RuntimeException("qr_token ganda: $qr");
            }
        }

        [$hitung, $bentrok, $versi] = $db->transaction(function () use ($db, $state, $dikenal) {
            $hitung = [];
            $bentrok = [];
            $versi = [];

            foreach (EventSchema::defs() as $name => $c) {
                if (! array_key_exists($name, $state)) {
                    continue; // not sent -> untouched
                }
                $rows = is_array($state[$name]) ? $state[$name] : [];
                $ids = RowSync::upsertCollection($db, $c, $rows, $name,
                    ['conflict' => true, 'capBump' => true, 'stampOnBump' => true, 'bareBentrok' => true, 'nameKeys' => ['name', 'title']], $bentrok, $versi);
                $kenal = self::known($dikenal, $name);
                if ($kenal !== null) {
                    RowSync::deleteKnown($db, $c['table'], $c['id'], $ids, $kenal);
                } else {
                    RowSync::deleteMissing($db, $c['table'], $c['id'], $ids, 'maxUpd', self::maxStamp($rows, $name, $bentrok, $versi));
                }
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
            // eventDetails used to be deleted without ANY bound: a stale tab removed
            // details other crews created after it loaded. Same two guards as above.
            if (isset($state['eventDetails']) && is_array($state['eventDetails'])) {
                $edTable = EventSchema::table('event_details');
                $verEd = [];
                $keys = array_map('strval', array_keys($state['eventDetails']));
                foreach (array_chunk($keys, 500) as $chunk) {
                    foreach ($db->select("SELECT event_id AS i, updated_at FROM `$edTable` WHERE event_id IN (".implode(',', array_fill(0, count($chunk), '?')).')', $chunk) as $row) {
                        $verEd[(string) $row->i] = (int) $row->updated_at;
                    }
                }
                $ids = [];
                foreach ($state['eventDetails'] as $eid => $d) {
                    if (! is_array($d)) {
                        continue;
                    }
                    $eid = (string) $eid;
                    $ids[] = $eid;
                    $passed = false;
                    if (array_key_exists('baseUpdatedAt', $d)) {
                        if (isset($verEd[$eid]) && $verEd[$eid] > RowSync::ms($d['baseUpdatedAt'])) {
                            $bentrok[] = ['koleksi' => 'eventDetails', 'id' => $eid, 'nama' => 'Rincian event '.$eid];

                            continue;
                        }
                        $passed = true;
                    }
                    unset($d['baseUpdatedAt']);
                    $uaSent = RowSync::ms($d['updatedAt'] ?? 0);
                    $ua = RowSync::capTulis($uaSent, $passed, $verEd[$eid] ?? null);
                    if ($ua !== $uaSent) {
                        $d['updatedAt'] = $ua;
                        $versi['ed:'.$eid] = $ua;
                    }
                    $this->upsertDetail($eid, $d);
                }
                $kenal = self::known($dikenal, 'eventDetails');
                if ($kenal !== null) {
                    RowSync::deleteKnown($db, $edTable, 'event_id', $ids, $kenal);
                } else {
                    RowSync::deleteMissing($db, $edTable, 'event_id', $ids, 'notIn');
                }
                $hitung['eventDetails'] = count($ids);
            }

            foreach (array_keys(EventSchema::SETTINGS) as $k) {
                if (isset($state[$k])) {
                    RowSync::putSetting($db, $k, $state[$k], EventSchema::table('settings'), EventSchema::onCore());
                }
            }

            return [$hitung, $bentrok, $versi];
        });

        return ['saved' => true, 'jumlah' => $hitung, 'bentrok' => $bentrok, 'versi' => (object) $versi, 'backend' => 'laravel', 'ts' => gmdate('c')];
    }

    /** The ids the client has seen for one collection, or null (old client: old delete rule). */
    private static function known(?array $dikenal, string $name): ?array
    {
        return isset($dikenal[$name]) && is_array($dikenal[$name]) ? $dikenal[$name] : null;
    }

    /** Newest updatedAt among the rows that are actually written (id present, not refused). */
    private static function maxStamp(array $rows, string $name, array $bentrok, array $versi): int
    {
        // as legacy: refused rows are not written, and a bumped stamp counts as written
        $refused = [];
        foreach ($bentrok as $b) {
            if ($b['koleksi'] === $name) {
                $refused[$b['id']] = true;
            }
        }
        $max = 0;
        foreach ($rows as $r) {
            if (is_array($r) && ! empty($r['id']) && ! isset($refused[(string) $r['id']])) {
                $max = max($max, $versi[$name.':'.$r['id']] ?? RowSync::ms($r['updatedAt'] ?? 0));
            }
        }

        return $max;
    }

    /** INSERT IGNORE: an existing check-in is never touched. Returns true when inserted. */
    public function insertCheckin(array $ci): bool
    {
        $id = (string) $ci['id'];
        $cols = [
            RowSync::ambil($ci, 'ticket_id', 'strRaw'),
            RowSync::ambil($ci, 'checked_in_at', 'datetimeWib'),
            RowSync::ambil($ci, 'staff', 'strRaw'),
            RowSync::ambil($ci, 'gate', 'strRaw'),
            RowSync::ambil($ci, 'result', 'strRaw'),
            RowSync::enc($ci),
        ];
        $table = EventSchema::table('checkins');
        if (! EventSchema::onCore()) {
            return $this->db()->affectingStatement("INSERT IGNORE INTO `$table`
                (id, ticket_id, checked_in_at, staff, gate, result, data) VALUES (?,?,?,?,?,?,?)", [$id, ...$cols]) > 0;
        }

        foreach (['ticket_id' => 0, 'staff' => 2, 'gate' => 3, 'result' => 4] as $col => $i) {
            $cols[$i] = RowSync::fit($this->db(), $table, $col, $cols[$i]);
        }

        return $this->db()->affectingStatement("INSERT IGNORE INTO `$table`
            (id, legacy_id, ticket_id, checked_in_at, staff, gate, result, data, version) VALUES (?,?,?,?,?,?,?,?,1)",
            [strtolower((string) Str::ulid()), $id, ...$cols]) > 0;
    }

    /** One event_details row with the updated_at guard. */
    public function upsertDetail(string $eventId, array $d): void
    {
        $table = EventSchema::table('event_details');
        $stamp = RowSync::ms($d['updatedAt'] ?? 0);
        $data = RowSync::enc($d);
        if (! EventSchema::onCore()) {
            $this->db()->statement("INSERT INTO `$table` (event_id, updated_at, data) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE
              data       = IF(VALUES(updated_at) >= updated_at, VALUES(data),       data),
              updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)",
                [$eventId, $stamp, $data]);

            return;
        }

        // core: event_id stays the natural key; legacy_id carries the same value.
        $eventId = RowSync::fit($this->db(), $table, 'event_id', $eventId);
        $this->db()->statement("INSERT INTO `$table` (id, legacy_id, event_id, updated_at, data, version) VALUES (?,?,?,?,?,1)
            ON DUPLICATE KEY UPDATE
              `version`  = `version` + IF(VALUES(updated_at) >= updated_at, 1, 0),
              data       = IF(VALUES(updated_at) >= updated_at, VALUES(data),       data),
              updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)",
            [strtolower((string) Str::ulid()), $eventId, $eventId, $stamp, $data]);
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
        // On core the row key is `legacy_id`, aliased back to the legacy `id`.
        foreach ($this->db()->select(
            'SELECT `'.EventSchema::idCol().'` AS id, title, status, venue, pic, start_datetime, data
               FROM `'.EventSchema::table('events')."`
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
            $out[$t] = (int) $db->selectOne('SELECT COUNT(*) AS c FROM `'.EventSchema::table($t).'`')->c;
        }
        $blob = strlen(RowSync::enc($this->read()));
        $out['blobChars'] = $blob;
        $out['blobMB'] = round($blob / 1048576, 3);
        $out['ts'] = gmdate('c');

        return $out;
    }

    /** Is event served from `core` (DB_EVENT_CONNECTION=core)? */
    public static function onCore(): bool
    {
        return EventSchema::onCore();
    }

    // ────────── EMS records for the Modul that shares this database (ticketing) ──
    // The public shop owns seat_holds/tix_* only; it reads and writes the EMS
    // tables above through these methods, never with its own SQL (ADR-0002, #61).
    // $cols and $order are code literals, never request input.

    /**
     * Decoded `data` of the rows matching $where (legacy column names), in the
     * legacy order. Where values: null means IS NULL, ['notNull' => true] means
     * IS NOT NULL, ['ne' => $v] means <>, anything else is `=`.
     *
     * @return list<array>
     */
    public function emsRows(string $collection, array $where = [], string $order = '', int $limit = 0): array
    {
        $out = [];
        foreach ($this->emsSelect($collection, ['data'], $where, $order, $limit) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** One row's decoded `data` by its legacy id, or null. */
    public function emsRow(string $collection, string $id): ?array
    {
        return $this->emsRows($collection, ['id' => $id], '', 1)[0] ?? null;
    }

    /**
     * Full event rows starting in [from, to] for the Radar board, in read()
     * order. Dateless rows ride along for the `tanggal` fallback; the caller
     * still filters, so the output is identical to the full read.
     *
     * @return list<array<string,mixed>>
     */
    public function eventsForRange(string $from, string $to): array
    {
        $def = $this->emsDef('events');
        $key = $def['id'];
        $order = ! empty($def['created']) ? "created_at ASC, `$key` ASC" : "`$key` ASC";
        $out = [];
        foreach ($this->db()->select(
            "SELECT `data` FROM `{$def['table']}`".
            ' WHERE (`start_datetime` >= ? AND `start_datetime` < ?) OR `start_datetime` IS NULL OR `start_datetime` = ?'.
            " ORDER BY $order",
            [$from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00', '']) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** COUNT of events (the board's `n`, which must stay whole-table). */
    public function countEvents(): int
    {
        return $this->emsCount('events');
    }

    /** Raw column projection (legacy column names) — for column-only reads. */
    public function emsColumns(string $collection, array $cols, array $where = [], string $order = '', int $limit = 0): array
    {
        return $this->emsSelect($collection, $cols, $where, $order, $limit);
    }

    /** COUNT(*) with the same where protocol. */
    public function emsCount(string $collection, array $where = []): int
    {
        $def = $this->emsDef($collection);
        [$sql, $args] = $this->emsWhere("SELECT COUNT(*) AS c FROM `{$def['table']}`", $def, $where);

        return (int) ($this->db()->selectOne($sql, $args)?->c ?? 0);
    }

    /** Does the physical table behind $collection exist (ping/diagnostics probe)? */
    public function emsTableExists(string $collection): bool
    {
        return $this->db()->selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->emsDef($collection)['table']]
        ) !== null;
    }

    private function emsDef(string $collection): array
    {
        return EventSchema::defs()[$collection] ?? throw new RuntimeException("Unknown EMS collection [$collection].");
    }

    private function emsSelect(string $collection, array $cols, array $where, string $order = '', int $limit = 0): array
    {
        $def = $this->emsDef($collection);
        // `id` is always the LEGACY id the shop speaks, so alias it back on core.
        $sql = 'SELECT '.implode(', ', array_map(
            fn (string $c) => $c === 'id' ? "`{$def['id']}` AS `id`" : "`$c`",
            $cols
        ))." FROM `{$def['table']}`";
        [$sql, $args] = $this->emsWhere($sql, $def, $where);
        if ($order !== '') {
            $sql .= " ORDER BY $order";
        }
        if ($limit > 0) {
            $sql .= ' LIMIT '.$limit;
        }

        return $this->db()->select($sql, $args);
    }

    /** @return array{0:string,1:list<mixed>} the SQL with its WHERE appended */
    private function emsWhere(string $sql, array $def, array $where): array
    {
        $parts = [];
        $args = [];
        foreach ($where as $col => $v) {
            $col = $col === 'id' ? (string) $def['id'] : (string) $col;
            if ($v === null) {
                $parts[] = "`$col` IS NULL";
            } elseif (is_array($v) && array_key_exists('notNull', $v)) {
                $parts[] = "`$col` IS NOT NULL";
            } elseif (is_array($v) && array_key_exists('ne', $v)) {
                $parts[] = "`$col` <> ?";
                $args[] = $v['ne'];
            } else {
                $parts[] = "`$col` = ?";
                $args[] = $v;
            }
        }
        if ($parts) {
            $sql .= ' WHERE '.implode(' AND ', $parts);
        }

        return [$sql, $args];
    }

    // ── EMS writes for the public shop: the same statements, core-aware ──

    /**
     * saveOrder: insert, or overwrite the columns the shop owns. created_at is
     * written on INSERT only and there is no updated_at guard, exactly like the
     * legacy statement (a webhook may confirm an order the app just saved).
     */
    public function emsSaveOrder(array $o): void
    {
        $table = EventSchema::table('orders');
        $data = RowSync::enc($o);
        $cols = [$o['event_id'], $o['buyer_name'], $o['phone'], $o['email'], $o['total'],
            $o['payment_status'], $o['payment_ref']];
        if (EventSchema::onCore()) {
            foreach (['event_id' => 0, 'buyer_name' => 1, 'phone' => 2, 'email' => 3,
                'payment_status' => 5, 'payment_ref' => 6] as $col => $i) {
                $cols[$i] = RowSync::fit($this->db(), $table, $col, $cols[$i]);
            }
        }
        $upd = 'buyer_name=VALUES(buyer_name), phone=VALUES(phone), email=VALUES(email),
              total=VALUES(total), payment_status=VALUES(payment_status), payment_ref=VALUES(payment_ref),
              updated_at=VALUES(updated_at), data=VALUES(data)';
        if (! EventSchema::onCore()) {
            $this->db()->insert("INSERT INTO `$table` (id,event_id,buyer_name,phone,email,total,payment_status,payment_ref,updated_at,created_at,data)
                VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE $upd",
                [(string) $o['id'], ...$cols, $this->nowMs(), $this->nowMs(), $data]);

            return;
        }
        $this->db()->insert("INSERT INTO `$table` (id,legacy_id,event_id,buyer_name,phone,email,total,payment_status,payment_ref,updated_at,created_at,data,version)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE $upd, `version` = `version` + 1",
            [strtolower((string) Str::ulid()), (string) $o['id'], ...$cols, $this->nowMs(), $this->nowMs(), $data]);
    }

    /** UPDATE orders SET updated_at, data (a Buyer claiming orders placed before signing up). */
    public function emsTouchOrder(string $id, array $o, int $updatedAt): void
    {
        $this->db()->update('UPDATE `'.EventSchema::table('orders').'` SET `updated_at` = ?, `data` = ?'
            .$this->emsVersionSuffix().' WHERE `'.EventSchema::idCol().'` = ?',
            [$updatedAt, RowSync::enc($o), $id]);
    }

    /** Issue one ticket: a plain INSERT, exactly like the shop (a reused id must fail). */
    public function emsInsertTicket(array $tk, int $updatedAt): void
    {
        $table = EventSchema::table('tickets');
        $cols = [$tk['order_item_id'], $tk['ticket_class_id'], $tk['seat_id'], $tk['ticket_number'],
            $tk['qr_token'], 'Valid', $updatedAt, RowSync::enc($tk)];
        if (! EventSchema::onCore()) {
            $this->db()->insert("INSERT INTO `$table` (id,order_item_id,ticket_class_id,seat_id,ticket_number,qr_token,status,updated_at,data)
                VALUES (?,?,?,?,?,?,?,?,?)", [(string) $tk['id'], ...$cols]);

            return;
        }
        $this->db()->insert("INSERT INTO `$table` (id,legacy_id,order_item_id,ticket_class_id,seat_id,ticket_number,qr_token,status,updated_at,data,version)
            VALUES (?,?,?,?,?,?,?,?,?,?,1)", [strtolower((string) Str::ulid()), (string) $tk['id'], ...$cols]);
    }

    /** Ticket status + full row (the upgrade path cancels the old ticket). */
    public function emsSetTicketStatus(string $id, string $status, array $tk, int $updatedAt): void
    {
        $this->db()->update('UPDATE `'.EventSchema::table('tickets').'` SET `status` = ?, `updated_at` = ?, `data` = ?'
            .$this->emsVersionSuffix().' WHERE `'.EventSchema::idCol().'` = ?',
            [$status, $updatedAt, RowSync::enc($tk), $id]);
    }

    /** Seat status + full row (Sold on payment, Available again after an upgrade). */
    public function emsSetSeatStatus(string $id, string $status, array $seat, int $updatedAt): void
    {
        $this->db()->update('UPDATE `'.EventSchema::table('seats').'` SET `status` = ?, `updated_at` = ?, `data` = ?'
            .$this->emsVersionSuffix().' WHERE `'.EventSchema::idCol().'` = ?',
            [$status, $updatedAt, RowSync::enc($seat), $id]);
    }

    /** Ticket-class `sold` counter + full row. */
    public function emsSetClassSold(string $id, int $sold, array $c, int $updatedAt): void
    {
        $this->db()->update('UPDATE `'.EventSchema::table('ticket_classes').'` SET `sold` = ?, `updated_at` = ?, `data` = ?'
            .$this->emsVersionSuffix().' WHERE `'.EventSchema::idCol().'` = ?',
            [$sold, $updatedAt, RowSync::enc($c), $id]);
    }

    private function emsVersionSuffix(): string
    {
        return EventSchema::onCore() ? ', `version` = `version` + 1' : '';
    }

    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
