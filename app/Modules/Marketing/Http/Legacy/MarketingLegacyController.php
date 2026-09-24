<?php

namespace App\Modules\Marketing\Http\Legacy;

use App\Modules\Marketing\Services\MarketingFiles;
use App\Modules\Marketing\Services\MarketingQueries;
use App\Modules\Marketing\Services\MarketingState;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for marketing-mysql/api.php — `/marketing-api-mysql/api.php`.
 * All actions are open (legacy had no session gate; see docs/security-followups.md).
 * saveAll keeps ok:true even with conflicts — the refused rows are in data.bentrok.
 */
class MarketingLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly MarketingState $state,
        private readonly MarketingQueries $queries,
        private readonly MarketingFiles $files,
    ) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        // A body dropped by post_max_size arrives empty although the browser sent one.
        if ($req->isPost() && $req->http->getContent() === '' && (int) $req->http->server('CONTENT_LENGTH', 0) > 0) {
            return Envelope::error('kiriman '.round(((int) $req->http->server('CONTENT_LENGTH')) / 1048576, 1).'MB dibuang server: '
                .'melewati post_max_size ('.ini_get('post_max_size').'). '
                .'Naikkan post_max_size & upload_max_filesize di .user.ini / php.ini folder API ini.');
        }
        $b = $req->body;
        $q = fn (string $k) => (string) $req->http->query($k, '');

        return match ($req->action) {
            'getAll' => Envelope::okData($this->state->read()),
            'stats' => Envelope::okData($this->queries->stats()),
            'ping' => Envelope::okData(array_merge(['pong' => true, 'backend' => 'laravel'], $this->queries->identity(), ['ts' => gmdate('c')])),
            'eventsHari' => Envelope::okData($this->queries->eventsOn($q('tgl'))),
            'dpMasuk' => Envelope::okData($this->queries->dpIn($q('dari'), $q('sampai'))),
            'designReqs' => Envelope::okData($this->queries->designRequests($q('aktif') === '1')),
            'designReq' => Envelope::okData($this->queries->designRequest($q('id'))),
            'designReqOpsi' => Envelope::okData($this->queries->setDesignOptions($b['opsi'] ?? null)),
            'designReqSet' => Envelope::okData($this->queries->setDesignStatus($b['id'] ?? '', $b['status'] ?? '', $b['picNama'] ?? '')),
            'receipt' => $this->files->stream($q('key')),
            'uploadReceipt' => Envelope::okData($this->files->save($b)),
            'uploadChunk' => Envelope::okData($this->files->saveChunk($b)),
            'saveAll' => Envelope::okData($this->state->saveAll($b['data'] ?? null)),
            default => Envelope::error('Aksi tidak dikenal: '.$req->action),
        };
    }
}
