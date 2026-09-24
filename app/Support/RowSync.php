<?php

namespace App\Support;

use DateTime;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * RowSync — the "rows of indexed columns + `data` JSON" persistence engine
 * shared by the blob-sync modules (marketing, konten, akademi, bd, event, hr,
 * hlife). Faithful port of marketing-mysql's upsert_collection /
 * upsert_settings_collection / hapus_yang_hilang, with options so the older
 * variants in other modules can be expressed with the same code.
 *
 * Rows are PHP ASSOC ARRAYS (json_decode(..., true)) — that is what the legacy
 * code used, so the legacy compat surface round-trips identically. (Legacy
 * getAll therefore returns an empty object as `[]`; keep it that way there.)
 *
 * ─── Collection definition ($def) ────────────────────────────────────────
 *   'table'   => 'events'
 *   'id'      => 'id'                        id column (and app field)
 *   'created' => true                        has created_at (from data.createdAt, never overwritten)
 *   'cols'    => ['nama' => ['nama','str'], 'tanggal' => ['tanggal','date'], …]
 *                column => [app field, type]  types: str|int|bool|date|datetime|ms
 *
 * ─── Options ($opt) ───────────────────────────────────────────────────────
 *   'conflict'      bool  rows carrying `baseUpdatedAt` older than the server
 *                         version are refused and reported in $bentrok (marketing, konten, akademi)
 *   'sidik'         bool  …unless the content is identical (key-sorted deep JSON
 *                         without updatedAt) — then no conflict, server version in $versi (marketing)
 *   'capBump'       bool  a row that passed the conflict check but whose stamp
 *                         <= server gets server+1, reported in $versi (marketing, akademi)
 *   'skipUnchanged' bool  rows not marked edited with the same stamp as stored
 *                         are not rewritten (marketing)
 *   'nameKeys'      list  fields used as the human name in $bentrok (default nama, name)
 *
 * ─── Delete strategies (deleteMissing) ───────────────────────────────────
 *   'sejak'  : DELETE rows NOT IN ids AND updated_at <= $param; $param<=0 or no ids => nothing (marketing)
 *   'notIn'  : DELETE rows NOT IN ids (no ids => nothing unless $allowEmpty)            (akademi, hr, hlife…)
 *   'maxUpd' : like sejak with $param = max updatedAt of the payload                    (event)
 *   'none'   : never delete
 */
final class RowSync
{
    public const JSON_STORE = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    // ───────────────────────────── value helpers (legacy ambil() & co) ──

