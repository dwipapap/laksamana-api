<?php

namespace App\Modules\Bd\Http\Legacy;

use App\Modules\Bd\Services\BdState;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for bd-mysql/api.php — `/bd-api-mysql/api.php`. All actions open
 * (legacy had no session gate). addPo is posted by the Marketing page and
 * setRealisasi by Finance → Kas Kecil; radar and kompas read getAll.
 */
class BdLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(private readonly BdState $state) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;

        return match ($req->action) {
            'getAll' => Envelope::okData($this->state->read()),
            'stats' => Envelope::okData($this->state->stats()),
            'ping' => Envelope::okData(['pong' => true, 'backend' => 'laravel', ...$this->state->identity(), 'ts' => gmdate('c')]),
            'addPo' => Envelope::okData(array_intersect_key($this->state->addPo($b['items'] ?? null), ['added' => 1, 'ts' => 1])),
            'setRealisasi' => Envelope::okData($this->state->setRealisasi(
                $b['id'] ?? '', array_key_exists('realisasi', $b) ? $b['realisasi'] : '', $b['oleh'] ?? '')),
            'saveAll' => Envelope::okData($this->state->saveAll($b['data'] ?? null, isset($b['sinceTs']) ? (int) $b['sinceTs'] : 0)),
            default => Envelope::error('Aksi tidak dikenal: '.$req->action),
        };
    }
}
