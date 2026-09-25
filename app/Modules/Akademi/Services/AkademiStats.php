<?php

namespace App\Modules\Akademi\Services;

use App\Support\Modules;
use App\Support\RowSync;

/**
 * Read models: trainingStats (for the HR Panel) and stats (diagnostics).
 *
 * trainingStats() is a line-by-line port of training_stats(). It MIRRORS the
 * akademi client's userStats() EXACTLY so the numbers never differ:
 *   matsForUser : published materials matching the user's division
 *                 ('all' division matches everything)
 *   isDone      : quiz -> data.passed === true; anything else -> data.status === 'done'
 *   mandPct     : over the MANDATORY materials; no mandatory materials -> 100
 *
 * Column note: never use the `progress.done` column as the pass signal. That
 * column is derived (`empty($r['done']) ? 0 : 1`) and is NOT the same as a
 * quiz `passed`. The source of truth stays the `data` JSON, same as read().
 *
 * Read-only, one direction: akademi never reads back from hr. Exposed as a
 * clean service method (like DwService::scheduleRange) so the future HR
 * Panel calls it in-process instead of over HTTP.
 */
class AkademiStats
{
    public function __construct(
        private readonly AkademiState $state,
        private readonly AkademiFiles $files,
    ) {}

    /**
     * @return array<string,array{mandPct:int,mandTotal:int,mandDone:int,total:int,done:int,certified:bool}>|object
     *                                                                                                              keyed by user id ({} when nobody is active, like legacy).
     */
    public function trainingStats(): array|object
    {
        $db = Modules::db('akademi');

        // Published materials only, with the (PLURAL) division[] from `data`.
        $mats = [];
        foreach ($db->select('SELECT id, kind, mandatory, published, data FROM materials') as $row) {
            if (empty($row->published)) {
                continue;
            }
            $d = json_decode((string) $row->data, true);
            $div = (is_array($d) && isset($d['division'])) ? $d['division'] : [];
            if (! is_array($div)) {
                $div = $div === null || $div === '' ? [] : [$div];
            }
            $mats[] = [
                'id' => $row->id,
                'kind' => $row->kind,
                'mandatory' => ! empty($row->mandatory),
                'division' => $div,
            ];
        }

        // progress[userId][materialId] = the whole data JSON (has passed/status).
        $prog = [];
        foreach ($db->select('SELECT user_id, material_id, data FROM progress') as $row) {
            $r = json_decode((string) $row->data, true);
            if (! is_array($r)) {
                continue;
            }
            $prog[(string) $row->user_id][(string) $row->material_id] = $r;
        }

        $out = [];
        foreach ($db->select('SELECT id, divisi, active FROM users') as $u) {
            if (empty($u->active)) {
                continue; // inactive crew is excluded
            }
            $uid = $u->id;
            $divisi = $u->divisi;
            $pByU = $prog[$uid] ?? [];

            $total = 0;
            $done = 0;
            $mandTotal = 0;
            $mandDone = 0;
            foreach ($mats as $m) {
                $cocok = ($divisi === 'all')
                    || in_array('all', $m['division'], true)
                    || in_array($divisi, $m['division'], true);
                if (! $cocok) {
                    continue;
                }

                $p = $pByU[$m['id']] ?? null;
                $selesai = false;
                if (is_array($p)) {
                    $selesai = ($m['kind'] === 'quiz')
                        ? (isset($p['passed']) && $p['passed'] === true)
                        : (isset($p['status']) && $p['status'] === 'done');
                }

                $total++;
                if ($selesai) {
                    $done++;
                }
                if ($m['mandatory']) {
                    $mandTotal++;
                    if ($selesai) {
                        $mandDone++;
                    }
                }
            }

            $out[$uid] = [
                'mandPct' => $mandTotal ? (int) round($mandDone / $mandTotal * 100) : 100,
                'mandTotal' => $mandTotal,
                'mandDone' => $mandDone,
                'total' => $total,
                'done' => $done,
                'certified' => $mandTotal > 0 && $mandDone === $mandTotal,
            ];
        }

        return $out !== [] ? $out : (object) [];
    }

    /** Diagnostics: table counts, payload size and receipt-folder figures. Port of stats(). */
    public function stats(): array
    {
        $db = Modules::db('akademi');
        $out = ['backend' => 'laravel', 'db' => Modules::databaseName('akademi')];
        foreach (AkademiSchema::statsTables() as $t) {
            $out[$t] = (int) $db->selectOne("SELECT COUNT(*) c FROM `$t`")->c;
        }
        $blob = strlen(RowSync::enc($this->state->read()));
        $out['blobChars'] = $blob;
        $out['blobMB'] = round($blob / 1048576, 3);

        // Receipt files on disk — monitor without opening the file manager.
        // A `berkasYatim` that keeps growing means the GC is not keeping up.
        $out = array_merge($out, $this->files->diskStats());
        $out['ts'] = gmdate('c');

        return $out;
    }
}
