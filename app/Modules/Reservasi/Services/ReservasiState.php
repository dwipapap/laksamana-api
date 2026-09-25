<?php

namespace App\Modules\Reservasi\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Reservasi (also the Service Excellent `master` blob) — port of
 * reservasi-mysql/lib_reservasi_mysql.php.
 *
 * Rows of `reservations` (indexed columns + the full `data` JSON, the source
 * of truth), an append-only `audit` trimmed to 500, and `settings`:
 * `master` (one JSON blob shared with Service Excellent) and `_ver`, the
 * GLOBAL version every saveAll must name (baseVer) — checked under
 * SELECT … FOR UPDATE, so a stale client never deletes someone's new row
 * through the delete-missing reconciliation.
 *
 * Photos never live in MySQL: inline `data:` URIs are moved to
 * <RESERVASI_DATA_DIR>/files/<key>.txt and replaced with `@f:<key>`; files no
 * longer referenced by the saved state are garbage-collected. The folder and
 * the `.lock` flock file are shared with the old backends during cutover.
 *
 * JSON is handled as assoc arrays, like legacy.
 */
class ReservasiState
{
    public const FILE_TAG = '@f:';

    public function db(): ConnectionInterface
    {
        return Modules::db('reservasi');
    }

    private static function s(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    private static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ───────────────────────────── files on disk ──

    public static function dir(): string
    {
        $d = Modules::dataDir('reservasi');
        if (! $d) {
            throw new RuntimeException('RESERVASI_DATA_DIR belum disetel');
        }
        if (! is_dir($d) && ! @mkdir($d, 0775, true)) {
            throw new RuntimeException('DATA_DIR tidak bisa dibuat: '.$d);
        }

        return realpath($d) ?: $d;
    }

    private static function filesDir(): string
    {
        return self::dir().'/files';
    }

    public static function filePath(mixed $key): string
    {
        return self::filesDir().'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', self::s($key)).'.txt';
    }

    /**
     * Serialise writes with the old backend: flock on <DATA_DIR>/.lock (what it
     * uses) inside a NamedLock (what spans several app servers).
     */
    private function locked(\Closure $fn): mixed
    {
        return NamedLock::run('reservasi', 'reservasi_save', function () use ($fn) {
            if (! is_dir(self::filesDir())) {
                @mkdir(self::filesDir(), 0775, true);
            }
            $fh = fopen(self::dir().'/.lock', 'c');
            if ($fh) {
                flock($fh, LOCK_EX);
            }
            try {
                return $fn();
            } finally {
                if ($fh) {
                    flock($fh, LOCK_UN);
                    fclose($fh);
                }
            }
        });
    }

    public function getFile(mixed $key): array
    {
        if (! $key) {
            throw new RuntimeException('key kosong');
        }
        $p = self::filePath($key);
        $data = is_file($p) ? file_get_contents($p) : '';

        return ['key' => $key, 'data' => $data === false ? '' : $data];
    }

    /** put_file (unlocked) — empty data deletes the file. */
    private function writeFile(mixed $payload): array
    {
        if (! $payload || ! is_array($payload) || empty($payload['key'])) {
            throw new RuntimeException('key kosong');
        }
        if (! is_dir(self::filesDir())) {
            @mkdir(self::filesDir(), 0775, true);
        }
        $key = $payload['key'];
        $data = isset($payload['data']) ? self::s($payload['data']) : '';
        $p = self::filePath($key);
        if ($data === '') {
            if (is_file($p)) {
                @unlink($p);
            }

            return ['key' => $key, 'len' => 0, 'deleted' => true];
        }
        if (@file_put_contents($p, $data) === false) {
            throw new RuntimeException('Gagal menulis foto (cek izin folder files)');
        }

        return ['key' => $key, 'len' => strlen($data)];
    }

    public function putFile(mixed $payload): array
    {
        return $this->locked(fn () => $this->writeFile($payload));
    }

    /** v1 file writes advance the same global version every other v1 write does. */
    public function putFileV1(mixed $payload): array
    {
        return $this->writeLocked(function () use ($payload) {
            $out = $this->writeFile($payload);
            $this->bumpVer();

            return $out;
        });
    }

