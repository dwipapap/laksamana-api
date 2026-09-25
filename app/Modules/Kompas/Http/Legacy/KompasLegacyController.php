<?php

namespace App\Modules\Kompas\Http\Legacy;

use App\Modules\Kompas\Services\KompasState;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\Legacy\Sesi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for kompas-mysql/api.php — `/kompas-api-mysql/api.php`.
 *
 * Ported so far (#28): getAll (adds a top-level `ts`), saveAll (stale-write
 * guard, `konflik` reply), simpanTarget, simpanRekap, omsetPic, performaDivisi
 * (session + the division's module), ping, stats. Everything else (investor,
 * analytics, void, BRI) answers "Aksi tidak dikenal" until its issue lands.
 * The blob actions stay open, as legacy.
 */
class KompasLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(private readonly KompasState $kompas) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $q = fn (string $k) => $req->http->query($k);

        switch ($req->action) {
            case 'getAll':
                return Envelope::json(['ok' => true, 'data' => $this->kompas->read(), 'ts' => $this->kompas->ts()]);

            case 'omsetPic':
                return Envelope::okData($this->kompas->omsetPic($q('dari') ?? '', $q('sampai') ?? ''));

            case 'stats':
                return Envelope::okData($this->kompas->stats());

            case 'ping':
                return Envelope::okData(['pong' => true, 'backend' => 'laravel', ...$this->kompas->identity(), 'ts' => gmdate('c')]);

            case 'saveAll':
                $out = $this->kompas->saveAll($b['data'] ?? null, isset($b['baseTs']) ? (int) $b['baseTs'] : null);
                if (! empty($out['konflik'])) {
                    // ok:false WITH its own flag: a conflict must not be retried, a network error must
                    return Envelope::json(['ok' => false, 'konflik' => true, 'ts' => $out['ts'], 'by' => $out['by'],
                        'error' => 'Data di server sudah diubah orang lain sejak halaman ini dimuat. Penyimpanan ditolak supaya perubahan mereka tidak ikut terhapus.']);
                }

                return Envelope::okData($out);

            case 'simpanTarget':
                return Envelope::okData($this->kompas->saveTarget($b['data'] ?? null));

            case 'simpanRekap':
                return Envelope::okData($this->kompas->saveRekap($b['data'] ?? null));

            case 'performaDivisi':
                // per-PIC revenue: gated by the module of the division asked for
                $u = app(Sesi::class)->user($req);
                if (! $u) {
                    Sesi::rejectUnknown();
                }
                $divi = ($q('divi') ?? ($b['divi'] ?? 'marketing')) === 'event' ? 'event' : 'marketing';
                if (! Sesi::hasModule($u, $divi)) {
                    Sesi::rejectNoModule($divi === 'event' ? 'Event' : 'Marketing');
                }

                return Envelope::okData($this->kompas->performaDivisi($divi, $q('dari') ?? ($b['dari'] ?? ''), $q('sampai') ?? ($b['sampai'] ?? '')));

            default:
                return Envelope::error('Aksi tidak dikenal: '.$req->action);
        }
    }
}
