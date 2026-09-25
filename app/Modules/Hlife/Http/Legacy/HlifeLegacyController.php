<?php

namespace App\Modules\Hlife\Http\Legacy;

use App\Modules\Hlife\Services\HlifeState;
use App\Support\Legacy\Envelope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stand-in for howandi-life-mysql/api.php — `/howandi-life-api-mysql/api.php`.
 *
 * Unlike most legacy modules this one answers with real HTTP status codes,
 * checks its OWN token (config laksamana.hlife_api_token, the value embedded
 * in the frontend HTML — not a secret) and hides exception messages behind
 * "kesalahan server". So it does not use LegacyController's shared plumbing.
 *
 * The POST body is decoded WITHOUT assoc so `{}` survives the round trip.
 */
class HlifeLegacyController
{
    public function __construct(private readonly HlifeState $state) {}

    public function __invoke(Request $request): Response
    {
        try {
            $res = $this->handle($request);
        } catch (Throwable $e) {
            report($e);
            $res = self::fail('kesalahan server', 500);
        }
        $res->headers->set('Cache-Control', 'no-store');

        return $res;
    }

    private function handle(Request $request): Response
    {
        $action = $request->query->all()['action'] ?? '';

        if ($request->isMethod('GET')) {
            if ($action === 'ping') {
                return Envelope::okData(['time' => gmdate('c')]);
            }
            if ($bad = $this->checkToken($request, null)) {
                return $bad;
            }

            return match ($action) {
                'stats' => Envelope::okData($this->state->stats()),
                'getAll' => Envelope::okData($this->state->read()),
                default => self::fail('action tidak dikenal', 400),
            };
        }

        if ($request->isMethod('POST')) {
            $body = json_decode((string) $request->getContent());
            if (! is_object($body)) {
                return self::fail('body bukan JSON', 400);
            }
            if ($bad = $this->checkToken($request, $body)) {
                return $bad;
            }
            if (($body->action ?? $action) !== 'saveAll') {
                return self::fail('action tidak dikenal', 400);
            }
            $hasil = $this->state->saveAll($body->data ?? null);
            if (empty($hasil['ok'])) {
                return self::fail($hasil['error'] ?? 'gagal simpan', 400);
            }

            // The frontend discards the reply, so it stays tiny.
            return Envelope::okData(['saved' => true]);
        }

        return self::fail('metode tidak didukung', 405);
    }

    /** cek_token — `?token=` wins over body.token; empty configured token = open. */
    private function checkToken(Request $request, ?object $body): ?Response
    {
        $expected = (string) config('laksamana.hlife_api_token', '');
        if ($expected === '') {
            return null;
        }
        $t = $request->query->has('token') ? $request->query->all()['token'] : ($body->token ?? '');
        if (! is_string($t) || ! hash_equals($expected, $t)) {
            return self::fail('token salah', 403);
        }

        return null;
    }

    private static function fail(string $msg, int $status): Response
    {
        return Envelope::error($msg, $status);
    }
}
