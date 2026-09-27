<?php

namespace App\Modules\Bd\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * BD OS — port of bd-mysql/lib_bd_mysql.php (LIB_VERSI 2026-08-03a).
 *
 * Rows are indexed columns + the full `data` JSON, decoded as ASSOC arrays
 * like the legacy code (so an empty object comes back as `[]` on the compat
 * surface, exactly as before). Writes run under GET_LOCK('<db>:bd_save').
 *
 * saveAll is the `sinceTs` RowSync variant with its own delete rule (see
 * deleteMissing): no baseUpdatedAt conflict check, only the updated_at
 * ordering guard in the upsert.
 */
class BdState
{
    public const LIB_VERSI = '2026-08-03a';

    /** Last stage of the purchasing flow — must equal PO_SELESAI in deploy/bd/index.html. */
    public const PO_SELESAI = 'Diterima';

    public const BUSY = 'Server sedang sibuk menyimpan, coba lagi sebentar.';

    private function db(): ConnectionInterface
    {
        return Modules::db('bd');
    }

    /** App collection => table + indexed columns: column => [app field(s), type]. */
    public static function collections(): array
    {
        return [
            'people' => ['table' => 'people', 'cols' => [
                'name' => ['name', 'str'], 'role' => ['role', 'str'], 'divisi' => ['div', 'str'],
                'boss_id' => ['boss', 'str'], 'office_user_id' => ['officeUserId', 'str'], 'active' => ['active', 'bool'],
            ]],
            'projects' => ['table' => 'projects', 'cols' => [
                'name' => ['name', 'str'], 'type' => ['type', 'str'], 'stage' => ['stage', 'str'], 'divisi' => ['div', 'str'],
                'pic' => [['pics', 'pic'], 'first'], 'start_date' => ['start', 'date'], 'end_date' => ['end', 'date'],
                'budget' => ['budget', 'int'], 'spent' => ['spent', 'int'], 'health' => ['health', 'str'],
            ]],
            'tasks' => ['table' => 'tasks', 'cols' => [
                'name' => ['name', 'str'], 'divisi' => ['div', 'str'], 'pic' => [['pics', 'pic'], 'first'],
                'status' => ['status', 'str'], 'priority' => ['priority', 'str'], 'deadline' => ['deadline', 'date'],
                'important' => ['important', 'bool'], 'urgent' => ['urgent', 'bool'], 'type' => ['type', 'str'],
                'project_id' => ['project', 'str'], 'progress' => ['progress', 'int'],
            ]],
            'routines' => ['table' => 'routines', 'cols' => [
                'name' => ['name', 'str'], 'divisi' => ['div', 'str'], 'pic' => ['pic', 'str'], 'freq' => ['freq', 'str'],
                'important' => ['important', 'bool'], 'urgent' => ['urgent', 'bool'], 'active' => ['active', 'bool'],
            ]],
            'coord' => ['table' => 'coord_requests', 'cols' => [
                'title' => ['title', 'str'], 'from_div' => ['fromDiv', 'str'], 'to_div' => ['toDiv', 'str'],
                'requested_by' => ['by', 'str'], 'assignee' => ['to', 'str'], 'status' => ['status', 'str'],
                'priority' => ['priority', 'str'], 'due_date' => ['due', 'date'], 'project_id' => ['project', 'str'],
            ]],
            'po' => ['table' => 'purchase_orders', 'cols' => [
                'item' => ['item', 'str'], 'vendor' => ['vendor', 'str'], 'qty' => ['qty', 'int'], 'unit' => ['unit', 'str'],
                'divisi' => ['div', 'str'], 'amount' => ['amount', 'int'], 'status' => ['status', 'str'],
                'payment' => ['payment', 'str'], 'need_by' => ['needBy', 'date'], 'pic' => ['pic', 'str'],
                'project_id' => ['project', 'str'], 'pr_id' => ['prId', 'str'],
            ]],
            // Weekly PR documents; their items live in purchase_orders (pr_id).
            'pr' => ['table' => 'purchase_requests', 'cols' => [
                'no' => ['no', 'str'], 'nama' => ['nama', 'str'], 'dept' => ['dept', 'str'], 'tanggal' => ['tanggal', 'date'],
                'week_start' => ['weekStart', 'date'], 'status' => ['status', 'str'], 'total' => ['total', 'int'],
            ]],
            'agenda' => ['table' => 'agenda', 'cols' => [
                'title' => ['title', 'str'], 'tanggal' => ['date', 'date'], 'type' => ['type', 'str'], 'divisi' => ['div', 'str'],
            ]],
        ];
    }

