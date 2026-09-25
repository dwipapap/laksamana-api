<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use App\Support\RowSync;

/** Diagnostics: table counts, payload size and receipt-folder figures. Port of stats(). */
class KontenQueries
{
    public function __construct(
        private readonly KontenState $state,
        private readonly KontenFiles $files,
    ) {}

    public function stats(): array
    {
        $db = Modules::db('konten');
        $out = ['backend' => 'laravel', 'db' => Modules::databaseName('konten')];
        foreach (KontenSchema::statsTables() as $t) {
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
