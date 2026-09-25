<?php

namespace App\Modules\Hlife\Services;

use App\Support\JsonDoc;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * Granular writes for /api/v1/hlife (the legacy app only has whole-state saveAll).
 *
 * The hlife tables have no version column, so a record's version is a hash of
 * its stored JSON: any write through either surface (v1 or compat saveAll)
 * changes it. Writes check it under SELECT … FOR UPDATE.
 */
class HlifeRecords
{
    public function __construct(private readonly HlifeState $state) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('hlife');
    }

    public static function version(string $storedJson): string
    {
        return substr(sha1($storedJson), 0, 16);
    }

    /** v1 resource name => table (the twelve collections + the finance ledger). */
    public static function table(string $resource): string
    {
        if ($resource === 'ledger' || isset(HlifeState::COLLECTIONS[$resource])) {
            return HlifeState::COLLECTIONS[$resource] ?? 'ledger';
        }
        throw new RuntimeException("unknown resource $resource");
    }

    /** @return list<array{record:stdClass, version:string}> */
    public function list(string $resource): array
    {
        $table = self::table($resource);
        $order = $table === 'ledger' ? ' ORDER BY `bulan`' : '';
        $out = [];
        foreach ($this->db()->select("SELECT `data` FROM `$table`".$order) as $r) {
            $o = json_decode($r->data);
            if (is_object($o)) {
                $out[] = ['record' => $o, 'version' => self::version($r->data)];
            }
        }

        return $out;
    }

    /** @return array{record:stdClass, version:string}|null */
    public function find(string $resource, string $id, bool $lock = false): ?array
    {
        $table = self::table($resource);
        $r = $this->db()->selectOne("SELECT `data` FROM `$table` WHERE `id` = ?".($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $r) {
            return null;
        }
        $o = json_decode($r->data);

        return ['record' => is_object($o) ? $o : new stdClass, 'version' => self::version($r->data)];
    }

    /** @return array{record:stdClass, version:string} ; throws HlifeConflict('exists') */
    public function create(string $resource, stdClass $rec): array
    {
        $table = self::table($resource);
        if (! isset($rec->id) || $rec->id === '') {
            $rec = (object) (['id' => self::newId()] + (array) $rec);
        }

        return $this->db()->transaction(function () use ($resource, $table, $rec) {
            if ($this->find($resource, (string) $rec->id, true)) {
                throw new HlifeConflict('exists');
            }
            $this->state->upsert($table, $rec, true);

            return $this->find($resource, (string) $rec->id);
        });
    }

    /**
     * Replace ($merge=false) or shallow-merge ($merge=true) one record.
     *
     * @return array{record:stdClass, version:string}|null null = not found ; throws HlifeConflict
     */
    public function update(string $resource, string $id, stdClass $fields, string $baseVersion, bool $merge): ?array
    {
        $table = self::table($resource);

        return $this->db()->transaction(function () use ($resource, $table, $id, $fields, $baseVersion, $merge) {
            $cur = $this->find($resource, $id, true);
            if (! $cur) {
                return null;
            }
            if (! hash_equals($cur['version'], $baseVersion)) {
                throw new HlifeConflict('stale', $cur);
            }
            $rec = clone ($merge ? $cur['record'] : $fields);
            if ($merge) {
                foreach ($fields as $k => $v) {
                    $rec->$k = $v;
                }
            }
            $rec->id = $cur['record']->id ?? $id;
            $this->state->upsert($table, $rec, true);

            return $this->find($resource, $id);
        });
    }

    /** @return bool false = not found ; throws HlifeConflict */
    public function delete(string $resource, string $id, string $baseVersion): bool
    {
        $table = self::table($resource);

        return $this->db()->transaction(function () use ($resource, $table, $id, $baseVersion) {
            $cur = $this->find($resource, $id, true);
            if (! $cur) {
                return false;
            }
            if (! hash_equals($cur['version'], $baseVersion)) {
                throw new HlifeConflict('stale', $cur);
            }
            $this->db()->delete("DELETE FROM `$table` WHERE `id` = ?", [$id]);

            return true;
        });
    }

    // ───────────────────────────── settings ──

    /** @return array{value:mixed, version:string} ; a missing row reads as its default. */
    public function setting(string $key, bool $lock = false): array
    {
        $r = $this->db()->selectOne('SELECT `v` FROM `settings` WHERE `k` = ?'.($lock ? ' FOR UPDATE' : ''), [$key]);
        $raw = $r ? $r->v : JsonDoc::encode(HlifeState::settingDefault($key));

        return ['value' => json_decode($raw), 'version' => self::version($raw)];
    }

    /** @return array<string, array{value:mixed, version:string}> */
    public function settings(): array
    {
        $out = [];
        foreach (HlifeState::SETTINGS_KEYS as $k) {
            $out[$k] = $this->setting($k);
        }

        return $out;
    }

    /** @return array{value:mixed, version:string} ; throws HlifeConflict */
    public function putSetting(string $key, mixed $value, string $baseVersion): array
    {
        return $this->db()->transaction(function () use ($key, $value, $baseVersion) {
            $cur = $this->setting($key, true);
            if (! hash_equals($cur['version'], $baseVersion)) {
                throw new HlifeConflict('stale', $cur);
            }
            $this->state->putSetting($key, $value);

            return $this->setting($key);
        });
    }

    /** Same shape as the frontend's uid(): 7 chars of [0-9a-z]. */
    private static function newId(): string
    {
        $s = '';
        for ($i = 0; $i < 7; $i++) {
            $s .= '0123456789abcdefghijklmnopqrstuvwxyz'[random_int(0, 35)];
        }

        return $s;
    }
}
