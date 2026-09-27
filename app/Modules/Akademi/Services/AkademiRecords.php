<?php

namespace App\Modules\Akademi\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single-record access for /api/v1/akademi — the granular counterpart of
 * the legacy whole-state saveAll, built on the SAME guards and the SAME
 * akademi_save lock, so a v1 client and an old laksamana-office tab can
 * never silently overwrite each other:
 *
 *   - every table record carries `updatedAt` (ms) = its version (the
 *     `updated_at` COLUMN — the real ordering guard; after a legacy
 *     cap-bump the column sits above data.updatedAt, and the column is
 *     what saveAll compares against)
 *   - update/delete require the version the client last saw; a newer stored
 *     version => 409 conflict, UNLESS the content is identical (sidik) => no-op
 *   - a successful write stamps max(now, stored+1) into the column AND
 *     data.updatedAt, which is exactly what legacy clients read back as
 *     their next baseUpdatedAt
 *   - writes append an entry to `activity` (the module's audit trail)
 *   - progress / program-progress (composite keys, nested maps in the app)
 *     are addressed per cell: PUT /progress/{user}/{material}
 *
 * Collections (API name => app key): see RESOURCES.
 */
class AkademiRecords
{
    /** api resource => [app key, id prefix, human label, activity verb base] */
    public const RESOURCES = [
        'users' => ['users', 'u', 'Kru', 'kru'],
        'divisions' => ['divisions', 'd', 'Divisi', 'divisi'],
        'materials' => ['materials', 'm', 'Materi', 'materi'],
        'programs' => ['programs', 'p', 'Program', 'program'],
    ];

    /** Settings documents editable through v1 (whole-object, hash-versioned). */
    public const DOCUMENTS = ['settings', 'version', 'createdAt'];

    private function db(): ConnectionInterface
    {
        return Modules::db('akademi');
    }

    public static function resource(string $name): array
    {
        if (! isset(self::RESOURCES[$name])) {
            throw new \InvalidArgumentException("unknown resource $name");
        }
        [$key, $prefix, $label, $verb] = self::RESOURCES[$name];

        return ['key' => $key, 'prefix' => $prefix, 'label' => $label, 'verb' => $verb,
            'def' => AkademiSchema::defs()[$key]];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    // ───────────────────────────── reads ──

    /**
     * @param  array{updatedSince?:int,q?:string,role?:string,division?:string,active?:string,type?:string,cat?:string,mandatory?:string,published?:string,bulan?:string}  $f
     * @return array{rows:array,total:int}
     */
    public function list(string $resource, array $f, int $page, int $perPage): array
    {
        $r = self::resource($resource);
        $rows = array_values(array_filter($this->tableRows($r['def'], $f), fn ($x) => is_array($x) && $this->matches($r['key'], $x, $f)));
        $total = count($rows);

        return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => $total];
    }

    private function tableRows(array $def, array $f): array
    {
        $cols = $def['cols'];
        $where = [];
        $args = [];
        // Indexed-column filters (exact vocabulary of the app fields).
        $map = ['role' => 'role', 'type' => 'kind', 'cat' => 'cat', 'bulan' => 'bulan'];
        foreach ($map as $key => $col) {
            if (isset($f[$key]) && $f[$key] !== '' && isset($cols[$col])) {
                $where[] = "`$col` = ?";
                $args[] = $f[$key];
            }
        }
        if (isset($f['division']) && $f['division'] !== '' && isset($cols['divisi'])) {
            $where[] = '`divisi` = ?';
            $args[] = $f['division'];
        }
        foreach (['mandatory' => 'mandatory', 'published' => 'published', 'active' => 'active'] as $key => $col) {
            if (isset($f[$key]) && $f[$key] !== '' && isset($cols[$col])) {
                $where[] = "`$col` = ?";
                $args[] = ((string) $f[$key] === '1' || $f[$key] === true || $f[$key] === 1) ? 1 : 0;
            }
        }
        if (! empty($f['updatedSince'])) {
            $where[] = '`updated_at` > ?';
            $args[] = (int) $f['updatedSince'];
        }
        $order = ! empty($def['created']) ? AkademiSchema::createdOrder() : AkademiSchema::idOrder();
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

    /** Free-text q (plus division[] membership for materials, which lives in data JSON). */
    private function matches(string $key, array $row, array $f): bool
    {
        if (! empty($f['division']) && $key === 'materials') {
            $div = $row['division'] ?? [];
            if (! is_array($div)) {
                $div = [$div];
            }
            if (! in_array($f['division'], $div, true) && ! in_array('all', $div, true) && $f['division'] !== 'all') {
                return false;
            }
        }
        if (! empty($f['updatedSince']) && RowSync::ms($row['updatedAt'] ?? 0) <= (int) $f['updatedSince']) {
            return false;
        }
        if (! empty($f['q']) && stripos(json_encode($row, JSON_UNESCAPED_UNICODE), (string) $f['q']) === false) {
            return false;
        }

        return true;
    }

    /** @return array{row:array,version:int}|null version = the updated_at column */
    public function find(string $resource, string $id): ?array
    {
        $r = self::resource($resource);
        $idCol = AkademiSchema::idCol();
        $row = $this->db()->selectOne("SELECT updated_at, data FROM `{$r['def']['table']}` WHERE `$idCol` = ?", [$id]);
        if (! $row) {
            return null;
        }
        $d = json_decode((string) $row->data, true);

        return is_array($d) ? ['row' => $d, 'version' => (int) $row->updated_at] : null;
    }

    // ───────────────────────────── writes (all under akademi_save) ──

    /** @return array{row:array,version:int} ; throws RecordConflict / RuntimeException('exists') */
    public function create(string $resource, array $data, string $by): array
    {
        return NamedLock::run('akademi', 'akademi_save', function () use ($resource, $data, $by) {
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
            if (! empty($r['def']['created']) && ! isset($data['createdAt'])) {
                $data['createdAt'] = $now;
            }
            $data['updatedAt'] = $now;
            unset($data['baseUpdatedAt']);
            $this->writeRow($r['def'], $data, $now);
            $this->log($r, 'tambah', $id, $data, $by);

            $found = $this->find($resource, $id);

            return ['row' => $found['row'], 'version' => $found['version']];
        });
    }

    /** Replace the whole record (fields not sent are dropped) or merge ($merge=true). */
    public function update(string $resource, string $id, array $data, int $baseVersion, string $by, bool $merge): array
    {
        return NamedLock::run('akademi', 'akademi_save', function () use ($resource, $id, $data, $baseVersion, $by, $merge) {
            $r = self::resource($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            $next = $merge ? array_replace($cur['row'], $data) : $data;
            $next['id'] = $id;
            if (! empty($r['def']['created']) && ! isset($next['createdAt']) && isset($cur['row']['createdAt'])) {
                $next['createdAt'] = $cur['row']['createdAt'];
            }
            unset($next['baseUpdatedAt']);
            if ($cur['version'] !== $baseVersion) {
                if (RowSync::sidik($cur['row']) === RowSync::sidik($next)) {
                    return $cur + ['unchanged' => true];
                }
                throw new RecordConflict('conflict', $cur['row']);
            }
            $stamp = max(self::nowMs(), $cur['version'] + 1);
            $next['updatedAt'] = $stamp;
            $this->writeRow($r['def'], $next, $stamp);
            $this->log($r, 'ubah', $id, $next, $by);

            $found = $this->find($resource, $id);

            return ['row' => $found['row'], 'version' => $found['version']];
        });
    }

    public function delete(string $resource, string $id, int $baseVersion, string $by): void
    {
        NamedLock::run('akademi', 'akademi_save', function () use ($resource, $id, $baseVersion, $by) {
            $r = self::resource($resource);
            $cur = $this->find($resource, $id);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            if ($cur['version'] !== $baseVersion) {
                throw new RecordConflict('conflict', $cur['row']);
            }
            $idCol = AkademiSchema::idCol();
            $this->db()->delete("DELETE FROM `{$r['def']['table']}` WHERE `$idCol` = ?", [$id]);
            $this->log($r, 'hapus', $id, $cur['row'], $by);
        });
    }

    /** Stamp is already set on $row; same INSERT...ON DUPLICATE shape as saveAll. Caller holds the lock. */
    private function writeRow(array $def, array $row, int $stamp): void
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
        $args = [(string) $row['id']];
        foreach ($cols as $col => [$field, $type]) {
            $args[] = RowSync::ambil($row, $field, $type);
        }
        $args[] = $stamp;
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
        $db->statement(
            "INSERT INTO `{$def['table']}` (`".implode('`,`', $names).'`) VALUES ('
            .implode(',', array_fill(0, count($names), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', $upd),
            $args,
        );
    }

    private function log(array $r, string $verb, string $id, array $row, string $by): void
    {
        $name = $row['title'] ?? $row['name'] ?? $id;
        $now = self::nowMs();
        $entry = ['ts' => $now, 'userId' => $by, 'action' => $verb.'_'.$r['verb'], 'detail' => is_scalar($name) ? (string) $name : $id];
        $t = AkademiSchema::table('activity');
        if (AkademiSchema::onCore()) {
            $this->db()->insert("INSERT IGNORE INTO `$t` (id, legacy_id, ts, user_id, action, data, version) VALUES (?,?,?,?,?,?,1)", [
                strtolower((string) Str::ulid()), AkademiState::activityId($entry), $now, $by, $entry['action'], RowSync::enc($entry),
            ]);
        } else {
            $this->db()->insert('INSERT IGNORE INTO activity (id, ts, user_id, action, data) VALUES (?,?,?,?,?)', [
                AkademiState::activityId($entry), $now, $by, $entry['action'], RowSync::enc($entry),
            ]);
        }
    }

    // ───────────────────────────── progress (composite keys) ──

    /**
     * @return array{rows:array,total:int} each row: {userId, materialId, version, entry}
     */
    public function progressList(?string $user, ?string $material): array
    {
        $where = [];
        $args = [];
        if ($user !== null && $user !== '') {
            $where[] = 'user_id = ?';
            $args[] = $user;
        }
        if ($material !== null && $material !== '') {
            $where[] = 'material_id = ?';
            $args[] = $material;
        }
        $t = AkademiSchema::table('progress');
        $rows = [];
        foreach ($this->db()->select("SELECT user_id, material_id, updated_at, data FROM `$t`"
            .($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY user_id, material_id', $args) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $rows[] = ['userId' => $r->user_id, 'materialId' => $r->material_id,
                    'version' => (int) $r->updated_at, 'entry' => $d];
            }
        }

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** @return array{userId:string,materialId:string,version:int,entry:array}|null */
    public function progressFind(string $user, string $material): ?array
    {
        $t = AkademiSchema::table('progress');
        $r = $this->db()->selectOne("SELECT user_id, material_id, updated_at, data FROM `$t` WHERE user_id = ? AND material_id = ?", [$user, $material]);
        if (! $r) {
            return null;
        }
        $d = json_decode((string) $r->data, true);

        return is_array($d) ? ['userId' => $r->user_id, 'materialId' => $r->material_id,
            'version' => (int) $r->updated_at, 'entry' => $d] : null;
    }

    /**
     * Replace one progress cell (the entry object, e.g. {status:'done'} or
     * quiz {attempts,score,passed,lastAt}). Version required when the cell
     * exists; omitted only to create it.
     */
    public function progressPut(string $user, string $material, array $entry, ?int $baseVersion, string $by): array
    {
        return NamedLock::run('akademi', 'akademi_save', function () use ($user, $material, $entry, $baseVersion) {
            $cur = $this->progressFind($user, $material);
            unset($entry['baseUpdatedAt']);
            if ($cur) {
                if ($baseVersion === null) {
                    throw new RecordConflict('conflict', $cur['entry']);
                }
                if ($cur['version'] !== $baseVersion) {
                    if (RowSync::sidik($cur['entry']) === RowSync::sidik($entry)) {
                        return $cur + ['unchanged' => true];
                    }
                    throw new RecordConflict('conflict', $cur['entry']);
                }
                $stamp = max(self::nowMs(), $cur['version'] + 1);
            } else {
                $stamp = self::nowMs();
            }
            $entry['updatedAt'] = $stamp;
            app(AkademiState::class)->saveProgress($this->db(), [$user => [$material => $entry]]);

            return $this->progressFind($user, $material);
        });
    }

    public function progressDelete(string $user, string $material, int $baseVersion): void
    {
        NamedLock::run('akademi', 'akademi_save', function () use ($user, $material, $baseVersion) {
            $cur = $this->progressFind($user, $material);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            if ($cur['version'] !== $baseVersion) {
                throw new RecordConflict('conflict', $cur['entry']);
            }
            $t = AkademiSchema::table('progress');
            $this->db()->delete("DELETE FROM `$t` WHERE user_id = ? AND material_id = ?", [$user, $material]);
        });
    }

    // ───────────────────────────── program progress (triple keys) ──

    /** @return array{rows:array,total:int} each row: {userId, programId, materialId, version, entry} */
    public function progProgList(?string $user, ?string $program, ?string $material): array
    {
        $where = [];
        $args = [];
        foreach (['user_id' => $user, 'program_id' => $program, 'material_id' => $material] as $col => $v) {
            if ($v !== null && $v !== '') {
                $where[] = "$col = ?";
                $args[] = $v;
            }
        }
        $t = AkademiSchema::table('progProg');
        $rows = [];
        foreach ($this->db()->select("SELECT user_id, program_id, material_id, updated_at, data FROM `$t`"
            .($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY user_id, program_id, material_id', $args) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $rows[] = ['userId' => $r->user_id, 'programId' => $r->program_id, 'materialId' => $r->material_id,
                    'version' => (int) $r->updated_at, 'entry' => $d];
            }
        }

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** @return array{userId:string,programId:string,materialId:string,version:int,entry:array}|null */
    public function progProgFind(string $user, string $program, string $material): ?array
    {
        $t = AkademiSchema::table('progProg');
        $r = $this->db()->selectOne("SELECT user_id, program_id, material_id, updated_at, data FROM `$t` WHERE user_id = ? AND program_id = ? AND material_id = ?",
            [$user, $program, $material]);
        if (! $r) {
            return null;
        }
        $d = json_decode((string) $r->data, true);

        return is_array($d) ? ['userId' => $r->user_id, 'programId' => $r->program_id, 'materialId' => $r->material_id,
            'version' => (int) $r->updated_at, 'entry' => $d] : null;
    }

    public function progProgPut(string $user, string $program, string $material, array $entry, ?int $baseVersion): array
    {
        return NamedLock::run('akademi', 'akademi_save', function () use ($user, $program, $material, $entry, $baseVersion) {
            $cur = $this->progProgFind($user, $program, $material);
            $curEntry = $cur ? $cur['entry'] : null;
            $stored = $cur ? $cur['version'] : null;
            unset($entry['baseUpdatedAt']);
            if ($cur) {
                if ($baseVersion === null || $stored !== $baseVersion) {
                    if (is_array($curEntry) && RowSync::sidik($curEntry) === RowSync::sidik($entry)) {
                        return ['userId' => $user, 'programId' => $program, 'materialId' => $material,
                            'version' => $stored, 'entry' => $curEntry, 'unchanged' => true];
                    }
                    throw new RecordConflict('conflict', is_array($curEntry) ? $curEntry : null);
                }
                $stamp = max(self::nowMs(), $stored + 1);
            } else {
                $stamp = self::nowMs();
            }
            $entry['updatedAt'] = $stamp;
            app(AkademiState::class)->saveProgProg($this->db(), [$user => [$program => [$material => $entry]]]);
            $t = AkademiSchema::table('progProg');
            $row = $this->db()->selectOne("SELECT updated_at, data FROM `$t` WHERE user_id = ? AND program_id = ? AND material_id = ?",
                [$user, $program, $material]);

            return ['userId' => $user, 'programId' => $program, 'materialId' => $material,
                'version' => (int) $row->updated_at, 'entry' => json_decode((string) $row->data, true)];
        });
    }

    public function progProgDelete(string $user, string $program, string $material, int $baseVersion): void
    {
        NamedLock::run('akademi', 'akademi_save', function () use ($user, $program, $material, $baseVersion) {
            $t = AkademiSchema::table('progProg');
            $cur = $this->db()->selectOne("SELECT updated_at, data FROM `$t` WHERE user_id = ? AND program_id = ? AND material_id = ?",
                [$user, $program, $material]);
            if (! $cur) {
                throw new RuntimeException('not_found');
            }
            if ((int) $cur->updated_at !== $baseVersion) {
                throw new RecordConflict('conflict', json_decode((string) $cur->data, true));
            }
            $this->db()->delete("DELETE FROM `$t` WHERE user_id = ? AND program_id = ? AND material_id = ?", [$user, $program, $material]);
        });
    }

    // ───────────────────────────── activity (audit trail) ──

    /** @return array{rows:array,total:int} newest first */
    public function activity(?string $user, ?string $action, int $limit): array
    {
        $where = [];
        $args = [];
        if ($user !== null && $user !== '') {
            $where[] = 'user_id = ?';
            $args[] = $user;
        }
        if ($action !== null && $action !== '') {
            $where[] = 'action = ?';
            $args[] = $action;
        }
        $t = AkademiSchema::table('activity');
        $idCol = AkademiSchema::idCol();
        $rows = array_values(array_filter(array_map(fn ($x) => json_decode((string) $x->data, true),
            $this->db()->select("SELECT data FROM `$t`".($where ? ' WHERE '.implode(' AND ', $where) : '')
                ." ORDER BY ts DESC, `$idCol` DESC LIMIT ".min(max($limit, 1), 5000), $args)), 'is_array'));

        return ['rows' => $rows, 'total' => count($rows)];
    }

    public function logActivity(array $entry, string $by): array
    {
        return NamedLock::run('akademi', 'akademi_save', function () use ($entry, $by) {
            $now = self::nowMs();
            $row = ['ts' => $now, 'userId' => $by, 'action' => $entry['action'] ?? 'catatan', 'detail' => $entry['detail'] ?? ''];
            $t = AkademiSchema::table('activity');
            $idCol = AkademiSchema::idCol();
            if (AkademiSchema::onCore()) {
                $this->db()->insert("INSERT IGNORE INTO `$t` (id, legacy_id, ts, user_id, action, data, version) VALUES (?,?,?,?,?,?,1)", [
                    strtolower((string) Str::ulid()), AkademiState::activityId($row), $now, $by, $row['action'], RowSync::enc($row),
                ]);
            } else {
                $this->db()->insert('INSERT IGNORE INTO activity (id, ts, user_id, action, data) VALUES (?,?,?,?,?)', [
                    AkademiState::activityId($row), $now, $by, $row['action'], RowSync::enc($row),
                ]);
            }
            $this->db()->statement("DELETE FROM `$t` WHERE `$idCol` NOT IN
                (SELECT `$idCol` FROM (SELECT `$idCol` FROM `$t` ORDER BY ts DESC, `$idCol` DESC LIMIT 5000) t)");

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
        $t = AkademiSchema::table('settings');
        $row = $this->db()->selectOne("SELECT v FROM `$t` WHERE k = ?", [self::documentKey($doc)]);
        $raw = $row ? (string) $row->v : 'null';

        return ['value' => json_decode($raw, true), 'version' => sha1($raw)];
    }

    public function putDocument(string $doc, mixed $value, string $baseVersion): array
    {
        return NamedLock::run('akademi', 'akademi_save', function () use ($doc, $value, $baseVersion) {
            $cur = $this->document($doc);
            if ($cur['version'] !== $baseVersion) {
                throw new RecordConflict('conflict', ['value' => $cur['value'], 'version' => $cur['version']]);
            }
            RowSync::putSetting($this->db(), self::documentKey($doc), $value,
                AkademiSchema::table('settings'), AkademiSchema::onCore());

            return $this->document($doc);
        });
    }
}
