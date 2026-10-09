<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use App\Support\RowSync;

/** Diagnostics: table counts, payload size and receipt-folder figures. Port of stats(). */
class KontenQueries
{
    /**
     * Crew roles that do production work. They come first in designOptions(),
     * mirroring DR_PERAN_PROD in deploy/marketing (the Request Design PIC picker).
     */
    private const PROD_ROLES = ['designer', 'video_editor', 'photographer', 'content_director', 'content_planner'];

    public function __construct(
        private readonly KontenState $state,
        private readonly KontenFiles $files,
    ) {}

    /**
     * Brand & crew picker for Marketing's Request Design form (G-16). Narrow by
     * design: {brands:[{id,name}], pics:[{id,name,roles}]} — active crew only,
     * production roles first, then by name, like the legacy picker that read
     * these straight from konten getAll (deploy/marketing drMuatKonten). No
     * other Konten state is handed over.
     */
    public function designOptions(): array
    {
        $db = Modules::db('konten');
        $idCol = KontenSchema::idCol();

        $brands = [];
        $t = KontenSchema::table('brands');
        foreach ($db->select("SELECT `$idCol` AS id, data FROM `$t` ORDER BY `$idCol` ASC") as $row) {
            $d = json_decode((string) $row->data, true);
            if (! is_array($d)) {
                continue;
            }
            $id = (string) ($row->id ?? ($d['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $brands[] = ['id' => $id, 'name' => (string) ($d['name'] ?? '')];
        }

        $pics = [];
        $t = KontenSchema::table('users');
        foreach ($db->select("SELECT `$idCol` AS id, data FROM `$t` ORDER BY `$idCol` ASC") as $row) {
            $d = json_decode((string) $row->data, true);
            if (! is_array($d)) {
                continue;
            }
            $id = (string) ($row->id ?? ($d['id'] ?? ''));
            // Inactive crew stays in Konten so old work keeps its name, but it is
            // never offered: a request aimed at someone who left reaches nobody.
            if ($id === '' || ($d['active'] ?? null) === false) {
                continue;
            }
            $roles = isset($d['roles']) && is_array($d['roles'])
                ? array_values(array_filter(array_map('strval', $d['roles']))) : [];
            $pics[] = ['id' => $id, 'name' => (string) ($d['name'] ?? ''), 'roles' => $roles,
                'prod' => (bool) array_intersect($roles, self::PROD_ROLES)];
        }
        usort($pics, fn ($a, $b) => [(int) $b['prod'], mb_strtolower((string) $a['name'])]
            <=> [(int) $a['prod'], mb_strtolower((string) $b['name'])]);
        foreach ($pics as &$p) {
            unset($p['prod']);
        }
        unset($p);

        return ['brands' => $brands, 'pics' => $pics];
    }

    public function stats(): array
    {
        $db = Modules::db('konten');
        $out = ['backend' => 'laravel', 'db' => Modules::databaseName('konten')];
        // Keys stay the legacy table names on both connections (parity).
        foreach (KontenSchema::collections() as $name => $def) {
            $t = KontenSchema::table($name);
            $out[$def['table']] = (int) $db->selectOne("SELECT COUNT(*) c FROM `$t`")->c;
        }
        $log = KontenSchema::table('logs');
        $out['logs'] = (int) $db->selectOne("SELECT COUNT(*) c FROM `$log`")->c;
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
