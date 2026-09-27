<?php

namespace App\Modules\Reservasi\Services;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Single-record writes for /api/v1/reservasi, on the SAME rules as legacy
 * saveAll: inline photos are moved to disk (@f:<key>), the row's `updated_at`
 * is its version, and every write bumps the global `_ver` so an old tab that
 * loaded before it gets its "reload" conflict instead of reconciling (and
 * deleting) around it.
 */
class ReservasiRecords
{
    public function __construct(private readonly ReservasiState $state) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('reservasi');
    }

    /** @return list<array> reservations with `tanggal` in [from, to] (either bound optional) */
    public function list(?string $from, ?string $to): array
    {
        $w = [];
        $a = [];
        if ($from) {
            $w[] = 'tanggal >= ?';
            $a[] = $from;
        }
        if ($to) {
            $w[] = 'tanggal <= ?';
            $a[] = $to;
        }
        $out = [];
        foreach ($this->db()->select('SELECT data FROM '.ReservasiState::t('reservations').($w ? ' WHERE '.implode(' AND ', $w) : '').' ORDER BY created_at ASC, '.ReservasiState::idCol().' ASC', $a) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** @return array{row:array,version:int}|null */
    public function find(string $id, bool $lock = false): ?array
    {
        $r = $this->db()->selectOne('SELECT data, updated_at FROM '.ReservasiState::t('reservations').' WHERE '.ReservasiState::idCol().' = ?'.($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $r) {
            return null;
        }
        $d = json_decode((string) $r->data, true);

        return ['row' => is_array($d) ? $d : ['id' => $id], 'version' => (int) $r->updated_at];
    }

    /**
     * Create ($base === null) or replace a reservation. Throws ReservasiConflict
     * ('exists' / 'stale' with the current row) — the caller maps it to 409.
     *
     * @return array{row:array,version:int}
     */
    public function put(array $row, ?int $base, bool $mustExist, ?array $audit = null): array
    {
        return $this->state->writeLocked(function () use ($row, $base, $mustExist, $audit) {
            $id = (string) $row['id'];
            $cur = $this->find($id, true);
            if ($mustExist && ! $cur) {
                throw new ReservasiConflict('missing');
            }
            if (! $mustExist && $cur) {
                throw new ReservasiConflict('exists');
            }
            if ($cur && $cur['version'] !== $base) {
                throw new ReservasiConflict('stale', $cur);
            }
            $now = (int) floor(microtime(true) * 1000);
            $row['updatedAt'] = max($now, ($cur['version'] ?? 0) + 1);
            $row['createdAt'] = $cur['row']['createdAt'] ?? ($row['createdAt'] ?? $now);
            if ($audit) {
                $row['log'] = array_slice([ReservasiState::logEntry($audit), ...array_values(is_array($row['log'] ?? null) ? $row['log'] : [])], 0, 20);
            }
            $this->state->upsertRow($this->state->externalizeOne('reservation', $row)); // photos never stay inline
            if ($audit) {
                $this->state->appendAudit($audit);
            }
            $this->state->bumpVer();

            return $this->find($id);
        });
    }

    public function delete(string $id, int $base, ?array $audit = null): bool
    {
        return $this->state->writeLocked(function () use ($id, $base, $audit) {
            $cur = $this->find($id, true);
            if (! $cur) {
                return false;
            }
            if ($cur['version'] !== $base) {
                throw new ReservasiConflict('stale', $cur);
            }
            $this->db()->delete('DELETE FROM '.ReservasiState::t('reservations').' WHERE '.ReservasiState::idCol().' = ?', [$id]);
            $this->state->deleteRowFiles($cur['row']);
            if ($audit) {
                $this->state->appendAudit($audit);
            }
            $this->state->bumpVer();

            return true;
        });
    }

    /** The ID-bearing master collections that get item-level writes. */
    public const ITEM_SECTIONS = ['reviews', 'feedbacks', 'waitlist'];

    /** POST /audit — an action that belongs to no reservation row. */
    public function logAudit(string $action, string $detail, array $actor, ?string $res = null): array
    {
        if (trim($action) === '') {
            throw new RuntimeException('action is required');
        }

        return $this->state->writeLocked(function () use ($action, $detail, $actor, $res) {
            $row = $this->state->auditEntry($action, $detail, $actor, $res);
            $this->state->appendAudit($row);
            $this->state->bumpVer();

            return $row;
        });
    }

    /** Every master part is versioned by its own content, like Kompas's blob parts. */
    public static function version(mixed $value): string
    {
        return substr(sha1(json_encode($value)), 0, 16);
    }

    /** @return array{value:mixed,version:string} master blob (shared with Service Excellent) and its content hash */
    public function master(): array
    {
        $m = $this->state->readMaster();

        return ['value' => $m, 'version' => self::version($m)];
    }

    public function section(string $name): mixed
    {
        return $this->state->readMaster()[$name] ?? null;
    }

    /**
     * Replace ONE master section, guarded by that section's own content hash.
     * A write to one screen therefore never conflicts with another section.
     */
    public function putSection(string $name, mixed $value, string $base): array
    {
        return $this->state->writeLocked(function () use ($name, $value, $base) {
            $master = $this->lockedMaster();
            $current = $master[$name] ?? null;
            if (! hash_equals(self::version($current), $base)) {
                throw new ReservasiConflict('stale', $current);
            }
            if ($value === null) {
                unset($master[$name]);
            } else {
                $master[$name] = $value;
            }
            $master = $this->state->saveMasterRaw($this->state->externalizeSection($master, $name));
            $this->state->bumpVer();

            return ['value' => $master[$name] ?? null, 'version' => self::version($master[$name] ?? null)];
        });
    }

    /** Replace one review / feedback / waitlist item, keeping every other item and section. */
    public function putItem(string $section, string $id, array $item, string $base): array
    {
        return $this->state->writeLocked(function () use ($section, $id, $item, $base) {
            $master = $this->lockedMaster();
            $items = is_array($master[$section] ?? null) ? array_values($master[$section]) : [];
            $at = $this->itemIndex($items, $id);
            if ($at === null || ! hash_equals(self::version($items), $base)) {
                throw new ReservasiConflict($at === null ? 'missing' : 'stale', $at === null ? null : $items);
            }
            $old = $items[$at];
            $items[$at] = $this->state->externalizeItem($section, $item);
            $master[$section] = $items;
            $master = $this->state->saveMasterRaw($master);
            $this->state->deleteItemFiles($section, $old, $items[$at]);
            $this->state->bumpVer();

            return ['value' => $master[$section][$at], 'version' => self::version($master[$section])];
        });
    }

    public function deleteItem(string $section, string $id, string $base): array
    {
        return $this->state->writeLocked(function () use ($section, $id, $base) {
            $master = $this->lockedMaster();
            $items = is_array($master[$section] ?? null) ? array_values($master[$section]) : [];
            $at = $this->itemIndex($items, $id);
            if ($at === null || ! hash_equals(self::version($items), $base)) {
                throw new ReservasiConflict($at === null ? 'missing' : 'stale', $at === null ? null : $items);
            }
            $old = $items[$at];
            array_splice($items, $at, 1);
            $master[$section] = $items;
            $master = $this->state->saveMasterRaw($master);
            $this->state->deleteItemFiles($section, $old);
            $this->state->bumpVer();

            return ['deleted' => true, 'version' => self::version($items)];
        });
    }

    public function putMaster(mixed $value, string $base): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('master must be an object');
        }

        return $this->state->writeLocked(function () use ($value, $base) {
            $this->db()->selectOne('SELECT v FROM '.ReservasiState::t('settings')." WHERE k='_ver' FOR UPDATE");
            $cur = $this->master();
            if (! hash_equals($cur['version'], $base)) {
                throw new ReservasiConflict('stale', ['master' => $cur['value']]);
            }
            $this->state->saveMaster($value);
            $this->state->bumpVer();

            return $this->master();
        });
    }

    /** serialise with legacy saveAll, then lock the exact master row being edited */
    private function lockedMaster(): array
    {
        $this->db()->selectOne('SELECT v FROM '.ReservasiState::t('settings')." WHERE k='_ver' FOR UPDATE");

        return $this->state->readMaster(true) ?? [];
    }

    private function itemIndex(array $items, string $id): ?int
    {
        foreach ($items as $i => $item) {
            if (is_array($item) && (string) ($item['id'] ?? '') === $id) {
                return $i;
            }
        }

        return null;
    }
}