    // ───────────────────────────── photo fields ──

    private static function isInline(mixed $v): bool
    {
        return is_string($v) && str_starts_with($v, 'data:');
    }

    private static function isRef(mixed $v): bool
    {
        return is_string($v) && str_starts_with($v, self::FILE_TAG);
    }

    /**
     * each_file_field — every photo field of the state with its file key:
     * reservation dpProofData (r:<id>:dp) / docReqData (r:<id>:doc), each DP's
     * proofData (p:<resId>:<dpId>), master reviews proofData (rv:) / proof2Data
     * (rv2: — not rv:, or the second photo would overwrite the first), feedbacks (fb:).
     *
     * @param  \Closure(array &$obj, string $field, string $key): void  $fn
     */
    public static function eachFileField(array &$state, \Closure $fn): void
    {
        if (! empty($state['reservations']) && is_array($state['reservations'])) {
            foreach ($state['reservations'] as &$r) {
                if (! is_array($r) || empty($r['id'])) {
                    continue;
                }
                $id = self::s($r['id']);
                $fn($r, 'dpProofData', 'r:'.$id.':dp');
                $fn($r, 'docReqData', 'r:'.$id.':doc');
                if (! empty($r['dps']) && is_array($r['dps'])) {
                    foreach ($r['dps'] as &$p) {
                        if (is_array($p) && ! empty($p['id'])) {
                            $fn($p, 'proofData', 'p:'.$id.':'.self::s($p['id']));
                        }
                    }
                    unset($p);
                }
            }
            unset($r);
        }
        if (! empty($state['master']) && is_array($state['master'])) {
            if (! empty($state['master']['reviews']) && is_array($state['master']['reviews'])) {
                foreach ($state['master']['reviews'] as &$rv) {
                    if (! is_array($rv) || empty($rv['id'])) {
                        continue;
                    }
                    $fn($rv, 'proofData', 'rv:'.self::s($rv['id']));
                    $fn($rv, 'proof2Data', 'rv2:'.self::s($rv['id']));
                }
                unset($rv);
            }
            if (! empty($state['master']['feedbacks']) && is_array($state['master']['feedbacks'])) {
                foreach ($state['master']['feedbacks'] as &$fb) {
                    if (is_array($fb) && ! empty($fb['id'])) {
                        $fn($fb, 'proofData', 'fb:'.self::s($fb['id']));
                    }
                }
                unset($fb);
            }
        }
    }

    /** Move inline photos to disk, leaving @f:<key>. Returns the number moved. */
    private function externalize(array &$state): int
    {
        $moved = 0;
        self::eachFileField($state, function (&$obj, $field, $key) use (&$moved) {
            if (! isset($obj[$field]) || ! self::isInline($obj[$field])) {
                return;
            }
            $this->writeFile(['key' => $key, 'data' => $obj[$field]]);
            $obj[$field] = self::FILE_TAG.$key;
            $moved++;
        });

        return $moved;
    }

    /** gc_files — delete .txt files no longer referenced by the saved state. */
    private function gcFiles(array &$state): int
    {
        if (! is_dir(self::filesDir())) {
            return 0;
        }
        $hidup = [];
        self::eachFileField($state, function (&$obj, $field, $key) use (&$hidup) {
            if (empty($obj[$field])) {
                return;
            }
            if (self::isRef($obj[$field])) {
                $hidup[substr($obj[$field], strlen(self::FILE_TAG))] = true;
            }
            $hidup[$key] = true;
        });
        $keep = [];
        foreach (array_keys($hidup) as $k) {
            $keep[basename(self::filePath($k))] = true;
        }
        $n = 0;
        foreach (scandir(self::filesDir()) as $f) {
            if (str_ends_with($f, '.txt') && empty($keep[$f])) {
                @unlink(self::filesDir().'/'.$f);
                $n++;
            }
        }

        return $n;
    }

    // ───────────────────────────── state ──

