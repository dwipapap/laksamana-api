<?php

namespace App\Modules\Akademi\Http\Legacy;

use App\Modules\Akademi\Services\AkademiFiles;
use App\Modules\Akademi\Services\AkademiState;
use App\Modules\Akademi\Services\AkademiStats;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for akademi-mysql/api.php — `/akademi-api-mysql/api.php`.
 * All actions are open (legacy had no session gate, only the optional API_TOKEN).
 * saveAll keeps ok:true even with conflicts — the refused rows are in data.bentrok.
 * trainingStats is read by the hr module (Staff Performance); same open read here.
 */
class AkademiLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly AkademiState $state,
        private readonly AkademiStats $stats,
        private readonly AkademiFiles $files,
    ) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $q = fn (string $k) => (string) $req->http->query($k, '');

        return match ($req->action) {
            'getAll' => Envelope::okData($this->state->read()),
            'stats' => Envelope::okData($this->stats->stats()),
            'trainingStats' => Envelope::okData($this->stats->trainingStats()),
            'ping' => Envelope::okData(['pong' => true, 'backend' => 'laravel', 'ts' => gmdate('c')]),
            'receipt' => $this->files->stream($q('key')),
            'uploadReceipt' => Envelope::okData($this->files->save($b)),
            'saveAll' => Envelope::okData($this->state->saveAll($b['data'] ?? null)),
            default => Envelope::error('Aksi tidak dikenal: '.$req->action),
        };
    }
}
