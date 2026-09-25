<?php

namespace App\Modules\Hr\Http\Legacy;

use App\Modules\Hr\Services\HrState;
use App\Support\Legacy\Envelope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stand-in for hr-mysql/api.php — `/hr-api-mysql/api.php`.
 *
 * Real HTTP status codes (jawab()), the optional token only from ?token=,
 * a conflict answered as HTTP 200 {ok:false,error:'conflict',savedBy,savedAt,rev},
 * and every failure hidden behind "kesalahan server". The body is decoded
 * WITHOUT assoc so empty maps stay `{}`.
 *
 * PII: employees carry personal data and PINs. Failures are logged by class
 * and SQLSTATE only — never the message (a QueryException message embeds the
 * bound values) and never the payload.
 */
class HrLegacyController
{
    public function __construct(private readonly HrState $state) {}

    public function __invoke(Request $request): Response
    {
        try {
            $res = $this->handle($request);
        } catch (Throwable $e) {
            Log::error('[hr-api] '.get_class($e).($e instanceof QueryException ? ' SQLSTATE '.$e->getCode() : ''));
            $res = Envelope::error('kesalahan server', 500);
        }
        $res->headers->set('Cache-Control', 'no-store');

        return $res;
    }

    private function handle(Request $request): Response
    {
        $query = $request->query->all();
        $token = (string) config('laksamana.legacy_api_token', '');
        if ($token !== '' && ! hash_equals($token, HrState::s($query['token'] ?? ''))) {
            return Envelope::error('token salah', 403);
        }
        $action = $query['action'] ?? '';

        if ($request->isMethod('GET')) {
            return match ($action) {
                'ping' => Envelope::okData(['time' => gmdate('c')]),
                'stats' => Envelope::okData($this->state->stats()),
                'getAll' => Envelope::okData($this->state->read()),
                default => Envelope::error('action tidak dikenal', 400),
            };
        }

        if ($request->isMethod('POST')) {
            $body = json_decode((string) $request->getContent());
            if (! is_object($body)) {
                return Envelope::error('body bukan JSON', 400);
            }
            if (($body->action ?? $action) !== 'saveAll') {
                return Envelope::error('action tidak dikenal', 400);
            }
            $hasil = $this->state->saveAll($body->data ?? null, property_exists($body, 'baseRev') ? $body->baseRev : null, $body->by ?? '');

            if (! empty($hasil['ok'])) {
                return Envelope::okData(['rev' => $hasil['rev']]);
            }
            if (($hasil['error'] ?? '') === 'conflict') {
                // A legitimate outcome, not a transport error: HTTP 200.
                return Envelope::json(['ok' => false, 'error' => 'conflict', 'savedBy' => $hasil['savedBy'], 'savedAt' => $hasil['savedAt'], 'rev' => $hasil['rev']]);
            }

            return Envelope::error($hasil['error'] ?? 'gagal simpan', 400);
        }

        return Envelope::error('metode tidak didukung', 405);
    }
}
