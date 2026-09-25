<?php

namespace App\Modules\Konten\Http\Legacy;

use App\Modules\Konten\Services\KontenFiles;
use App\Modules\Konten\Services\KontenQueries;
use App\Modules\Konten\Services\KontenState;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for konten-mysql/api.php — `/konten-api-mysql/api.php`.
 * All actions are open (legacy had no session gate).
 * saveAll keeps ok:true even with conflicts — the refused rows are in data.bentrok.
 */
class KontenLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly KontenState $state,
        private readonly KontenQueries $queries,
        private readonly KontenFiles $files,
    ) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $q = fn (string $k) => (string) $req->http->query($k, '');

        return match ($req->action) {
            'getAll' => Envelope::okData($this->state->read()),
            'stats' => Envelope::okData($this->queries->stats()),
            'ping' => Envelope::okData(['pong' => true, 'backend' => 'laravel', 'ts' => gmdate('c')]),
            'receipt' => $this->files->stream($q('key')),
            'uploadReceipt' => Envelope::okData($this->files->save($b)),
            'saveAll' => Envelope::okData($this->state->saveAll($b['data'] ?? null)),
            default => Envelope::error('Aksi tidak dikenal: '.$req->action),
        };
    }
}
