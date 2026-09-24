<?php

namespace App\Support\Legacy;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Base for every compat controller that stands in for an old
 * `<modul>-mysql/api.php`. Subclasses implement dispatch() — a switch on
 * $req->action that calls the module's Services (the same ones v1 uses) and
 * returns the legacy envelope.
 *
 * Shared legacy behaviour handled here:
 *  - optional shared API_TOKEN (?token= / body.token), empty = open
 *  - any uncaught exception -> {ok:false,error:<message>} with HTTP 200, like
 *    `catch (Throwable $e) keluar(['ok'=>false,'error'=>$e->getMessage()])`.
 *    Messages of the rejection family (sesi_tidak_sah:, tanpa_modul:,
 *    tidak_berhak:) pass through verbatim; frontends branch on them.
 */
abstract class LegacyController
{
    /** Action used for a GET without ?action= (legacy default differs per module). */
    protected string $defaultGetAction = 'ping';

    /** finance-api reads ?action= before the body. */
    protected bool $actionFromQueryFirst = false;

    abstract protected function dispatch(LegacyRequest $req): Response;

    public function __invoke(Request $request): Response
    {
        $req = LegacyRequest::from($request, $this->defaultGetAction, $this->actionFromQueryFirst);

        $token = (string) config('laksamana.legacy_api_token', '');
        if ($token !== '' && ! hash_equals($token, (string) $req->input('token', ''))) {
            return $this->tokenRejected();
        }

        try {
            return $this->dispatch($req);
        } catch (Throwable $e) {
            report($e);

            return $this->failure($e);
        }
    }

    protected function tokenRejected(): Response
    {
        return Envelope::error('token salah');
    }

    /** Legacy modules echoed $e->getMessage(). Hide driver internals of DB errors outside debug. */
    protected function failure(Throwable $e): Response
    {
        $msg = $e->getMessage();
        if ($e instanceof QueryException && ! config('app.debug')) {
            Log::warning('legacy db error', ['msg' => $msg]);
            $msg = 'kesalahan database';
        }

        return Envelope::error($msg);
    }

    protected function unknownAction(): Response
    {
        return Envelope::error('Aksi tidak dikenal: '.request()->input('action', ''));
    }
}
