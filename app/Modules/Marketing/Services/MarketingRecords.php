<?php

namespace App\Modules\Marketing\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single-record access for /api/v1/marketing — the granular counterpart of
 * the legacy whole-state saveAll, built on the SAME RowSync guards and the
 * SAME mkt_save lock, so a v1 client and an old laksamana-office tab can
 * never silently overwrite each other:
 *
 *   - every record carries `updatedAt` (ms) = its version
 *   - update/delete require the version the client last saw; a newer stored
 *     version => 409 conflict, UNLESS the content is identical (sidik) => no-op
 *   - a successful write stamps max(now, stored+1) into data.updatedAt,
 *     which is exactly what legacy clients read back as their next baseUpdatedAt
 *   - writes append an entry to `activities` (the module's audit timeline)
 *
 * Collections (API name => app key): see RESOURCES.
 */
class MarketingRecords
{
    /** api resource => [app key, id prefix, human label] */
    public const RESOURCES = [
        'clients' => ['clients', 'c', 'Client'],
        'events' => ['events', 'e', 'Event'],
        'followups' => ['followups', 'f', 'Follow up'],
        'approvals' => ['approvals', 'ap', 'Approval'],
        'users' => ['users', 'u', 'User'],
        'staff' => ['staff', 's', 'Pegawai'],
        'task-templates' => ['taskTemplates', 'tt', 'Template task'],
        'task-categories' => ['taskCategories', 'tc', 'Kategori task'],
        'categories' => ['categories', 'cat', 'Kategori'],
        'notifications' => ['notifs', 'n', 'Notifikasi'],
        'vip' => ['vip', 'vip', 'Reservasi VIP'],
        'design-requests' => ['designreqs', 'dr', 'Request Design'],
    ];

    /** Settings documents editable through v1 (whole-object, hash-versioned). */
    public const DOCUMENTS = ['settings', 'baseline', 'rolePerms', 'roleNav', 'menuDb', 'katalog', 'fbFormats', 'fbSubs', 'roleAcc'];

    private function db(): ConnectionInterface
    {
        return Modules::db('marketing');
    }

    public static function resource(string $name): array
    {
        if (! isset(self::RESOURCES[$name])) {
            throw new \InvalidArgumentException("unknown resource $name");
        }
        [$key, $prefix, $label] = self::RESOURCES[$name];
        $inSettings = in_array($key, MarketingSchema::settingsCollections(), true);

        return ['key' => $key, 'prefix' => $prefix, 'label' => $label, 'inSettings' => $inSettings,
            'def' => $inSettings ? null : MarketingSchema::collections()[$key]];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    // ───────────────────────────── reads ──

    /**
     * @param  array{from?:string,to?:string,status?:string,pic?:string,clientId?:string,eventId?:string,updatedSince?:int,q?:string}  $f
     * @return array{rows:array,total:int}
     */
    public function list(string $resource, array $f, int $page, int $perPage): array
    {
        $r = self::resource($resource);
        $rows = $r['inSettings'] ? RowSync::settingsRows($this->db(), $r['key']) : $this->tableRows($r['def'], $f);
        $rows = array_values(array_filter($rows, fn ($x) => is_array($x) && $this->matches($x, $f)));
        $total = count($rows);

        return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => $total];
    }

    private function tableRows(array $def, array $f): array
    {
        $cols = $def['cols'];
        $where = [];
        $args = [];
        $map = ['status' => 'status', 'pic' => 'mkt_pic', 'clientId' => 'client_id', 'eventId' => 'event_id'];
        foreach ($map as $key => $col) {
            if (! empty($f[$key]) && isset($cols[$col])) {
                $where[] = "`$col` = ?";
                $args[] = $f[$key];
            }
        }
        if (isset($cols['tanggal'])) {
            if (! empty($f['from'])) {
                $where[] = '`tanggal` >= ?';
                $args[] = $f['from'];
            }
            if (! empty($f['to'])) {
                $where[] = '`tanggal` <= ?';
                $args[] = $f['to'];
            }
        }
        if (! empty($f['updatedSince'])) {
            $where[] = '`updated_at` > ?';
            $args[] = (int) $f['updatedSince'];
        }
        $order = ! empty($def['created']) ? 'created_at DESC, id DESC' : 'id ASC';
        $sql = "SELECT data FROM `{$def['table']}`".($where ? ' WHERE '.implode(' AND ', $where) : '')." ORDER BY $order";
        $out = [];
        foreach ($this->db()->select($sql, $args) as $row) {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** Filters that also apply to settings-stored rows (and a free-text q on any row). */
    private function matches(array $row, array $f): bool
    {
        if (! empty($f['status']) && isset($row['status']) && (string) $row['status'] !== $f['status']) {
            return false;
        }
        if (! empty($f['from']) && isset($row['tanggal']) && (string) $row['tanggal'] < $f['from']) {
            return false;
        }
        if (! empty($f['to']) && isset($row['tanggal']) && (string) $row['tanggal'] > $f['to']) {
            return false;
        }
        if (! empty($f['updatedSince']) && RowSync::ms($row['updatedAt'] ?? 0) <= (int) $f['updatedSince']) {
            return false;
        }
        if (! empty($f['q']) && stripos(json_encode($row, JSON_UNESCAPED_UNICODE), (string) $f['q']) === false) {
            return false;
        }

        return true;
    }

    public function find(string $resource, string $id): ?array
    {
        $r = self::resource($resource);
        if ($r['inSettings']) {
            foreach (RowSync::settingsRows($this->db(), $r['key']) as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                    return $row;
                }
            }

            return null;
        }
        $row = $this->db()->selectOne("SELECT data FROM `{$r['def']['table']}` WHERE id = ?", [$id]);
        $d = $row ? json_decode((string) $row->data, true) : null;

        return is_array($d) ? $d : null;
    }

    public static function versionOf(array $row): int
    {
        return RowSync::ms($row['updatedAt'] ?? 0);
    }

    // ───────────────────────────── writes (all under mkt_save) ──

    /** @return array{row:array} ; throws RecordConflict / RuntimeException('exists') */
    public function create(string $resource, array $data, string $by): array
    {
        return NamedLock::run('marketing', 'mkt_save', function () use ($resource, $data, $by) {
            $r = self::resource($resource);
            $id = trim((string) ($data['id'] ?? ''));
            if ($id === '') {
                $id = $r['prefix'].'_'.strtolower(Str::random(7));
            }
            if ($this->find($resource, $id)) {
                throw new RecordConflict('exists', null);
            }
            $now = self::nowMs();
            $data['id'] = $id;
            $data['createdAt'] = $data['createdAt'] ?? $now;
            $data['updatedAt'] = $now;
            unset($data['baseUpdatedAt']);
            $this->write($r, $data);
            $this->log($r, $id, $data, 'ditambah', $by);

            return ['row' => $this->find($resource, $id)];
        });
    }

    /** Replace the whole record (fields not sent are dropped) or merge ($merge=true). */
    public function update(string $resource, string $id, array $data, int $baseVersion, string $by, bool $merge): array
    {
        return NamedLock::run('marketing', 'mkt_save', function () use ($resource, $id, $data, $baseVersion, $by, $merge) {
            $r = self::resource($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            $next = $merge ? array_replace($cur, $data) : $data;
            $next['id'] = $id;
            if (! isset($next['createdAt']) && isset($cur['createdAt'])) {
                $next['createdAt'] = $cur['createdAt'];
            }
            unset($next['baseUpdatedAt']);
            $stored = self::versionOf($cur);
            if ($stored !== $baseVersion) {
                if (RowSync::sidik($cur) === RowSync::sidik($next)) {
                    return ['row' => $cur, 'unchanged' => true];
                }
                throw new RecordConflict('conflict', $cur);
            }
            $next['updatedAt'] = max(self::nowMs(), $stored + 1);
            $this->write($r, $next);
            $this->log($r, $id, $next, 'diubah', $by);

            return ['row' => $this->find($resource, $id)];
        });
    }

    public function delete(string $resource, string $id, int $baseVersion, string $by): void
    {
        NamedLock::run('marketing', 'mkt_save', function () use ($resource, $id, $baseVersion, $by) {
            $r = self::resource($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            if (self::versionOf($cur) !== $baseVersion) {
                throw new RecordConflict('conflict', $cur);
            }
            $db = $this->db();
            $db->transaction(function () use ($db, $r, $id) {
                if ($r['inSettings']) {
                    $rows = array_values(array_filter(RowSync::settingsRows($db, $r['key']),
                        fn ($x) => ! (is_array($x) && (string) ($x['id'] ?? '') === $id)));
                    RowSync::putSetting($db, 'extra:'.$r['key'], $rows);
                } else {
                    $db->delete("DELETE FROM `{$r['def']['table']}` WHERE id = ?", [$id]);
                }
            });
            $this->log($r, $id, $cur, 'dihapus', $by);
        });
    }

    /** Stamp is already set on $row; write through RowSync (ordering guard kept). Caller holds the lock. */
    private function write(array $r, array $row): void
    {
        $db = $this->db();
        $bentrok = [];
        $versi = [];
        $db->transaction(function () use ($db, $r, $row, &$bentrok, &$versi) {
            if ($r['inSettings']) {
                RowSync::upsertSettingsCollection($db, $r['key'], [$row], MarketingSchema::SYNC, $bentrok, $versi, 0);
            } else {
                RowSync::upsertCollection($db, $r['def'], [$row], $r['key'], ['conflict' => false], $bentrok, $versi);
            }
        });
    }

    private function log(array $r, string $id, array $row, string $verb, string $by): void
    {
        $name = $row['nama'] ?? $row['name'] ?? $row['judul'] ?? $id;
        $refType = rtrim($r['key'], 's');
        app(MarketingState::class)->appendActivities($this->db(), [[
            'id' => 'act_'.strtolower(Str::random(10)),
            'refType' => $refType, 'refId' => $id,
            'action' => $r['label'].' '.$verb.' (API)',
            'detail' => is_scalar($name) ? (string) $name : $id,
            'by' => $by,
            'at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]]);
    }

    // ───────────────────────────── documents (settings k/v) ──

    public static function documentKey(string $doc): string
    {
        if (! in_array($doc, self::DOCUMENTS, true)) {
            throw new \InvalidArgumentException("unknown document $doc");
        }

        return in_array($doc, MarketingSchema::scalarKeys(), true) ? $doc : 'extra:'.$doc;
    }

    /** @return array{value:mixed,version:string} version = sha1 of the stored JSON */
    public function document(string $doc): array
    {
        $row = $this->db()->selectOne('SELECT v FROM settings WHERE k = ?', [self::documentKey($doc)]);
        $raw = $row ? (string) $row->v : 'null';

        return ['value' => json_decode($raw, true), 'version' => sha1($raw)];
    }

    public function putDocument(string $doc, mixed $value, string $baseVersion): array
    {
        return NamedLock::run('marketing', 'mkt_save', function () use ($doc, $value, $baseVersion) {
            $cur = $this->document($doc);
            if ($cur['version'] !== $baseVersion) {
                throw new RecordConflict('conflict', ['value' => $cur['value'], 'version' => $cur['version']]);
            }
            RowSync::putSetting($this->db(), self::documentKey($doc), $value);

            return $this->document($doc);
        });
    }
}
