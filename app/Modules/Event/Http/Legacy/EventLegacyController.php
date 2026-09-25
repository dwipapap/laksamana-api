<?php

namespace App\Modules\Event\Http\Legacy;

use App\Modules\Event\Services\EventFiles;
use App\Modules\Event\Services\EventState;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\RowSync;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for event-mysql/api.php — `/event-api-mysql/api.php`.
 *
 * Every action is open (legacy had no session gate, only the optional
 * API_TOKEN). A GET without ?action= is getAll. eventsHari is the narrow
 * cross-module read used by Finance > Omset > Breakdown Sumber; `tgl` and
 * `key` are read from the query string only, like legacy `$_GET`.
 */
class EventLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly EventState $state,
        private readonly EventFiles $files,
    ) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        // $_GET exactly: Laravel's TrimStrings / ConvertEmptyStringsToNull would turn
        // `?action=` into getAll (legacy: unknown action "") and trim `tgl`.
        parse_str((string) $req->http->server('QUERY_STRING', ''), $get);
        $q = fn (string $k) => RowSync::strRaw($get[$k] ?? '') ?? '';
        $action = $req->isPost() ? $req->action : (array_key_exists('action', $get) ? $q('action') : 'getAll');

        return match ($action) {
            'getAll' => Envelope::okData($this->state->read()),
            'stats' => Envelope::okData($this->state->stats()),
            'ping' => Envelope::okData(array_merge(['pong' => true, 'backend' => 'laravel'], $this->state->identity(), ['ts' => gmdate('c')])),
            'saveAll' => Envelope::okData($this->state->saveAll($req->body['data'] ?? null)),
            'eventsHari' => Envelope::okData($this->state->eventsHari($q('tgl'))),
            'file' => $this->files->stream($q('key')),
            'upload' => Envelope::okData($this->files->save($req->body)),
            default => Envelope::error('Aksi tidak dikenal: '.$action),
        };
    }
}