    public static function tanggal(mixed $d): ?string
    {
        $d = trim((string) (is_scalar($d) ? $d : ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /**
     * Zoned instants (Z / ±hh:mm) are converted to WIB; zone-less wall-clock
     * strings are kept as-is (the app displays them unparsed).
     */
    public static function datetime(mixed $v): ?string
    {
        $v = trim((string) (is_scalar($v) ? $v : ''));
        if ($v === '') {
            return null;
        }
        if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $v)) {
            $ts = strtotime($v);
            if ($ts === false) {
                return null;
            }
            $d = new DateTime('@'.$ts);
            $d->setTimezone(new DateTimeZone('Asia/Jakarta'));

            return $d->format('Y-m-d H:i:s');
        }
        $v = preg_replace('/\.\d+$/', '', str_replace('T', ' ', $v));
        try {
            return (new DateTime($v, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public static function ms(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return (int) $v;
        }
        if (is_string($v) && $v !== '') {
            if (ctype_digit($v)) {
                return (int) $v;
            }
            $ts = strtotime($v);
            if ($ts !== false) {
                return $ts * 1000;
            }
        }

        return 0;
    }

    public static function ambil(array $row, string $field, string $type): mixed
    {
        $v = $row[$field] ?? null;

        return match ($type) {
            'int' => intval($v),
            'bool' => empty($v) ? 0 : 1,
            'date' => self::tanggal($v),
            'datetime' => self::datetime($v),
            'ms' => self::ms($v),
            default => $v === null ? null : (is_array($v) ? json_encode($v) : (string) $v),
        };
    }

    public static function enc(mixed $v): string
    {
        return json_encode($v, self::JSON_STORE);
    }

    /** Key-sorted deep copy; numeric-indexed lists keep their order. */
    public static function urutDalam(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = self::urutDalam($x);
        }
        foreach (array_keys($out) as $k) {
            if (! is_int($k)) {
                ksort($out);
                break;
            }
        }

        return $out;
    }

    /** Content fingerprint of a row, ignoring stamps (legacy sidik_baris). */
    public static function sidik(mixed $r): string
    {
        if (! is_array($r)) {
            return '';
        }
        unset($r['updatedAt'], $r['baseUpdatedAt']);

        return json_encode(self::urutDalam($r));
    }

    public static function versiBaris(array $rows): int
    {
        $max = 0;
        foreach ($rows as $r) {
            if (is_array($r)) {
                $max = max($max, self::ms($r['updatedAt'] ?? 0));
            }
        }

        return $max;
    }

    /** Stamps come from the CLIENT; bumped only when a legit edit would lose the ordering guard. */
    public static function capTulis(int $ua, bool $passedConflict, ?int $verServer): int
    {
        return ($passedConflict && $verServer !== null && $ua <= $verServer) ? $verServer + 1 : $ua;
    }

    // ───────────────────────────── table collections ──

    /**
     * Upsert every row of one collection. Returns the list of ids present in
     * the payload (conflicting rows included, so deleteMissing never removes them).
     *
     * @param  array<int,array>  $bentrok  (by ref) refused rows
     * @param  array<string,int>  $versi  (by ref) '<name>:<id>' => stamp actually stored
     */
    public static function upsertCollection(ConnectionInterface $db, array $def, array $rows, string $name, array $opt, array &$bentrok, array &$versi): array
    {
        $table = $def['table'];
        $idCol = $def['id'] ?? 'id';
        $cols = $def['cols'] ?? [];
        $hasCreated = ! empty($def['created']);
        $conflict = ! empty($opt['conflict']);
        $useSidik = ! empty($opt['sidik']);
        $capBump = ! empty($opt['capBump']);
        $skipUnchanged = ! empty($opt['skipUnchanged']);

        $sendIds = [];
        foreach ($rows as $r) {
            if (is_array($r) && ! empty($r['id'])) {
                $sendIds[] = (string) $r['id'];
            }
        }
        $verServer = [];
        $dataServer = [];
        foreach (array_chunk($sendIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->select("SELECT `$idCol` AS id, updated_at, data FROM `$table` WHERE `$idCol` IN ($ph)", $chunk) as $row) {
                $verServer[(string) $row->id] = (int) $row->updated_at;
                $dataServer[(string) $row->id] = json_decode((string) $row->data, true);
            }
        }

        $names = array_merge([$idCol], array_keys($cols), ['updated_at']);
        if ($hasCreated) {
            $names[] = 'created_at';
        }
        $names[] = 'data';
        $upd = [];
        foreach (array_merge(array_keys($cols), ['data']) as $n) {
            $upd[] = "`$n` = IF(VALUES(updated_at) >= updated_at, VALUES(`$n`), `$n`)";
        }
        $upd[] = 'updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)';
        $sql = "INSERT INTO `$table` (`".implode('`,`', $names).'`) VALUES ('
            .implode(',', array_fill(0, count($names), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', $upd);

        $ids = [];
        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['id'])) {
                continue;
            }
            $id = (string) $r['id'];
            $ids[] = $id;

            $passed = false;
            if ($conflict && array_key_exists('baseUpdatedAt', $r)) {
                $base = self::ms($r['baseUpdatedAt']);
                if (isset($verServer[$id]) && $verServer[$id] > $base) {
                    if ($useSidik && isset($dataServer[$id]) && self::sidik($dataServer[$id]) === self::sidik($r)) {
                        $versi[$name.':'.$id] = $verServer[$id];

                        continue; // nothing changed: not a conflict, nothing to write
                    }
                    $bentrok[] = [
                        'koleksi' => $name, 'id' => $id,
                        'nama' => self::rowName($r, $opt, $id),
                        'versiKamu' => $base, 'versiServer' => $verServer[$id],
                    ];

                    continue; // never overwrite someone else's newer work
                }
                $passed = true;
            }

            $simpan = $r;
            unset($simpan['baseUpdatedAt']);
            $uaSent = self::ms($simpan['updatedAt'] ?? 0);
            $ua = $capBump ? self::capTulis($uaSent, $passed, $verServer[$id] ?? null) : $uaSent;
            if ($ua !== $uaSent) {
                $versi[$name.':'.$id] = $ua;
            }
            if ($capBump) {
                $simpan['updatedAt'] = $ua;
            }
            if ($skipUnchanged && ! $passed && isset($verServer[$id]) && $ua === $verServer[$id]) {
                continue;
            }

            $args = [$id];
            foreach ($cols as $col => [$field, $type]) {
                $args[] = self::ambil($simpan, $field, $type);
            }
            $args[] = $ua;
            if ($hasCreated) {
                $args[] = self::ms($simpan['createdAt'] ?? 0);
            }
            $args[] = self::enc($simpan);
            $db->statement($sql, $args);
        }

        return $ids;
    }

    private static function rowName(array $r, array $opt, string $id): string
    {
        foreach ($opt['nameKeys'] ?? ['nama', 'name'] as $k) {
            if (isset($r[$k])) {
                return is_scalar($r[$k]) ? (string) $r[$k] : $id;
            }
        }

        return $id;
    }

    /** Remove rows missing from the payload according to $strategy (see class doc). */
    public static function deleteMissing(ConnectionInterface $db, string $table, string $idCol, array $ids, string $strategy, int $param = 0, bool $allowEmpty = false): int
    {
        if ($strategy === 'none') {
            return 0;
        }
        if (count($ids) === 0) {
            if ($strategy === 'notIn' && $allowEmpty) {
                return $db->delete("DELETE FROM `$table`");
            }

            return 0; // an empty payload never empties a table
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($strategy === 'notIn') {
            return $db->delete("DELETE FROM `$table` WHERE `$idCol` NOT IN ($ph)", array_values($ids));
        }
        // sejak / maxUpd: only rows the client could have known about
        if ($param <= 0) {
            return 0;
        }

        return $db->delete("DELETE FROM `$table` WHERE `$idCol` NOT IN ($ph) AND updated_at <= ?", [...array_values($ids), $param]);
    }

    // ───────────────────────────── collections stored inside `settings` ──

    public static function settingsRows(ConnectionInterface $db, string $name, string $prefix = 'extra:'): array
    {
        $v = $db->selectOne('SELECT v FROM settings WHERE k = ?', [$prefix.$name]);
        $d = $v ? json_decode((string) $v->v, true) : null;

        return is_array($d) ? $d : [];
    }

    public static function putSetting(ConnectionInterface $db, string $k, mixed $v): void
    {
        $db->statement('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, self::enc($v)]);
    }

    /**
     * Per-row merge of an id-keyed list stored as ONE JSON value in `settings`
     * (marketing designreqs/vip) — the same guards as table collections, done
     * in PHP. Deletion bounded by $sejak; empty payload never empties the list.
     * Returns the stored row count.
     */
    public static function upsertSettingsCollection(ConnectionInterface $db, string $name, array $rows, array $opt, array &$bentrok, array &$versi, int $sejak): int
    {
        $byId = [];
        foreach (self::settingsRows($db, $name) as $r) {
            if (is_array($r) && isset($r['id']) && $r['id'] !== '') {
                $byId[(string) $r['id']] = $r;
            }
        }
        $sent = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['id']) || $r['id'] === '') {
                continue;
            }
            $id = (string) $r['id'];
            $sent[$id] = 1;
            $verServer = isset($byId[$id]) ? self::ms($byId[$id]['updatedAt'] ?? 0) : null;

            $passed = false;
            if (array_key_exists('baseUpdatedAt', $r)) {
                $base = self::ms($r['baseUpdatedAt']);
                if ($verServer !== null && $verServer > $base) {
                    if (self::sidik($byId[$id]) === self::sidik($r)) {
                        $versi[$name.':'.$id] = $verServer;

                        continue;
                    }
                    $bentrok[] = [
                        'koleksi' => $name, 'id' => $id,
                        'nama' => isset($r['nama']) ? (string) $r['nama'] : (isset($r['judul']) ? (string) $r['judul'] : $id),
                        'versiKamu' => $base, 'versiServer' => $verServer,
                    ];

                    continue;
                }
                $passed = true;
            }
            $simpan = $r;
            unset($simpan['baseUpdatedAt']);
            $uaSent = self::ms($simpan['updatedAt'] ?? 0);
            $ua = self::capTulis($uaSent, $passed, $verServer);
            if ($ua !== $uaSent) {
                $versi[$name.':'.$id] = $ua;
            }
            if (! $passed && $verServer !== null && $ua <= $verServer) {
                continue; // older/equal reflection of a row the client did not edit
            }
            $simpan['updatedAt'] = $ua;
            $byId[$id] = $simpan;
        }

        $mayDelete = $sejak > 0 && count($sent) > 0;
        $out = [];
        foreach ($byId as $id => $r) {
            if (isset($sent[$id]) || ! $mayDelete || self::ms($r['updatedAt'] ?? 0) > $sejak) {
                $out[] = $r;
            }
        }
        self::putSetting($db, 'extra:'.$name, $out);

        return count($out);
    }
}