    /** BD cut over (#59): the Modul reads and writes the bd_* tables of core. */
    public static function onCore(): bool
    {
        return Modules::connectionName('bd') === 'core';
    }

    /** Physical table for a legacy table name on the current connection. */
    public static function table(string $legacy): string
    {
        if (! self::onCore()) {
            return $legacy;
        }

        return $legacy === 'settings' ? 'bd_pengaturan' : 'bd_'.$legacy;
    }

    /** The row key: the legacy id, kept in `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** PHP's (string) cast without the "Array to string" warning Laravel would turn into an exception. */
    public static function str(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    /** Legacy ambil(): the first field of $field present in the row, converted to $type. */
    public static function ambil(array $row, string|array $field, string $type): mixed
    {
        $v = null;
        foreach ((array) $field as $f) {
            if (array_key_exists($f, $row)) {
                $v = $row[$f];
                break;
            }
        }

        return match ($type) {
            'int' => intval($v),
            'bool' => empty($v) ? 0 : 1,
            'date' => RowSync::tanggal($v),
            // first element of a list (`pics`), or the old single-string `pic`
            'first' => is_array($v)
                ? (count($v) ? self::str(array_key_exists(0, $v) ? $v[0] : null) : null)
                : (($v === null || $v === '') ? null : self::str($v)),
            default => $v === null ? null : self::str($v),
        };
    }

    public function identity(): array
    {
        return ['env' => Modules::envLabel(), 'db' => Modules::databaseName('bd'), 'versi' => self::LIB_VERSI];
    }

    // ───────────────────────────── read ──

    /** baca_state — the app's DB object plus `_serverTs` (server clock, the client's next sinceTs). */
    public function read(): array
    {
        $out = [];
        foreach (self::collections() as $name => $c) {
            $out[$name] = $this->rows($c['table']);
        }
        $out['focus'] = $this->setting('focus', new stdClass);      // {peopleId: {teks, tgl}}
        $out['approverSets'] = $this->setting('approverSets', []);  // PR signers
        $out['promos'] = $this->setting('promos', []);              // also read by radar & kompas
        $out['_serverTs'] = self::nowMs();

        return $out;
    }

    /** Decoded rows of one table in legacy order (created_at, id). */
    public function rows(string $table): array
    {
        $rows = [];
        $t = self::table($table);
        $id = self::idCol();
        foreach ($this->db()->select("SELECT `data` FROM `$t` ORDER BY `created_at` ASC, `$id` ASC") as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $rows[] = $d;
            }
        }

        return $rows;
    }

    public function setting(string $k, mixed $default): mixed
    {
        $row = $this->db()->selectOne('SELECT `v` FROM `'.self::table('settings').'` WHERE `k` = ? LIMIT 1', [$k]);
        if (! $row) {
            return $default;
        }
        $v = json_decode((string) $row->v, true);

        return $v === null ? $default : $v;
    }

    public function putSetting(string $k, mixed $v): void
    {
        RowSync::putSetting($this->db(), $k, $v, self::table('settings'), self::onCore());
    }

    public function stats(): array
    {
        $out = ['backend' => 'laravel', ...$this->identity()];
        foreach (['people', 'projects', 'tasks', 'routines', 'coord_requests', 'purchase_orders', 'purchase_requests', 'agenda', 'settings'] as $t) {
            $out[$t] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `'.self::table($t).'`')->c;
        }
        $blob = strlen(RowSync::enc($this->read()));
        $out['blobChars'] = $blob;
        $out['blobMB'] = round($blob / 1048576, 3);
        $out['ts'] = gmdate('c');

        return $out;
    }

    // ───────────────────────────── saveAll ──

    public function saveAll(mixed $state, int $sinceTs): array
    {
        return NamedLock::run('bd', 'bd_save', fn () => $this->saveAllLocked($state, $sinceTs), 10, self::BUSY);
    }

