<?php

namespace App\Modules\Event\Services;

use App\Support\Modules;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Single-record access for /api/v1/event — the granular counterpart of the
 * legacy whole-state saveAll, on the SAME table rows, the SAME updated_at
 * guard and the SAME ems_save lock, so a v1 client and an old Event Planner
 * tab serialise against each other:
 *
 *   - a record's version is its `updated_at` column (ms); the old app stamps
 *     updatedAt on every changed row, so its saves move the version too
 *   - update/delete require the version the client last saw; a different
 *     stored version => EventConflict('stale') with the live record
 *   - a successful write stamps max(now, stored+1) into the column AND
 *     data.updatedAt, so the next legacy save of an older copy cannot
 *     overwrite it (the updated_at guard refuses older stamps)
 *   - event details (one per event), append-only check-ins and the three
 *     settings documents have their own methods below
 */
class EventRecords
{
    /** v1 resource => app collection key (see EventSchema::collections()). */
    public const RESOURCES = [
        'talents' => 'talents',
        'events' => 'events',
        'schedules' => 'schedules',
        'recurring-rules' => 'recurringRules',
        'talent-payments' => 'talentPayments',
        'ticket-classes' => 'ticketClasses',
        'seats' => 'seats',
        'orders' => 'orders',
        'tickets' => 'tickets',
        'ideas' => 'ideas',
        'refunds' => 'refunds',
        'calendar-extra' => 'calendarExtra',
    ];

