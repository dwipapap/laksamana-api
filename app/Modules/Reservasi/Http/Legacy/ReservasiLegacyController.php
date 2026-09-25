<?php

namespace App\Modules\Reservasi\Http\Legacy;

use App\Modules\Reservasi\Services\ReservasiState;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for reservasi-mysql/api.php — `/reservasi-api-mysql/api.php`.
 * Open, as legacy (also used by Service Excellent, Marketing's VIP locks and
 * the cocok-bri asset). Always HTTP 200; a version conflict is ok:true with
 * data.conflict.
 */
class ReservasiLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(private readonly ReservasiState $state) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;

        return match ($req->action) {
            'getAll' => Envelope::okData($this->state->read() + ['_ver' => $this->state->ver()]),
            'getFile' => Envelope::okData($this->state->getFile($req->isPost()
                ? (is_array($b['data'] ?? null) && isset($b['data']['key']) ? $b['data']['key'] : ($b['key'] ?? null))
                : $req->http->query('key'))),
            'stats' => Envelope::okData($this->state->stats()),
            'ping' => Envelope::okData(['pong' => true, 'backend' => 'laravel', 'ts' => gmdate('c')]),
            'saveAll' => Envelope::okData($this->state->saveAll($b['data'] ?? null, $b['baseVer'] ?? null)),
            'putFile' => Envelope::okData($this->state->putFile($b['data'] ?? null)),
            default => Envelope::error('Aksi tidak dikenal: '.$req->action),
        };
    }
}