    private function saveAllLocked(mixed $state, int $sinceTs): array
    {
        if (! is_array($state)) {
            throw new RuntimeException('Payload data kosong/invalid');
        }

        $hitung = [];
        $this->db()->transaction(function () use ($state, $sinceTs, &$hitung) {
            foreach (self::collections() as $name => $c) {
                if (! array_key_exists($name, $state)) {
                    continue; // not sent => untouched (not emptied)
                }
                $hitung[$name] = $this->upsertCollection($c, is_array($state[$name]) ? $state[$name] : [], $sinceTs);
            }
            if (isset($state['focus'])) {
                $this->putSetting('focus', $state['focus']);
            }
            // array_key_exists: an emptied list must be stored empty, not ignored.
            foreach (['approverSets', 'promos'] as $k) {
                if (array_key_exists($k, $state)) {
                    $this->putSetting($k, $state[$k]);
                }
            }
        });

        return [
            'saved' => true,
            'jumlah' => $hitung,
            'backend' => 'laravel',
            'ts' => gmdate('c'),
            'tsMs' => self::nowMs(), // the client advances its sinceTs to this
        ];
    }

    /** upsert_collection — per-row write with the updated_at ordering guard, then delete the missing ones. */
    private function upsertCollection(array $c, array $rows, int $sinceTs): int
    {
        $ids = [];
        $maxUpd = 0;
        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['id'])) {
                continue;
            }
            $ids[] = self::str($r['id']);
            $ua = RowSync::ms($r['updatedAt'] ?? 0);
            $maxUpd = max($maxUpd, $ua);
            $this->writeRow($c, $r, $ua, RowSync::ms($r['createdAt'] ?? 0), false);
        }
        $this->deleteMissing($c['table'], $ids, $sinceTs > 0 ? $sinceTs : $maxUpd);

        return count($ids);
    }

    /**
     * INSERT (IGNORE) or INSERT … ON DUPLICATE KEY UPDATE guarded by updated_at;
     * created_at is written once and never overwritten.
     */
    public function writeRow(array $c, array $r, int $updatedAt, int $createdAt, bool $insertOnly): void
    {
        $core = self::onCore();
        $cols = array_keys($c['cols']);
        $physical = self::table($c['table']);
        $args = [];
        foreach ($c['cols'] as $col => [$field, $type]) {
            $v = self::ambil($r, $field, $type);
            $args[] = $core ? RowSync::fit($this->db(), $physical, $col, $v) : $v;
        }
        if ($core && $c['table'] === 'people') {
            // the linked Office User as a real FK (NULL when unlinked or unknown)
            $cols[] = 'user_id';
            $officeId = $args[array_search('office_user_id', array_keys($c['cols']), true)];
            $args[] = $officeId === null ? null : $this->db()->selectOne('SELECT `id` FROM `user` WHERE `legacy_id` = ?', [$officeId])?->id;
        }
        $names = $core
            ? ['id', 'legacy_id', ...$cols, 'updated_at', 'created_at', 'data', 'version']
            : ['id', ...$cols, 'updated_at', 'created_at', 'data'];
        $args = $core
            ? [strtolower((string) Str::ulid()), self::str($r['id']), ...$args, $updatedAt, $createdAt, RowSync::enc($r), 1]
            : [self::str($r['id']), ...$args, $updatedAt, $createdAt, RowSync::enc($r)];

        $sql = 'INSERT '.($insertOnly ? 'IGNORE ' : '').'INTO `'.self::table($c['table']).'` (`'.implode('`,`', $names).'`) VALUES ('
            .implode(',', array_fill(0, count($names), '?')).')';
        if (! $insertOnly) {
            // version first: later assignments see the NEW updated_at
            $upd = $core ? ['`version` = `version` + IF(VALUES(`updated_at`) >= `updated_at`, 1, 0)'] : [];
            array_push($upd, ...array_map(fn ($n) => "`$n` = IF(VALUES(`updated_at`) >= `updated_at`, VALUES(`$n`), `$n`)", [...$cols, 'data']));
            $upd[] = '`updated_at` = IF(VALUES(`updated_at`) >= `updated_at`, VALUES(`updated_at`), `updated_at`)';
            $sql .= ' ON DUPLICATE KEY UPDATE '.implode(', ', $upd);
        }
        $this->db()->statement($sql, $args);
    }

    /**
     * hapus_yang_hilang — delete rows missing from the payload, bounded by $batas
     * (sinceTs: what the client knew of the server). An empty payload is trusted
     * only when $batas is set; with ids but no bound it is a plain NOT IN.
     */
    private function deleteMissing(string $table, array $ids, int $batas): void
    {
        if (! $ids && $batas <= 0) {
            return;
        }
        $table = self::table($table);
        if ($ids) {
            $sql = "DELETE FROM `$table` WHERE `".self::idCol().'` NOT IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            $args = $ids;
            if ($batas > 0) {
                $sql .= ' AND `updated_at` <= ?';
                $args[] = $batas;
            }
        } else {
            $sql = "DELETE FROM `$table` WHERE `updated_at` <= ?";
            $args = [$batas];
        }
        $this->db()->delete($sql, $args);
    }

    // ───────────────────────────── addPo / setRealisasi (other modules) ──

    /**
     * tambah_po — insert-only purchase rows from Marketing. Ids are always
     * generated here (an outside id could collide and INSERT IGNORE would drop
     * the new row silently).
     */
    public function addPo(mixed $rows): array
    {
        return NamedLock::run('bd', 'bd_save', function () use ($rows) {
            if (! is_array($rows) || ! count($rows)) {
                throw new RuntimeException('Tidak ada baris untuk ditambahkan');
            }
            if (count($rows) > 200) {
                throw new RuntimeException('Terlalu banyak baris sekaligus (maks 200)');
            }
            $c = self::collections()['po'];
            $now = self::nowMs();
            $n = 0;
            $ids = [];
            $this->db()->transaction(function () use ($rows, $c, $now, &$n, &$ids) {
                foreach ($rows as $r) {
                    if (! is_array($r) || empty($r['item'])) {
                        continue;
                    }
                    $r['id'] = 'po'.bin2hex(random_bytes(5));
                    if (empty($r['status'])) {
                        $r['status'] = 'Diajukan';
                    }
                    $r['updatedAt'] = $now;
                    $r['createdAt'] = $now;
                    $this->writeRow($c, $r, $now, $now, true);
                    $ids[] = $r['id'];
                    $n++;
                }
            });

            return ['added' => $n, 'ts' => gmdate('c'), 'ids' => $ids];
        }, 10, self::BUSY);
    }

    /**
     * set_realisasi — Finance → Kas Kecil writes the spent amount back into ONE
     * PO and marks it processed (status → PO_SELESAI, the previous status kept
     * in statusSebelum), mirroring togglePoProses() in deploy/bd/index.html.
     * '' / null removes the realisation and reverts the marker.
     */
    public function setRealisasi(mixed $id, mixed $nilai, mixed $oleh): array
    {
        return NamedLock::run('bd', 'bd_save', function () use ($id, $nilai, $oleh) {
            $id = trim(self::str($id));
            if ($id === '') {
                throw new RuntimeException('id PO kosong');
            }
            $po = self::table('purchase_orders');
            $key = self::idCol();
            $row = $this->db()->selectOne("SELECT `data` FROM `$po` WHERE `$key` = ? LIMIT 1", [$id]);
            if (! $row) {
                throw new RuntimeException('PO tidak ditemukan: '.$id);
            }
            $data = json_decode((string) $row->data, true);
            if (! is_array($data)) {
                $data = ['id' => $id];
            }
            $lama = $data['realisasi'] ?? '';
            $now = self::nowMs();

            if ($nilai === '' || $nilai === null) {
                unset($data['realisasi'], $data['proses'], $data['prosesAt'], $data['prosesBy']);
                $baru = '';
                // Revert the status only if it is still the one we set.
                if (isset($data['status']) && $data['status'] === self::PO_SELESAI && ! empty($data['statusSebelum'])) {
                    $data['status'] = $data['statusSebelum'];
                }
                unset($data['statusSebelum']);
            } else {
                $baru = max(0, (int) preg_replace('/[^0-9]/', '', self::str($nilai)));
                $data['realisasi'] = $baru;
                $data['proses'] = true;
                $data['prosesAt'] = $now;
                $data['prosesBy'] = substr(trim(self::str($oleh)), 0, 120);
                if (! isset($data['status']) || $data['status'] !== self::PO_SELESAI) {
                    if (isset($data['status']) && $data['status'] !== '') {
                        $data['statusSebelum'] = $data['status'];
                    }
                    $data['status'] = self::PO_SELESAI;
                }
            }
            $data['updatedAt'] = $now;

            $this->db()->update("UPDATE `$po` SET `data` = ?, `status` = ?, `updated_at` = ?".(self::onCore() ? ', `version` = `version` + 1' : '')." WHERE `$key` = ?",
                [RowSync::enc($data), isset($data['status']) ? self::str($data['status']) : '', $now, $id]);

            return [
                'id' => $id,
                'item' => $data['item'] ?? '',
                'realisasi' => $baru,
                'sebelum' => $lama,
                'proses' => ! empty($data['proses']),
                'status' => $data['status'] ?? '',
                'ts' => gmdate('c'),
            ];
        }, 10, self::BUSY);
    }
}
