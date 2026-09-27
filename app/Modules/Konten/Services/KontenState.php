<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * The whole konten state S — port of baca_state() and save_all().
 *
 * Konten is an OLDER variant of marketing's saveAll, so two of its quirks
 * are kept exactly (see docs/modules/konten.md):
 *
 *  - NO `_sejak` bound. hapus_yang_hilang deletes every row missing from the
 *    payload, including rows created after the client loaded. RowSync can
 *    express this as deleteMissing(..., 'notIn'), which is used.
 *  - NO `versi` map and NO identical-content escape. A stale baseUpdatedAt
 *    is always a conflict, even when the content is the same.
 *  - The cap bump (+1 above the server version for a legit edit whose stamp
 *    is not newer) applies to the `updated_at` COLUMN only; `data.updatedAt`
 *    keeps the client-sent value. RowSync::upsertCollection writes the bump
 *    back into `data`, so the per-collection loop below is a line-by-line
 *    port of upsert_collection() instead — reusing RowSync only for the
 *    value helpers (ambil/ms/enc), putSetting and deleteMissing.
 *
 * saveAll runs under GET_LOCK('<db>:konten_save'), in one transaction, with
 * the receipt GC after commit (deleting files cannot be rolled back).
 */
class KontenState
{
    public function __construct(private readonly KontenFiles $files) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('konten');
    }

    /** Exactly the object S the app uses. */
    public function read(): array
    {
        $db = $this->db();
        $out = [];

        foreach (KontenSchema::defs() as $name => $c) {
            $order = ! empty($c['created']) ? KontenSchema::createdOrder() : KontenSchema::idOrder();
            $rows = [];
            foreach ($db->select("SELECT data FROM `{$c['table']}` ORDER BY $order") as $row) {
                $r = json_decode((string) $row->data, true);
                if (is_array($r)) {
                    $rows[] = $r;
                }
            }
            $out[$name] = $rows;
        }

        // logs: append-only, newest first (capped so the payload stays small)
        $act = [];
        $logTable = KontenSchema::table('logs');
        $logId = KontenSchema::idCol();
        foreach ($db->select("SELECT data FROM `$logTable` ORDER BY at_ms DESC, `$logId` DESC LIMIT 1000") as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                $act[] = $r;
            }
        }
        $out['logs'] = $act;

        // settings + keys unknown to the backend (stored with an 'extra:' prefix)
        $setTable = KontenSchema::table('settings');
        foreach ($db->select("SELECT k, v FROM `$setTable`") as $row) {
            $v = json_decode((string) $row->v, true);
            if (str_starts_with((string) $row->k, 'extra:')) {
                $out[substr((string) $row->k, 6)] = $v;
            } else {
                $out[(string) $row->k] = $v;
            }
        }
        // Safety net: the app expects these keys to always exist ({} when empty).
        if (! isset($out['settings']) || ! is_array($out['settings'])) {
            $out['settings'] = new stdClass;
        }
        if (! isset($out['perms']) || ! is_array($out['perms'])) {
            $out['perms'] = new stdClass;
        }

        return $out;
    }

    public function saveAll(mixed $state): array
    {
        try {
            return NamedLock::run('konten', 'konten_save', fn () => $this->saveAllLocked($state));
        } catch (RuntimeException $e) {
            // Same lock, same serialisation as the old backend — but its
            // busy message is part of the compat contract.
            if ($e->getMessage() === 'server sibuk, coba lagi') {
                throw new RuntimeException('Server sedang sibuk menyimpan, coba lagi sebentar.');
            }
            throw $e;
        }
    }

    /** saveAll body; caller must hold the konten_save lock. */
    public function saveAllLocked(mixed $state): array
    {
        if (! is_array($state)) {
            throw new RuntimeException('Payload data kosong/invalid');
        }
        $db = $this->db();
        $hitung = [];
        $bentrok = [];

        $db->transaction(function () use ($db, $state, &$hitung, &$bentrok) {
            $known = ['logs', '_rev'];
            $defs = KontenSchema::defs();
            $idCol = KontenSchema::idCol();

            foreach ($defs as $name => $c) {
                $known[] = $name;
                if (! array_key_exists($name, $state)) {
                    continue; // not sent -> skip
                }
                $rows = is_array($state[$name]) ? $state[$name] : [];
                $hitung[$name] = $this->upsertCollection($db, $c, $rows, $bentrok, $name);
            }

            // ---- logs: append-only (the audit trail is never overwritten/deleted) ----
            if (isset($state['logs']) && is_array($state['logs'])) {
                $hitung['logs'] = $this->appendLogs($db, $state['logs']);
                $logTable = KontenSchema::table('logs');
                $db->statement("DELETE FROM `$logTable` WHERE `$idCol` NOT IN
                    (SELECT `$idCol` FROM (SELECT `$idCol` FROM `$logTable` ORDER BY at_ms DESC, `$idCol` DESC LIMIT 5000) t)");
            }

            // ---- settings & non-list keys ----
            foreach (KontenSchema::scalarKeys() as $k) {
                $known[] = $k;
                if (array_key_exists($k, $state)) {
                    RowSync::putSetting($db, $k, $state[$k], KontenSchema::table('settings'), KontenSchema::onCore());
                }
            }

            // ---- top-level keys the backend does not know yet ----
            // Stored as-is (with an 'extra:' prefix) so the app may grow new
            // sections without their data silently disappearing here. Note:
            // `_sejak` is one of them — konten never reads it back, it just
            // ends up stored. That is the legacy behaviour, kept exactly.
            foreach ($state as $k => $v) {
                if (! in_array($k, $known, true)) {
                    RowSync::putSetting($db, 'extra:'.$k, $v, KontenSchema::table('settings'), KontenSchema::onCore());
                }
            }
        });

        // Clean orphan files AFTER commit: deleting files cannot be rolled
        // back, so a failed transaction must never have deleted any. Runs
        // inside the write lock (the caller holds konten_save).
        $buang = 0;
        try {
            $buang = $this->files->gc();
        } catch (\Throwable) {
            // Failing to clean up is no reason to fail the save.
        }

        return [
            'saved' => true,
            'jumlah' => $hitung,
            'berkasDibuang' => $buang,
            // Rows REFUSED because someone else saved first. Empty = everything
            // went in. The app must tell the user when this is filled — staying
            // silent would let them believe their change was saved.
            'bentrok' => $bentrok,
            'backend' => 'laravel',
            'ts' => gmdate('c'),
        ];
    }

    /**
     * Port of upsert_collection(): per-row conflict guard via baseUpdatedAt,
     * ordering guard via updated_at (bumped to server+1 for a legit edit),
     * then the LEGACY unbounded delete (no _sejak). Returns the payload row
     * count — conflicting rows count as "present" so they are never deleted.
     */
    private function upsertCollection(ConnectionInterface $db, array $def, array $rows, array &$bentrok, string $name): int
    {
        $table = $def['table'];
        $idCol = $def['id'] ?? 'id';
        $useUlid = ! empty($def['ulid']);
        $versioned = ! empty($def['versioned']);
        $cols = $def['cols'];
        $hasCreated = ! empty($def['created']);

        // Server versions for the sent rows (one query, not one per row).
        $sendIds = [];
        foreach ($rows as $r) {
            if (is_array($r) && ! empty($r['id'])) {
                $sendIds[] = (string) $r['id'];
            }
        }
        $verServer = [];
        foreach (array_chunk($sendIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->select("SELECT `$idCol` AS id, updated_at FROM `$table` WHERE `$idCol` IN ($ph)", $chunk) as $row) {
                $verServer[$row->id] = (int) $row->updated_at;
            }
        }

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

        // created_at is deliberately never overwritten: a row's birth stays.
        $upd = [];
        foreach (array_merge(array_keys($cols), ['data']) as $n) {
            $upd[] = "`$n` = IF(VALUES(updated_at) >= updated_at, VALUES(`$n`), `$n`)";
        }
        $upd[] = '`updated_at` = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)';
        if ($versioned) {
            $upd[] = '`version` = `version` + 1';
        }
        $sql = "INSERT INTO `$table` (`".implode('`,`', $names).'`) VALUES ('
            .implode(',', array_fill(0, count($names), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', $upd);

        $ids = [];
        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['id'])) {
                continue;
            }
            $id = (string) $r['id'];
            $ids[] = $id; // still counts as "present" on conflict, so it is not deleted

            // --- conflict guard: only for rows the client actually edited ---
            $lolosBentrok = false;
            if (array_key_exists('baseUpdatedAt', $r)) {
                $base = RowSync::ms($r['baseUpdatedAt']);
                if (isset($verServer[$id]) && $verServer[$id] > $base) {
                    $bentrok[] = [
                        'koleksi' => $name,
                        'id' => $id,
                        'nama' => isset($r['nama']) ? (string) $r['nama'] : (isset($r['name']) ? (string) $r['name'] : $id),
                        'versiKamu' => $base,
                        'versiServer' => $verServer[$id],
                    ];

                    continue; // NEVER overwrite someone else's newer work
                }
                // Passed the conflict check = nobody overtook us. This row MUST go in.
                $lolosBentrok = true;
            }

            // baseUpdatedAt is send metadata — never stored in `data`.
            $simpan = $r;
            unset($simpan['baseUpdatedAt']);

            $ua = RowSync::ms($simpan['updatedAt'] ?? 0);
            // A row that passed the check must not be blocked by the ordering
            // guard: raise the stamp at least 1 above the server version.
            // NOTE: unlike the newer marketing variant, the bumped stamp goes
            // into the `updated_at` column ONLY — `data.updatedAt` keeps the
            // client-sent value.
            if ($lolosBentrok && isset($verServer[$id]) && $ua <= $verServer[$id]) {
                $ua = $verServer[$id] + 1;
            }

            $args = [$id];
            foreach ($cols as $col => [$field, $type]) {
                $args[] = RowSync::ambil($simpan, $field, $type);
            }
            $args[] = $ua;
            if ($hasCreated) {
                $args[] = RowSync::ms($simpan['createdAt'] ?? 0);
            }
            $args[] = RowSync::enc($simpan);
            if ($versioned) {
                $args[] = 1;
            }
            if ($useUlid) {
                array_unshift($args, strtolower((string) Str::ulid()));
            }
            $db->statement($sql, $args);
        }

        // LEGACY BUG, reproduced exactly: no _sejak bound — every row missing
        // from the payload is deleted, including rows created after the client
        // loaded. An empty payload never empties a table (guard against an
        // accidentally empty state, e.g. the app failed to load then saved).
        RowSync::deleteMissing($db, $table, $idCol, $ids, 'notIn');

        return count($ids);
    }

    /**
     * Append-only insert of log rows; ids already stored are ignored.
     * Returns the payload row count (duplicates included, like legacy).
     * Caller trims to the newest 5000. Every field mapping follows the live
     * data: ref_id <= "target", by_user <= "by", at_ms <= "at".
     */
    public function appendLogs(ConnectionInterface $db, array $logs): int
    {
        $table = KontenSchema::table('logs');
        $idCol = KontenSchema::idCol();
        $onCore = KontenSchema::onCore();
        $n = 0;
        foreach ($logs as $a) {
            if (! is_array($a) || empty($a['id'])) {
                continue;
            }
            if ($onCore) {
                $db->insert("INSERT IGNORE INTO `$table` (id, `$idCol`, ref_id, action, by_user, at_ms, data, version) VALUES (?,?,?,?,?,?,?,1)", [
                    strtolower((string) Str::ulid()),
                    (string) $a['id'],
                    RowSync::ambil($a, 'target', 'str'),
                    RowSync::ambil($a, 'action', 'str'),
                    RowSync::ambil($a, 'by', 'str'),
                    RowSync::ambil($a, 'at', 'ms'),
                    RowSync::enc($a),
                ]);
            } else {
                $db->insert('INSERT IGNORE INTO logs (id, ref_id, action, by_user, at_ms, data) VALUES (?,?,?,?,?,?)', [
                    (string) $a['id'],
                    RowSync::ambil($a, 'target', 'str'),
                    RowSync::ambil($a, 'action', 'str'),
                    RowSync::ambil($a, 'by', 'str'),
                    RowSync::ambil($a, 'at', 'ms'),
                    RowSync::enc($a),
                ]);
            }
            $n++;
        }

        return $n;
    }
}