    public function __construct(private readonly EventState $state) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('event');
    }

    public static function def(string $resource): array
    {
        $key = self::RESOURCES[$resource] ?? throw new InvalidArgumentException("unknown resource $resource");

        return ['key' => $key] + EventSchema::defs()[$key];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Same shape as the app's uid(): "id" + 7 chars of [0-9a-z]. */
    public static function newId(): string
    {
        $s = 'id';
        for ($i = 0; $i < 7; $i++) {
            $s .= '0123456789abcdefghijklmnopqrstuvwxyz'[random_int(0, 35)];
        }

        return $s;
    }

    // ───────────────────────────── records: reads ──

    /**
     * Filters: any indexed column by name (event_id, talent_id, status, …, exact),
     * `from`/`to` (YYYY-MM-DD) on the collection's date column, `updatedSince` (ms).
     *
     * Tickets carry no event_id column (legacy stores only order_item_id,
     * ticket_class_id, …), so `?event_id=` on tickets is resolved through the
     * order: a ticket matches when its own `event_id` (when present) equals the
     * filter, otherwise when its order (order_item_id/order_id → orders.event_id)
     * does. A ticket without a matching order is excluded.
     *
     * @return list<array{record:array, version:int}>
     */
    public function list(string $resource, array $f): array
    {
        $def = self::def($resource);
        $ticketEvent = $resource === 'tickets'
            && isset($f['event_id']) && is_string($f['event_id']) && $f['event_id'] !== ''
            ? $f['event_id'] : null;
        $where = [];
        $args = [];
        foreach (array_keys($def['cols']) as $col) {
            if (isset($f[$col]) && is_string($f[$col]) && $f[$col] !== '') {
                $where[] = "`$col` = ?";
                $args[] = $f[$col];
            }
        }
        $dateExpr = match (true) {
            isset($def['cols']['tanggal']) => '`tanggal`',
            isset($def['cols']['start_datetime']) => 'DATE(`start_datetime`)',
            isset($def['cols']['valid_from']) => '`valid_from`',
            default => null,
        };
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if ($dateExpr && isset($f[$k]) && RowSync::tanggal($f[$k]) !== null) {
                $where[] = "$dateExpr $op ?";
                $args[] = $f[$k];
            }
        }
        if (! empty($f['updatedSince']) && ctype_digit((string) $f['updatedSince'])) {
            $where[] = '`updated_at` > ?';
            $args[] = (int) $f['updatedSince'];
        }
        $key = $def['id'];
        $order = ! empty($def['created']) ? "created_at ASC, `$key` ASC" : "`$key` ASC";
        $sql = "SELECT updated_at, data FROM `{$def['table']}`".($where ? ' WHERE '.implode(' AND ', $where) : '')." ORDER BY $order";

        $out = [];
        foreach ($this->db()->select($sql, $args) as $row) {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $out[] = ['record' => $d, 'version' => (int) $row->updated_at];
            }
        }

        if ($ticketEvent !== null) {
            $orderDef = self::def('orders');
            $orderIds = [];
            foreach ($this->db()->select(
                "SELECT `{$orderDef['id']}` AS id FROM `{$orderDef['table']}` WHERE event_id = ?",
                [$ticketEvent]
            ) as $r) {
                $orderIds[(string) $r->id] = true;
            }
            $out = array_values(array_filter($out, function ($x) use ($ticketEvent, $orderIds) {
                $rec = $x['record'];
                if (isset($rec['event_id']) && (string) $rec['event_id'] === $ticketEvent) {
                    return true;
                }
                foreach (['order_item_id', 'order_id'] as $k) {
                    if (isset($rec[$k]) && $rec[$k] !== '' && isset($orderIds[(string) $rec[$k]])) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $out;
    }

    /** @return array{record:array, version:int}|null */
    public function find(string $resource, string $id): ?array
    {
        $def = self::def($resource);
        $row = $this->db()->selectOne("SELECT updated_at, data FROM `{$def['table']}` WHERE `{$def['id']}` = ?", [$id]);
        if (! $row) {
            return null;
        }
        $d = json_decode((string) $row->data, true);

        return ['record' => is_array($d) ? $d : ['id' => $id], 'version' => (int) $row->updated_at];
    }

    // ───────────────────────────── records: writes ──

    /**
     * Events remember who entered them (createdBy/createdById, read back by
     * eventsHari for Finance's Breakdown): always the acting user, never the body.
     *
     * @param  array{id:string,name:string}  $by
     * @return array{record:array, version:int}
     */
    public function create(string $resource, array $data, array $by): array
    {
        return $this->write(function () use ($resource, $data, $by) {
            $def = self::def($resource);
            $id = trim(RowSync::strRaw($data['id'] ?? '') ?? '');
            if ($id === '') {
                $id = self::newId();
            }
            if ($this->find($resource, $id)) {
                throw new EventConflict('exists');
            }
            $now = self::nowMs();
            $data['id'] = $id;
            if ($def['key'] === 'events') {
                $data['createdBy'] = $by['name'];
                $data['createdById'] = $by['id'];
            }
            $data['createdAt'] = $now;
            if (($def['created_field'] ?? null) === 'created' && empty($data['created'])) {
                $data['created'] = gmdate('Y-m-d\TH:i:s.v\Z'); // orders: the app's ISO `created`
            }
            $data['updatedAt'] = $now;
            $this->upsert($def, $data);

            return $this->find($resource, $id);
        });
    }

    /**
     * Replace ($merge=false: fields not sent are dropped) or shallow-merge one record.
     *
     * @return array{record:array, version:int}|null null = not found
     */
    public function update(string $resource, string $id, array $data, int $baseVersion, bool $merge): ?array
    {
        return $this->write(function () use ($resource, $id, $data, $baseVersion, $merge) {
            $def = self::def($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                return null;
            }
            if ($cur['version'] !== $baseVersion) {
                throw new EventConflict('stale', $cur['record']);
            }
            $next = $merge ? array_replace($cur['record'], $data) : $data;
            $next['id'] = $id;
            // Birth facts survive a replace: created_at never changes, and neither
            // does who entered the event.
            foreach (['createdAt', 'created', 'createdBy', 'createdById'] as $k) {
                if (array_key_exists($k, $cur['record'])) {
                    $next[$k] = $cur['record'][$k];
                }
            }
            $next['updatedAt'] = max(self::nowMs(), $cur['version'] + 1);
            $this->upsert($def, $next);

            return $this->find($resource, $id);
        });
    }

    /** @return bool false = not found */
    public function delete(string $resource, string $id, int $baseVersion): bool
    {
        return $this->write(function () use ($resource, $id, $baseVersion) {
            $def = self::def($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                return false;
            }
            if ($cur['version'] !== $baseVersion) {
                throw new EventConflict('stale', $cur['record']);
            }
            $this->db()->delete("DELETE FROM `{$def['table']}` WHERE `{$def['id']}` = ?", [$id]);

            return true;
        });
    }

    private function upsert(array $def, array $row): void
    {
        // tickets.qr_token is UNIQUE, and the upsert's ON DUPLICATE KEY UPDATE fires on
        // ANY unique key: a reused token would silently rewrite ANOTHER ticket's row.
        // v1 refuses it up front; compat saveAll refuses the whole save too (#100).
        if ($def['key'] === 'tickets' && ($qr = RowSync::strRaw($row['qr_token'] ?? null)) !== null
            && $this->db()->selectOne("SELECT `{$def['id']}` AS id FROM `{$def['table']}` WHERE qr_token = ? AND `{$def['id']}` <> ?", [$qr, (string) $row['id']])) {
            throw new EventConflict('duplicate');
        }
        $b = $v = [];
        try {
            RowSync::upsertCollection($this->db(), $def, [$row], $def['key'], ['keepBase' => true], $b, $v);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new EventConflict('duplicate');
            }
            throw $e;
        }
    }

    /** Every v1 write: the legacy ems_save lock + one transaction. */
    private function write(\Closure $fn): mixed
    {
        return EventState::locked(fn () => $this->db()->transaction($fn));
    }

    // ───────────────────────────── event details (one per event) ──

    /** @return array<string, array{record:array, version:int}> */
    public function details(): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT event_id, updated_at, data FROM `'.EventSchema::table('event_details').'` ORDER BY event_id') as $row) {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $out[(string) $row->event_id] = ['record' => $d, 'version' => (int) $row->updated_at];
            }
        }

        return $out;
    }

    /** @return array{record:array, version:int}|null */
    public function detail(string $eventId): ?array
    {
        $row = $this->db()->selectOne('SELECT updated_at, data FROM `'.EventSchema::table('event_details').'` WHERE event_id = ?', [$eventId]);
        if (! $row) {
            return null;
        }
        $d = json_decode((string) $row->data, true);

        return ['record' => is_array($d) ? $d : [], 'version' => (int) $row->updated_at];
    }

    /**
     * Create (no version needed) or replace (version required) one event's detail.
     *
     * @return array{record:array, version:int, created:bool}
     */
    public function putDetail(string $eventId, array $data, ?int $baseVersion): array
    {
        return $this->write(function () use ($eventId, $data, $baseVersion) {
            $cur = $this->detail($eventId);
            if ($cur && $baseVersion === null) {
                throw new EventConflict('version_required');
            }
            if ($cur && $cur['version'] !== $baseVersion) {
                throw new EventConflict('stale', $cur['record']);
            }
            $data['updatedAt'] = max(self::nowMs(), ($cur['version'] ?? 0) + 1);
            $this->state->upsertDetail($eventId, $data);

            return $this->detail($eventId) + ['created' => $cur === null];
        });
    }

    public function deleteDetail(string $eventId, int $baseVersion): bool
    {
        return $this->write(function () use ($eventId, $baseVersion) {
            $cur = $this->detail($eventId);
            if (! $cur) {
                return false;
            }
            if ($cur['version'] !== $baseVersion) {
                throw new EventConflict('stale', $cur['record']);
            }
            $this->db()->delete('DELETE FROM `'.EventSchema::table('event_details').'` WHERE event_id = ?', [$eventId]);

            return true;
        });
    }

    // ───────────────────────────── check-ins (append-only) ──

    /** Newest first; `ticket_id` filter; at most EventState::CHECKIN_LIMIT rows. */
    public function checkins(array $f, int $limit): array
    {
        $limit = max(1, min($limit, EventState::CHECKIN_LIMIT));
        $where = '';
        $args = [];
        if (isset($f['ticket_id']) && is_string($f['ticket_id']) && $f['ticket_id'] !== '') {
            $where = ' WHERE ticket_id = ?';
            $args[] = $f['ticket_id'];
        }
        $out = [];
        $sql = 'SELECT data FROM `'.EventSchema::table('checkins').'`'.$where
            .' ORDER BY checked_in_at DESC, `'.EventSchema::idCol()."` DESC LIMIT $limit";
        foreach ($this->db()->select($sql, $args) as $row) {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** One check-in row by its id, or null. Cancellation rows resolve through this too. */
    public function findCheckin(string $id): ?array
    {
        $row = $this->db()->selectOne('SELECT data FROM `'.EventSchema::table('checkins').'` WHERE `'.EventSchema::idCol().'` = ?', [$id]);
        if (! $row) {
            return null;
        }
        $d = json_decode((string) $row->data, true);

        return is_array($d) ? $d : null;
    }

    /**
     * Record one check-in. `staff` is the acting user's name (never the body);
     * `checked_in_at` defaults to now. An existing id is never overwritten.
     *
     * A cancellation is a correction ROW, not a delete (#185, owner decision
     * 2026-09-29): the body carries `batalDari` (the id of the check-in being
     * cancelled) instead of a fresh attendance. `ticket_id` may be omitted and
     * is then taken from the cancelled row; when sent it must match that row.
     * The cancelled rows stay in place, so no new table or column is needed.
     */
    public function addCheckin(array $data, string $staff): array
    {
        return $this->write(function () use ($data, $staff) {
            $cancel = $data['batalDari'] ?? null;
            if (is_string($cancel) && $cancel !== '') {
                $ref = $this->findCheckin($cancel);
                if (! $ref) {
                    throw new EventConflict('cancel_missing');
                }
                if (! empty($ref['batalDari'])) {
                    throw new EventConflict('cancel_invalid', 'A cancellation cannot be cancelled.');
                }
                $refTicket = RowSync::strRaw($ref['ticket_id'] ?? null) ?? '';
                $tid = RowSync::strRaw($data['ticket_id'] ?? null);
                if ($tid === null || $tid === '') {
                    $data['ticket_id'] = $refTicket;
                } elseif ($refTicket !== '' && $tid !== $refTicket) {
                    throw new EventConflict('cancel_invalid', 'ticket_id does not match the cancelled check-in.');
                }
                $data['batalDari'] = $cancel;
            }
            $id = trim(RowSync::strRaw($data['id'] ?? '') ?? '');
            $data['id'] = $id !== '' ? $id : self::newId();
            $data['staff'] = $staff;
            if (empty($data['checked_in_at'])) {
                $data['checked_in_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
            }
            if (! $this->state->insertCheckin($data)) {
                throw new EventConflict('exists');
            }

            return $data;
        });
    }

    // ───────────────────────────── settings documents ──

    public static function settingVersion(mixed $value): string
    {
        return substr(sha1(RowSync::enc($value)), 0, 16);
    }

    /** @return array{value:mixed, version:string} */
    public function setting(string $key): array
    {
        $v = $this->state->setting($key, EventSchema::SETTINGS[$key]);

        return ['value' => $v, 'version' => self::settingVersion($v)];
    }

    /** @return array{value:mixed, version:string} */
    public function putSetting(string $key, mixed $value, string $baseVersion): array
    {
        return $this->write(function () use ($key, $value, $baseVersion) {
            $cur = $this->setting($key);
            if (! hash_equals($cur['version'], $baseVersion)) {
                throw new EventConflict('stale', $cur['value']);
            }
            RowSync::putSetting($this->db(), $key, $value, EventSchema::table('settings'), EventSchema::onCore());

            return $this->setting($key);
        });
    }
}