    /** The master blob alone. v1 reads one section without loading the ~2.4 MB reservation list. */
    public function readMaster(bool $lock = false): ?array
    {
        $m = $this->db()->selectOne("SELECT v FROM settings WHERE k='master' LIMIT 1".($lock ? ' FOR UPDATE' : ''));
        if (! $m || ! isset($m->v)) {
            return null;
        }
        $d = json_decode((string) $m->v, true);

        return is_array($d) ? $d : null;
    }

    /** The newest 500 audit rows, without reading the reservation list. */
    public function readAudit(): array
    {
        $audit = [];
        foreach ($this->db()->select('SELECT data FROM audit ORDER BY ts DESC LIMIT 500') as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $audit[] = $d;
            }
        }

        return $audit;
    }

    /** baca_state — reservations (created_at, id), master, the newest 500 audit rows. */
    public function read(): array
    {
        $res = [];
        foreach ($this->db()->select('SELECT data FROM reservations ORDER BY created_at ASC, id ASC') as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $res[] = $d;
            }
        }

        return ['reservations' => $res, 'master' => $this->readMaster(), 'audit' => $this->readAudit()];
    }

    public function ver(): int
    {
        $r = $this->db()->selectOne("SELECT v FROM settings WHERE k='_ver' LIMIT 1");

        return $r && isset($r->v) && ctype_digit((string) $r->v) ? (int) $r->v : 0;
    }

    /** The role the app shows in its audit rows: master.users[id].role. */
    public function role(string $userId): string
    {
        $users = $this->readMaster()['users'] ?? null;
        if (is_array($users)) {
            foreach ($users as $key => $u) {
                $id = is_array($u) ? (string) ($u['id'] ?? (is_string($key) ? $key : '')) : (string) $key;
                if ($id === $userId && is_array($u)) {
                    return (string) ($u['role'] ?? '');
                }
            }
        }

        return '';
    }

    /**
     * One server-owned audit row, shaped like the old frontend's logAudit():
     * {id, ts, user, role, action, detail, res?}. The name comes from the
     * token; the role comes from master.users. Nothing here trusts the body.
     */
    public function auditEntry(string $action, mixed $detail, array $actor, ?string $res = null): array
    {
        $row = [
            'id' => 'a'.(int) floor(microtime(true) * 1000).bin2hex(random_bytes(4)),
            'ts' => (int) floor(microtime(true) * 1000),
            'user' => (string) ($actor['name'] ?? $actor['id'] ?? '-'),
            'role' => $this->role((string) ($actor['id'] ?? '')),
            'action' => $action,
            'detail' => is_scalar($detail) ? (string) $detail : '',
        ];
        if ($res !== null && $res !== '') {
            $row['res'] = $res;
        }

        return $row;
    }

    /** tempelLogRes(): the per-reservation trail keeps the newest 20 and cuts detail at 180. */
    public static function logEntry(array $audit): array
    {
        return [
            'ts' => (int) ($audit['ts'] ?? 0),
            'by' => (string) ($audit['user'] ?? '-'),
            'role' => (string) ($audit['role'] ?? ''),
            'action' => (string) ($audit['action'] ?? ''),
            'detail' => mb_substr((string) ($audit['detail'] ?? ''), 0, 180),
        ];
    }

    /** Append-only, like legacy saveAll: INSERT IGNORE by id, then keep the newest 500 by ts. */
    public function appendAudit(array $row): void
    {
        if (empty($row['id'])) {
            return;
        }
        $this->db()->insert('INSERT IGNORE INTO audit (id, ts, data) VALUES (?,?,?)', [
            self::s($row['id']), intval($row['ts'] ?? 0), self::enc($row),
        ]);
        $this->db()->delete('DELETE FROM audit WHERE id NOT IN (SELECT id FROM (SELECT id FROM audit ORDER BY ts DESC LIMIT 500) t)');
    }

    /**
     * save_all — reconcile the WHOLE state. baseVer is required (APP_LAWAS);
     * a stale one → {conflict:true, saved:false, ver}. Rows: upsert guarded by
     * updated_at, rows missing from the payload deleted (never all of them from
     * an empty payload); audit INSERT IGNORE then trimmed to 500; master; _ver+1.
     */
    public function saveAll(mixed $state, mixed $baseVer): array
    {
        return $this->locked(function () use ($state, $baseVer) {
            if (! is_array($state)) {
                throw new RuntimeException('Payload data kosong/invalid');
            }
            if ($baseVer === null || $baseVer === '') {
                throw new RuntimeException('APP_LAWAS — aplikasi di perangkat ini belum diperbarui. Tekan Ctrl+Shift+R (muat ulang) lalu simpan lagi.');
            }
            $moved = $this->externalize($state); // writes files only: safe even if the save is refused below

            $db = $this->db();
            $db->insert("INSERT IGNORE INTO settings (k,v) VALUES ('_ver','0')");
            $reservations = isset($state['reservations']) && is_array($state['reservations']) ? $state['reservations'] : [];
            $audit = isset($state['audit']) && is_array($state['audit']) ? $state['audit'] : [];

            $out = $db->transaction(function () use ($db, $state, $baseVer, $reservations, $audit) {
                $curVer = (int) $db->selectOne("SELECT v FROM settings WHERE k='_ver' FOR UPDATE")->v;
                if ((string) $curVer !== self::s($baseVer)) {
                    return ['conflict' => true, 'saved' => false, 'ver' => $curVer];
                }
                $buang = $this->gcFiles($state);

                $ids = [];
                foreach ($reservations as $r) {
                    if (! is_array($r) || empty($r['id'])) {
                        continue;
                    }
                    $ids[] = self::s($r['id']);
                    $this->upsertRow($r);
                }
                // an empty payload never empties a populated table
                if ($ids) {
                    $db->delete('DELETE FROM reservations WHERE id NOT IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids);
                }
                foreach ($audit as $a) {
                    if (is_array($a) && ! empty($a['id'])) {
                        $db->insert('INSERT IGNORE INTO audit (id, ts, data) VALUES (?,?,?)', [self::s($a['id']), intval($a['ts'] ?? 0), self::enc($a)]);
                    }
                }
                $db->delete('DELETE FROM audit WHERE id NOT IN (SELECT id FROM (SELECT id FROM audit ORDER BY ts DESC LIMIT 500) t)');
                if (isset($state['master'])) {
                    $db->insert("INSERT INTO settings (k,v) VALUES ('master',?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::enc($state['master'])]);
                }
                $newVer = $curVer + 1;
                $db->update("UPDATE settings SET v = ? WHERE k = '_ver'", [(string) $newVer]);

                return ['ver' => $newVer, 'buang' => $buang];
            });
            if (! empty($out['conflict'])) {
                return $out;
            }

            return ['saved' => true, 'ver' => $out['ver'], 'reservations' => count($reservations), 'audit' => count($audit),
                'blobChars' => 0, 'fotoDipisah' => $moved, 'fotoDihapus' => $out['buang'], 'backend' => 'laravel', 'ts' => gmdate('c')];
        });
    }

    /** One reservation row: indexed columns + the full JSON, applied only when its updatedAt is not older. */
    public function upsertRow(array $r): void
    {
        $cols = ['name', 'phone', 'tanggal', 'jam', 'pax', 'status', 'pic_name', 'source', 'dp_amount', 'data'];
        $upd = implode(', ', array_map(fn ($c) => "$c = IF(VALUES(updated_at) >= updated_at, VALUES($c), $c)", $cols))
            .', updated_at = IF(VALUES(updated_at) >= updated_at, VALUES(updated_at), updated_at)';
        $str = fn ($k) => isset($r[$k]) ? self::s($r[$k]) : null;
        $date = trim(self::s($r['date'] ?? ''));
        $this->db()->insert('INSERT INTO reservations (id,name,phone,tanggal,jam,pax,status,pic_name,source,dp_amount,updated_at,created_at,data)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '.$upd, [
            self::s($r['id']), $str('name'), $str('phone'), preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            isset($r['time']) ? substr(self::s($r['time']), 0, 8) : null, intval($r['pax'] ?? 0),
            $str('status'), $str('picName'), $str('source'), intval($r['dpAmount'] ?? 0),
            intval($r['updatedAt'] ?? 0), intval($r['createdAt'] ?? 0), self::enc($r),
        ]);
    }

    // ───────────────────────────── v1 helpers ──

    /** Run $fn under the flock + NamedLock and one DB transaction. */
    public function writeLocked(\Closure $fn): mixed
    {
        return $this->locked(fn () => $this->db()->transaction($fn));
    }

    /** Move the inline photos of ONE reservation (or of the master blob) to disk; returns the changed value. */
    public function externalizeOne(string $what, array $value): array
    {
        $state = $what === 'master' ? ['master' => $value] : ['reservations' => [$value]];
        $this->externalize($state);

        return $what === 'master' ? $state['master'] : $state['reservations'][0];
    }

    /** Remove the photo files of one deleted reservation. */
    public function deleteRowFiles(array $row): void
    {
        $state = ['reservations' => [$row]];
        self::eachFileField($state, function (&$obj, $field, $key) {
            if (is_file(self::filePath($key))) {
                @unlink(self::filePath($key));
            }
        });
    }

    /** Save the master blob and return it with its inline photos already moved to files. */
    public function saveMaster(array $master): array
    {
        return $this->saveMasterRaw($this->externalizeOne('master', $master));
    }

    /** Save the blob exactly as given; callers externalize only the part they own. */
    public function saveMasterRaw(array $master): array
    {
        $this->db()->insert("INSERT INTO settings (k,v) VALUES ('master',?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::enc($master)]);

        return $master;
    }

    /** Externalize only one section, so a write never changes another section's content version. */
    public function externalizeSection(array $master, string $section): array
    {
        if (! array_key_exists($section, $master)) {
            return $master;
        }
        $state = ['master' => [$section => $master[$section]]];
        $this->externalize($state);
        $master[$section] = $state['master'][$section];

        return $master;
    }

    public function externalizeItem(string $section, array $item): array
    {
        $state = ['master' => [$section => [$item]]];
        $this->externalize($state);

        return $state['master'][$section][0];
    }

    /** Remove the deterministic proof files of one master item when its new value no longer points at them. */
    public function deleteItemFiles(string $section, array $old, ?array $new = null): void
    {
        if (empty($old['id'])) {
            return;
        }
        $fields = match ($section) {
            'reviews' => ['proofData' => 'rv', 'proof2Data' => 'rv2'],
            'feedbacks' => ['proofData' => 'fb'],
            default => [],
        };
        $id = self::s($old['id']);
        foreach ($fields as $field => $prefix) {
            $key = $prefix.':'.$id;
            if (($new[$field] ?? null) === self::FILE_TAG.$key) {
                continue;
            }
            if (is_file(self::filePath($key))) {
                @unlink(self::filePath($key));
            }
        }
    }

    /** _ver + 1 (the row must be locked FOR UPDATE by the caller's transaction or be created here). */
    public function bumpVer(): void
    {
        $this->db()->insert("INSERT IGNORE INTO settings (k,v) VALUES ('_ver','0')");
        $v = (int) $this->db()->selectOne("SELECT v FROM settings WHERE k='_ver' FOR UPDATE")->v;
        $this->db()->update("UPDATE settings SET v = ? WHERE k = '_ver'", [(string) ($v + 1)]);
    }

    public function stats(): array
    {
        $count = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM reservations')->c;
        $state = $this->read();
        $blob = strlen(self::enc($state));
        $inline = 0;
        $ref = 0;
        self::eachFileField($state, function (&$obj, $field) use (&$inline, &$ref) {
            if (! isset($obj[$field])) {
                return;
            }
            if (self::isInline($obj[$field])) {
                $inline++;
            } elseif (self::isRef($obj[$field])) {
                $ref++;
            }
        });
        $n = is_dir(self::filesDir()) ? count(array_filter(scandir(self::filesDir()), fn ($f) => str_ends_with($f, '.txt'))) : 0;

        return ['backend' => 'laravel', 'db' => Modules::databaseName('reservasi'), 'blobChars' => $blob, 'blobMB' => round($blob / 1048576, 3),
            'reservations' => $count, 'fotoMasihInline' => $inline, 'fotoSudahDipisah' => $ref, 'fileFoto' => $n,
            'folderFoto' => self::dir(), 'amanDiLuarWeb' => ! str_starts_with(self::dir(), (string) realpath(public_path()))];
    }
}
