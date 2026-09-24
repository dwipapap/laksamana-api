<?php

namespace App\Modules\Marketing\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * The whole marketing state S — port of baca_state() and save_all().
 *
 * saveAll keeps every guard documented in laksamana-office CLAUDE.md
 * ("Data Marketing hilang sendiri", babak 1–7):
 *  - per-row conflict via baseUpdatedAt, but identical content (sidik) is NOT a conflict
 *  - client-clock stamps, bumped to server+1 only when needed, reported in `versi`
 *  - deletes bounded by `_sejak`; `_sejak` missing/0 deletes nothing; empty list never deletes
 *  - designreqs & vip merged per row inside settings, BEFORE the generic `extra:` branch
 *  - activities append-only (insert only unknown ids), trimmed to 5000
 *  - one GET_LOCK('<db>:mkt_save') + one transaction; receipt GC after commit (~1 in 20)
 */
class MarketingState
{
    public function __construct(private readonly MarketingFiles $files) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('marketing');
    }

    /** Exactly the object S the app uses. */
    public function read(): array
    {
        $db = $this->db();
        $out = [];
        foreach (MarketingSchema::collections() as $name => $c) {
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

        $act = [];
        foreach ($db->select('SELECT data FROM activities ORDER BY at_time DESC, id DESC LIMIT 1000') as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                $act[] = $r;
            }
        }
        $out['activities'] = $act;

        foreach ($db->select('SELECT k, v FROM settings') as $row) {
            $v = json_decode((string) $row->v, true);
            if (str_starts_with((string) $row->k, 'extra:')) {
                $out[substr((string) $row->k, 6)] = $v;
            } else {
                $out[(string) $row->k] = $v;
            }
        }
        if (! isset($out['settings']) || ! is_array($out['settings'])) {
            $out['settings'] = new stdClass;
        }
        if (! isset($out['baseline'])) {
            $out['baseline'] = 0;
        }

        $max = 0;
        foreach (MarketingSchema::collections() as $c) {
            try {
                $max = max($max, (int) ($db->selectOne("SELECT COALESCE(MAX(updated_at),0) m FROM `{$c['table']}`")->m ?? 0));
            } catch (\Throwable) {
            }
        }
        foreach (MarketingSchema::settingsCollections() as $name) {
            $max = max($max, RowSync::versiBaris(isset($out[$name]) && is_array($out[$name]) ? $out[$name] : []));
        }
        $out['_versi'] = $max;

        return $out;
    }

    /** Current stored version of the whole state (the `_versi` a client would receive). */
    public function version(): int
    {
        return (int) $this->read()['_versi'];
    }

    public function saveAll(mixed $state): array
    {
        return NamedLock::run('marketing', 'mkt_save', fn () => $this->saveAllLocked($state));
    }

    /** saveAll body; caller must hold the mkt_save lock. */
    public function saveAllLocked(mixed $state): array
    {
        if (! is_array($state)) {
            throw new RuntimeException('Payload data kosong/invalid');
        }
        $db = $this->db();
        $hitung = [];
        $bentrok = [];
        $versi = [];

        $db->transaction(function () use ($db, $state, &$hitung, &$bentrok, &$versi) {
            $sejak = isset($state['_sejak']) ? RowSync::ms($state['_sejak']) : 0;
            $known = ['activities', '_rev', '_sejak', '_versi'];

            foreach (MarketingSchema::collections() as $name => $c) {
                $known[] = $name;
                if (! array_key_exists($name, $state)) {
                    continue;
                }
                $rows = is_array($state[$name]) ? $state[$name] : [];
                $ids = RowSync::upsertCollection($db, $c, $rows, $name, MarketingSchema::SYNC, $bentrok, $versi);
                RowSync::deleteMissing($db, $c['table'], 'id', $ids, 'sejak', $sejak);
                $hitung[$name] = count($ids);
            }

            // MUST run before the generic extra: branch (and be listed in $known).
            foreach (MarketingSchema::settingsCollections() as $name) {
                $known[] = $name;
                if (! array_key_exists($name, $state)) {
                    continue;
                }
                $rows = is_array($state[$name]) ? $state[$name] : [];
                $hitung[$name] = RowSync::upsertSettingsCollection($db, $name, $rows, MarketingSchema::SYNC, $bentrok, $versi, $sejak);
            }

            if (isset($state['activities']) && is_array($state['activities'])) {
                $hitung['activities'] = $this->appendActivities($db, $state['activities']);
            }

            foreach (MarketingSchema::scalarKeys() as $k) {
                $known[] = $k;
                if (array_key_exists($k, $state)) {
                    RowSync::putSetting($db, $k, $state[$k]);
                }
            }
            foreach ($state as $k => $v) {
                if (! in_array($k, $known, true)) {
                    RowSync::putSetting($db, 'extra:'.$k, $v);
                }
            }
        });

        // File GC after commit (deleting files cannot be rolled back); not on every save.
        $buang = 0;
        if (mt_rand(1, 20) === 1) {
            try {
                $buang = $this->files->gc();
            } catch (\Throwable) {
            }
        }

        return [
            'saved' => true,
            'jumlah' => $hitung,
            'berkasDibuang' => $buang,
            'bentrok' => $bentrok,
            'versi' => $versi,
            'backend' => 'laravel',
            'ts' => gmdate('c'),
        ];
    }

    /** Append-only: insert only ids not yet stored; trim to the newest 5000 when something was added. */
    public function appendActivities(ConnectionInterface $db, array $activities): int
    {
        $send = [];
        foreach ($activities as $a) {
            if (is_array($a) && ! empty($a['id'])) {
                $send[(string) $a['id']] = $a;
            }
        }
        $exists = [];
        foreach (array_chunk(array_keys($send), 500) as $batch) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            foreach ($db->select("SELECT id FROM activities WHERE id IN ($ph)", array_map('strval', $batch)) as $row) {
                $exists[(string) $row->id] = true;
            }
        }
        $new = 0;
        foreach ($send as $id => $a) {
            if (isset($exists[$id])) {
                continue;
            }
            $db->insert('INSERT IGNORE INTO activities (id, ref_type, ref_id, action, by_user, at_time, data) VALUES (?,?,?,?,?,?,?)', [
                (string) $id,
                RowSync::ambil($a, 'refType', 'str'), RowSync::ambil($a, 'refId', 'str'),
                RowSync::ambil($a, 'action', 'str'), RowSync::ambil($a, 'by', 'str'),
                RowSync::ambil($a, 'at', 'datetime'), RowSync::enc($a),
            ]);
            $new++;
        }
        if ($new > 0) {
            $db->statement('DELETE FROM activities WHERE id NOT IN
                (SELECT id FROM (SELECT id FROM activities ORDER BY at_time DESC, id DESC LIMIT 5000) t)');
        }

        return $new;
    }
}
