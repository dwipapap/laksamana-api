<?php

namespace App\Modules\Akademi\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * The whole akademi state S — port of baca_state() and save_all().
 *
 * Akademi is the same older variant as konten, so the same quirks are kept
 * exactly (see docs/modules/konten.md and docs/modules/akademi.md):
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
 * Two shapes are akademi-only and handled here:
 *
 *  - `progress` / `progProg` are NESTED MAPS in the app
 *    (progress[userId][materialId], progProg[userId][programId][materialId])
 *    but ROWS with composite keys in the DB. read() rebuilds the maps so the
 *    frontend never knows; saveAll() splits them back into rows. An empty map
 *    never deletes anything (learning progress is too expensive to lose to
 *    one accidentally empty payload).
 *  - `activity` rows carry no id in the app, so the server derives one from
 *    a fingerprint of the content (self::activityId, port of id_activity()).
 *    With INSERT IGNORE a client may resend the whole list without doubling
 *    rows. Append-only, trimmed to the newest 5000.
 *
 * saveAll runs under GET_LOCK('<db>:akademi_save'), in one transaction, with
 * the receipt GC after commit (deleting files cannot be rolled back).
 */
class AkademiState
{
    public function __construct(private readonly AkademiFiles $files) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('akademi');
    }

    /** Exactly the object S the app uses. */
    public function read(): array
    {
        $db = $this->db();
        $out = [];

        foreach (AkademiSchema::collections() as $name => $c) {
            $order = ! empty($c['created']) ? 'created_at DESC, id DESC' : 'id ASC';
            $rows = [];
            foreach ($db->select("SELECT data FROM `{$c['table']}` ORDER BY $order") as $row) {
                $r = json_decode((string) $row->data, true);
                if (is_array($r)) {
                    $rows[] = $r;
                }
            }
            $out[$name] = $rows;
        }

        // activity: append-only, newest first (capped so the payload stays small)
        $act = [];
        foreach ($db->select('SELECT data FROM activity ORDER BY ts DESC, id DESC LIMIT 1000') as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                $act[] = $r;
            }
        }
        $out['activity'] = $act;

        // progress & progProg: ROWS in the DB, NESTED MAPS in the app.
        // Rebuilt here so the frontend never knows the difference.
        $pr = [];
        foreach ($db->select('SELECT user_id, material_id, data FROM progress') as $row) {
            $r = json_decode((string) $row->data, true);
            if (! is_array($r)) {
                continue;
            }
            $pr[(string) $row->user_id][(string) $row->material_id] = $r;
        }
        $out['progress'] = $pr !== [] ? $pr : new stdClass;

        $pp = [];
        foreach ($db->select('SELECT user_id, program_id, material_id, data FROM prog_prog') as $row) {
            $r = json_decode((string) $row->data, true);
            if (! is_array($r)) {
                continue;
            }
            $pp[(string) $row->user_id][(string) $row->program_id][(string) $row->material_id] = $r;
        }
        $out['progProg'] = $pp !== [] ? $pp : new stdClass;

        // settings + keys unknown to the backend (stored with an 'extra:' prefix)
        foreach ($db->select('SELECT k, v FROM settings') as $row) {
            $v = json_decode((string) $row->v, true);
            if (str_starts_with((string) $row->k, 'extra:')) {
                $out[substr((string) $row->k, 6)] = $v;
            } else {
                $out[(string) $row->k] = $v;
            }
        }
        // Safety net: the app expects these keys to always exist.
        if (! isset($out['settings']) || ! is_array($out['settings'])) {
            $out['settings'] = new stdClass;
        }
        if (! isset($out['version'])) {
            $out['version'] = 2;
        }

        return $out;
    }

    public function saveAll(mixed $state): array
    {
        try {
            return NamedLock::run('akademi', 'akademi_save', fn () => $this->saveAllLocked($state));
        } catch (RuntimeException $e) {
            // Same lock, same serialisation as the old backend — but its
            // busy message is part of the compat contract.
            if ($e->getMessage() === 'server sibuk, coba lagi') {
                throw new RuntimeException('Server sedang sibuk menyimpan, coba lagi sebentar.');
            }
            throw $e;
        }
    }

    /** saveAll body; caller must hold the akademi_save lock. */
    public function saveAllLocked(mixed $state): array
    {
        if (! is_array($state)) {
            throw new RuntimeException('Payload data kosong/invalid');
        }
        $db = $this->db();
        $hitung = [];
        $bentrok = [];

        $db->transaction(function () use ($db, $state, &$hitung, &$bentrok) {
            $known = ['activity', 'progress', 'progProg', '_rev'];

            foreach (AkademiSchema::collections() as $name => $c) {
                $known[] = $name;
                if (! array_key_exists($name, $state)) {
                    continue; // not sent -> skip
                }
                $rows = is_array($state[$name]) ? $state[$name] : [];
                $hitung[$name] = $this->upsertCollection($db, $c, $rows, $bentrok, $name);
            }

            // ---- activity: append-only (the trail is never overwritten/deleted) ----
            // App rows carry NO id (only ts+userId+action+detail), so the id is
            // derived from a fingerprint of the content. With INSERT IGNORE a
            // resend of the same list never doubles rows.
            if (isset($state['activity']) && is_array($state['activity'])) {
                $n = 0;
                foreach ($state['activity'] as $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    $db->insert('INSERT IGNORE INTO activity (id, ts, user_id, action, data) VALUES (?,?,?,?,?)', [
                        self::activityId($a),
                        RowSync::ms($a['ts'] ?? 0),
                        RowSync::ambil($a, 'userId', 'str'),
                        RowSync::ambil($a, 'action', 'str'),
                        RowSync::enc($a),
                    ]);
                    $n++;
                }
                $hitung['activity'] = $n;
                // keep it from growing without bound
                $db->statement('DELETE FROM activity WHERE id NOT IN
                    (SELECT id FROM (SELECT id FROM activity ORDER BY ts DESC, id DESC LIMIT 5000) t)');
            }

            // ---- progress: nested map -> rows ----
            if (isset($state['progress']) && is_array($state['progress'])) {
                $hitung['progress'] = $this->saveProgress($db, $state['progress']);
            }
            // ---- progProg: 3-level map -> rows ----
            if (isset($state['progProg']) && is_array($state['progProg'])) {
                $hitung['progProg'] = $this->saveProgProg($db, $state['progProg']);
            }

            // ---- settings & non-list keys ----
            foreach (AkademiSchema::scalarKeys() as $k) {
                $known[] = $k;
                if (array_key_exists($k, $state)) {
                    RowSync::putSetting($db, $k, $state[$k]);
                }
            }

            // ---- top-level keys the backend does not know yet ----
            // Stored as-is (with an 'extra:' prefix) so the app may grow new
            // sections without their data silently disappearing here.
            foreach ($state as $k => $v) {
                if (! in_array($k, $known, true)) {
                    RowSync::putSetting($db, 'extra:'.$k, $v);
                }
            }
        });

        // Clean orphan files AFTER commit: deleting files cannot be rolled
        // back, so a failed transaction must never have deleted any. Runs
        // inside the write lock (the caller holds akademi_save).
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
            foreach ($db->select("SELECT id, updated_at FROM `$table` WHERE id IN ($ph)", $chunk) as $row) {
                $verServer[$row->id] = (int) $row->updated_at;
            }
        }

        $names = array_merge(['id'], array_keys($cols), ['updated_at']);
        if ($hasCreated) {
            $names[] = 'created_at';
        }
        $names[] = 'data';

        // created_at is deliberately never overwritten: a row's birth stays.
        $upd = [];
        foreach (array_merge(array_keys($cols), ['data']) as $n) {
            $upd[] = "`$n` = IF(VALUES(updated_at) >= updated_at, VALUES(`$n`), `$n`)";
        }
        $upd[] = '`updated_at` = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)';
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
            // NOTE: the bumped stamp goes into the `updated_at` column ONLY —
            // `data.updatedAt` keeps the client-sent value.
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
            $db->statement($sql, $args);
        }

        // LEGACY, kept exactly: no _sejak bound — every row missing from the
        // payload is deleted, including rows created after the client loaded.
        // An empty payload never empties a table (guard against an
        // accidentally empty state, e.g. the app failed to load then saved).
        RowSync::deleteMissing($db, $table, 'id', $ids, 'notIn');

        return count($ids);
    }

    /**
     * Activity rows carry no id in the app. The id is a fingerprint of the
     * content (ts + user + action + detail) so resending the same list never
     * doubles rows — the trail must stay accurate, not bloated.
     */
    public static function activityId(array $a): string
    {
        $kunci = ($a['ts'] ?? '').'|'.($a['userId'] ?? '').'|'.($a['action'] ?? '').'|'.($a['detail'] ?? '');

        return 'ac_'.substr(sha1($kunci), 0, 24);
    }

    /**
     * progress[userId][materialId] = {done,score,at,...} -> 1 row per pair.
     * Split this way so "who passed which quiz" is answerable in SQL.
     */
    public function saveProgress(ConnectionInterface $db, array $map): int
    {
        $n = 0;
        $pairs = [];
        foreach ($map as $uid => $mats) {
            if (! is_array($mats)) {
                continue;
            }
            foreach ($mats as $mid => $r) {
                if (! is_array($r)) {
                    continue;
                }
                $db->statement('INSERT INTO progress (user_id, material_id, done, score, at_ms, updated_at, data)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      done       = IF(VALUES(updated_at) >= updated_at, VALUES(done),       done),
                      score      = IF(VALUES(updated_at) >= updated_at, VALUES(score),      score),
                      at_ms      = IF(VALUES(updated_at) >= updated_at, VALUES(at_ms),      at_ms),
                      data       = IF(VALUES(updated_at) >= updated_at, VALUES(data),       data),
                      updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)', [
                    (string) $uid, (string) $mid,
                    empty($r['done']) ? 0 : 1,
                    isset($r['score']) ? intval($r['score']) : null,
                    RowSync::ms($r['at'] ?? 0),
                    RowSync::ms($r['updatedAt'] ?? ($r['at'] ?? 0)),
                    RowSync::enc($r),
                ]);
                $pairs[] = [(string) $uid, (string) $mid];
                $n++;
            }
        }
        $this->deleteMissingProgress($db, 'progress', ['user_id', 'material_id'], $pairs);

        return $n;
    }

    /** progProg[userId][programId][materialId] -> 1 row per triple. */
    public function saveProgProg(ConnectionInterface $db, array $map): int
    {
        $n = 0;
        $triples = [];
        foreach ($map as $uid => $progs) {
            if (! is_array($progs)) {
                continue;
            }
            foreach ($progs as $pid => $mats) {
                if (! is_array($mats)) {
                    continue;
                }
                foreach ($mats as $mid => $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $db->statement('INSERT INTO prog_prog (user_id, program_id, material_id, done, score, at_ms, updated_at, data)
                        VALUES (?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                          done       = IF(VALUES(updated_at) >= updated_at, VALUES(done),       done),
                          score      = IF(VALUES(updated_at) >= updated_at, VALUES(score),      score),
                          at_ms      = IF(VALUES(updated_at) >= updated_at, VALUES(at_ms),      at_ms),
                          data       = IF(VALUES(updated_at) >= updated_at, VALUES(data),       data),
                          updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)', [
                        (string) $uid, (string) $pid, (string) $mid,
                        empty($r['done']) ? 0 : 1,
                        isset($r['score']) ? intval($r['score']) : null,
                        RowSync::ms($r['at'] ?? 0),
                        RowSync::ms($r['updatedAt'] ?? ($r['at'] ?? 0)),
                        RowSync::enc($r),
                    ]);
                    $triples[] = [(string) $uid, (string) $pid, (string) $mid];
                    $n++;
                }
            }
        }
        $this->deleteMissingProgress($db, 'prog_prog', ['user_id', 'program_id', 'material_id'], $triples);

        return $n;
    }

    /**
     * Delete progress rows no longer in the payload. SAFEGUARD, same as the
     * other collections: an EMPTY map never empties the table — learning
     * progress is too expensive to lose to one accidentally empty payload.
     */
    private function deleteMissingProgress(ConnectionInterface $db, string $table, array $cols, array $rows): void
    {
        if (count($rows) === 0) {
            return;
        }
        $unit = '('.implode(',', array_fill(0, count($cols), '?')).')';
        $place = implode(',', array_fill(0, count($rows), $unit));
        $args = [];
        foreach ($rows as $b) {
            foreach ($b as $v) {
                $args[] = $v;
            }
        }
        $db->statement('DELETE FROM `'.$table.'` WHERE ('.implode(',', $cols).') NOT IN ('.$place.')', $args);
    }
}
