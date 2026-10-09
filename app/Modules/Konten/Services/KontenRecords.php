<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single-record access for /api/v1/konten — the granular counterpart of
 * the legacy whole-state saveAll, built on the SAME guards and the SAME
 * konten_save lock, so a v1 client and an old laksamana-office tab can
 * never silently overwrite each other:
 *
 *   - every record carries `updatedAt` (ms) = its version
 *   - update/delete require the version the client last saw; a newer stored
 *     version => 409 conflict, UNLESS the content is identical (sidik) => no-op
 *   - a successful write stamps max(now, stored+1) into data.updatedAt,
 *     which is exactly what legacy clients read back as their next baseUpdatedAt
 *   - writes append an entry to `logs` (the module's audit trail)
 *
 * Collections (API name => app key): see RESOURCES.
 */
class KontenRecords
{
    /** api resource => [app key, id prefix, human label] */
    public const RESOURCES = [
        'users' => ['users', 'u', 'Kru'],
        'brands' => ['brands', 'b', 'Brand'],
        'campaigns' => ['campaigns', 'cam', 'Campaign'],
        'content' => ['content', 'ct', 'Konten'],
        'prod-tasks' => ['prodTasks', 'pt', 'Task produksi'],
        'shootings' => ['shootings', 'sh', 'Shooting'],
        'assets' => ['assets', 'as', 'Aset'],
        'bank' => ['bank', 'bk', 'Ide bank'],
        'kols' => ['kols', 'kol', 'KOL'],
        'visits' => ['visits', 'vs', 'Kunjungan'],
        'ads' => ['ads', 'ad', 'Iklan'],
        'ad-funds' => ['adFunds', 'fd', 'Dana iklan'],
        'notifications' => ['notifs', 'nt', 'Notifikasi'],
    ];

    /** Settings documents editable through v1 (whole-object, hash-versioned). */
    public const DOCUMENTS = ['settings', 'perms', 'seeded'];

    private function db(): ConnectionInterface
    {
        return Modules::db('konten');
    }

    public static function resource(string $name): array
    {
        if (! isset(self::RESOURCES[$name])) {
            throw new \InvalidArgumentException("unknown resource $name");
        }
        [$key, $prefix, $label] = self::RESOURCES[$name];

        return ['key' => $key, 'prefix' => $prefix, 'label' => $label,
            'def' => KontenSchema::defs()[$key]];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    // ───────────────────────────── reads ──

    /**
     * @param  array{from?:string,to?:string,status?:string,brand?:string,pic?:string,platform?:string,campaign?:string,updatedSince?:int,q?:string}  $f
     * @return array{rows:array,total:int}
     */
    public function list(string $resource, array $f, int $page, int $perPage): array
    {
        $r = self::resource($resource);
        $rows = array_values(array_filter($this->tableRows($r['def'], $f), fn ($x) => is_array($x) && $this->matches($x, $f)));
        $total = count($rows);

        return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => $total];
    }

    private function tableRows(array $def, array $f): array
    {
        $cols = $def['cols'];
        $where = [];
        $args = [];
        foreach (['status' => 'status', 'brand' => 'brand', 'pic' => 'pic', 'platform' => 'platform', 'campaign' => 'campaign', 'kolId' => 'kol_id'] as $key => $col) {
            if (! empty($f[$key]) && isset($cols[$col])) {
                $where[] = "`$col` = ?";
                $args[] = $f[$key];
            }
        }
        // Date window: `tanggal` where it exists, else the start date.
        $dateCol = isset($cols['tanggal']) ? 'tanggal' : (isset($cols['start_date']) ? 'start_date' : null);
        if ($dateCol !== null) {
            if (! empty($f['from'])) {
                $where[] = "`$dateCol` >= ?";
                $args[] = $f['from'];
            }
            if (! empty($f['to'])) {
                $where[] = "`$dateCol` <= ?";
                $args[] = $f['to'];
            }
        }
        if (! empty($f['updatedSince'])) {
            $where[] = '`updated_at` > ?';
            $args[] = (int) $f['updatedSince'];
        }
        $order = ! empty($def['created']) ? KontenSchema::createdOrder() : KontenSchema::idOrder();
        $sql = "SELECT data FROM `{$def['table']}`".($where ? ' WHERE '.implode(' AND ', $where) : '')." ORDER BY $order";
        $out = [];
        foreach ($this->db()->select($sql, $args) as $row) {
            $d = json_decode((string) $row->data, true);
            if (is_array($d)) {
                $out[] = self::perfObjek($d);
            }
        }

        return $out;
    }

    /** Free-text q on any row (plus the same date/status guards for safety). */
    private function matches(array $row, array $f): bool
    {
        if (! empty($f['status']) && isset($row['status']) && (string) $row['status'] !== $f['status']) {
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
        $idCol = KontenSchema::idCol();
        $row = $this->db()->selectOne("SELECT data FROM `{$r['def']['table']}` WHERE `$idCol` = ?", [$id]);
        $d = $row ? json_decode((string) $row->data, true) : null;

        return is_array($d) ? self::perfObjek($d) : null;
    }

    /**
     * #261: an empty `perf` must leave as a JSON object, never `[]`. PHP decodes
     * `{}` to an empty array and json_encode writes it back as `[]`. The old Office
     * does `c.perf=c.perf||{}` (an array is truthy, so it stays an array) and then
     * `c.perf[p]=…`; JSON.stringify drops named keys on an array, so the next
     * figures typed there vanished on save. The old konten-mysql getAll had the
     * same decode, so the legacy read deliberately differs here (`{}` not `[]`).
     */
    public static function perfObjek(array $row): array
    {
        if (array_key_exists('perf', $row) && $row['perf'] === []) {
            $row['perf'] = new \stdClass;
        }

        return $row;
    }

    public static function versionOf(array $row): int
    {
        return RowSync::ms($row['updatedAt'] ?? 0);
    }

    // ───────────────────────────── writes (all under konten_save) ──

    /** @return array{row:array} ; throws RecordConflict / RuntimeException('exists') */
    public function create(string $resource, array $data, string $by): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($resource, $data, $by) {
            $r = self::resource($resource);
            $id = trim((string) ($data['id'] ?? ''));
            if ($id === '') {
                $id = $r['prefix'].'_'.strtolower(Str::random(10));
            }
            if ($this->find($resource, $id)) {
                throw new RecordConflict('exists', null);
            }
            $now = self::nowMs();
            $data['id'] = $id;
            $data['createdAt'] = $data['createdAt'] ?? $now;
            $data['updatedAt'] = $now;
            unset($data['baseUpdatedAt']);
            $this->writeRow($r['def'], $data);
            $this->log($r, $id, $data, 'ditambah', $by);

            return ['row' => $this->find($resource, $id)];
        });
    }

    /** Replace the whole record (fields not sent are dropped) or merge ($merge=true). */
    public function update(string $resource, string $id, array $data, int $baseVersion, string $by, bool $merge): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($resource, $id, $data, $baseVersion, $by, $merge) {
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
            $this->writeRow($r['def'], $next);
            $this->log($r, $id, $next, 'diubah', $by);

            return ['row' => $this->find($resource, $id)];
        });
    }

    public function delete(string $resource, string $id, int $baseVersion, string $by): void
    {
        NamedLock::run('konten', 'konten_save', function () use ($resource, $id, $baseVersion, $by) {
            $r = self::resource($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            if (self::versionOf($cur) !== $baseVersion) {
                throw new RecordConflict('conflict', $cur);
            }
            $idCol = KontenSchema::idCol();
            $this->db()->delete("DELETE FROM `{$r['def']['table']}` WHERE `$idCol` = ?", [$id]);
            $this->log($r, $id, $cur, 'dihapus', $by);
        });
    }

    /**
     * Merge one platform's performance figures into a content row
     * (content.data.perf per platform), or remove that platform's entry
     * when $metrics is null (the old Input Performa screen deletes
     * perf[platform] once every box of that platform is emptied).
     * Other platforms are kept. Returns the stored row.
     */
    public function setPerformance(string $id, string $platform, ?array $metrics, string $by): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($id, $platform, $metrics, $by) {
            $r = self::resource('content');
            $cur = $this->find('content', $id);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            $platform = trim($platform);
            if ($platform === '') {
                throw new RuntimeException('platform kosong');
            }
            $perf = isset($cur['perf']) && is_array($cur['perf']) ? $cur['perf'] : [];
            if ($metrics === null) {
                if (! array_key_exists($platform, $perf)) {
                    return ['row' => $cur, 'unchanged' => true];
                }
                unset($perf[$platform]);
                $cur['perf'] = $perf === [] ? new \stdClass : $perf;
                unset($cur['baseUpdatedAt']);
                $cur['updatedAt'] = max(self::nowMs(), self::versionOf($cur) + 1);
                $this->writeRow($r['def'], $cur);
                $this->log($r, $id, $cur, 'diubah', $by.' (performa '.$platform.' dihapus)');

                return ['row' => $this->find('content', $id)];
            }
            $perf[$platform] = array_merge($metrics, ['at' => self::nowMs(), 'by' => $by]);
            $cur['perf'] = $perf;
            unset($cur['baseUpdatedAt']);
            $cur['updatedAt'] = max(self::nowMs(), self::versionOf($cur) + 1);
            $this->writeRow($r['def'], $cur);
            $this->log($r, $id, $cur, 'diubah', $by.' (performa '.$platform.')');

            return ['row' => $this->find('content', $id)];
        });
    }

    /** Stamp is already set on $row; same INSERT...ON DUPLICATE shape as saveAll. Caller holds the lock. */
    private function writeRow(array $def, array $row): void
    {
        $db = $this->db();
        $idCol = $def['id'] ?? 'id';
        $useUlid = ! empty($def['ulid']);
        $versioned = ! empty($def['versioned']);
        $cols = $def['cols'];
        $hasCreated = ! empty($def['created']);
        $names = array_merge([$idCol], array_keys($cols), ['updated_at']);
        if ($hasCreated) {
            $names[] = 'created_at';
        }
        $names[] = 'data';
        if ($versioned) {
            $names[] = 'version';
        }
        if ($useUlid) {
            array_unshift($names, 'id');
        }
        $upd = [];
        foreach (array_merge(array_keys($cols), ['data']) as $n) {
            $upd[] = "`$n` = IF(VALUES(updated_at) >= updated_at, VALUES(`$n`), `$n`)";
        }
        $upd[] = '`updated_at` = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)';
        if ($versioned) {
            $upd[] = '`version` = `version` + 1';
        }
        $core = KontenSchema::onCore();
        $args = [(string) $row['id']];
        foreach ($cols as $col => [$field, $type]) {
            $v = RowSync::ambil($row, $field, $type);
            $args[] = $core ? RowSync::fit($db, $def['table'], $col, $v) : $v;
        }
        $args[] = RowSync::ms($row['updatedAt'] ?? 0);
        if ($hasCreated) {
            $args[] = RowSync::ms($row['createdAt'] ?? 0);
        }
        $args[] = RowSync::enc($row);
        if ($versioned) {
            $args[] = 1;
        }
        if ($useUlid) {
            array_unshift($args, strtolower((string) Str::ulid()));
        }
        $db->transaction(fn () => $db->statement(
            "INSERT INTO `{$def['table']}` (`".implode('`,`', $names).'`) VALUES ('
            .implode(',', array_fill(0, count($names), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', $upd),
            $args,
        ));
    }

    private function log(array $r, string $id, array $row, string $verb, string $by): void
    {
        $name = $row['title'] ?? $row['nama'] ?? $row['name'] ?? $id;
        app(KontenState::class)->appendLogs($this->db(), [[
            'id' => 'lg_'.strtolower(Str::random(10)),
            'by' => $by,
            'action' => $r['label'].' '.$verb.' (API)',
            'target' => is_scalar($name) ? (string) $name : $id,
            'at' => self::nowMs(),
        ]]);
    }

    // ───────────────────────────── logs (audit trail) ──

    /** @return array{rows:array,total:int} */
    public function logs(?string $refId, int $limit): array
    {
        $args = [];
        $t = KontenSchema::table('logs');
        $idCol = KontenSchema::idCol();
        $sql = "SELECT data FROM `$t`";
        if ($refId !== null && $refId !== '') {
            $sql .= ' WHERE ref_id = ?';
            $args[] = $refId;
        }
        $sql .= " ORDER BY at_ms DESC, `$idCol` DESC LIMIT ".min(max($limit, 1), 5000);
        $rows = array_values(array_filter(array_map(fn ($x) => json_decode((string) $x->data, true),
            $this->db()->select($sql, $args)), 'is_array'));

        return ['rows' => $rows, 'total' => count($rows)];
    }

    public function logActivity(array $entry, string $by): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($entry, $by) {
            $now = self::nowMs();
            $row = $entry + ['id' => 'lg_'.strtolower(Str::random(10)), 'by' => $by, 'at' => $now];
            app(KontenState::class)->appendLogs($this->db(), [$row]);
            $t = KontenSchema::table('logs');
            $idCol = KontenSchema::idCol();
            $this->db()->statement("DELETE FROM `$t` WHERE `$idCol` NOT IN
                (SELECT `$idCol` FROM (SELECT `$idCol` FROM `$t` ORDER BY at_ms DESC, `$idCol` DESC LIMIT 5000) t)");

            return ['row' => $row];
        });
    }

    // ───────────────────────────── documents (settings k/v) ──

    public static function documentKey(string $doc): string
    {
        if (! in_array($doc, self::DOCUMENTS, true)) {
            throw new \InvalidArgumentException("unknown document $doc");
        }

        return $doc;
    }

    /** @return array{value:mixed,version:string} version = sha1 of the stored JSON */
    public function document(string $doc): array
    {
        $t = KontenSchema::table('settings');
        $row = $this->db()->selectOne("SELECT v FROM `$t` WHERE k = ?", [self::documentKey($doc)]);
        $raw = $row ? (string) $row->v : 'null';

        return ['value' => json_decode($raw, true), 'version' => sha1($raw)];
    }

    public function putDocument(string $doc, mixed $value, string $baseVersion): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($doc, $value, $baseVersion) {
            $cur = $this->document($doc);
            if ($cur['version'] !== $baseVersion) {
                throw new RecordConflict('conflict', ['value' => $cur['value'], 'version' => $cur['version']]);
            }
            RowSync::putSetting($this->db(), self::documentKey($doc), $value,
                KontenSchema::table('settings'), KontenSchema::onCore());

            return $this->document($doc);
        });
    }
}
