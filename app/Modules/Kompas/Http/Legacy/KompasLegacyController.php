<?php

namespace App\Modules\Kompas\Http\Legacy;

use App\Modules\Kompas\Services\KompasState;
use App\Modules\Kompas\Services\VoidBri;
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
 * (session + the division's module), ping, stats; void log and BRI matching (#29). Investor and
 * analytics answer "Aksi tidak dikenal" until their issue lands.
 * The blob actions stay open, as legacy.
 */
class KompasLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly KompasState $kompas,
        private readonly VoidBri $vb,
    ) {}

    private function sessionUser(LegacyRequest $req): array
    {
        $u = app(Sesi::class)->user($req);
        if (! $u) {
            Sesi::rejectUnknown();
        }

        return $u;
    }

    /** Legacy: ok → {ok:true, data:<result incl. ok>}; failure → the result array as is (kurang, gagal…). */
    private static function result(array $r): Response
    {
        return $r['ok'] ? Envelope::okData($r) : Envelope::json($r);
    }

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

                // ── Catatan Void & QRIS BRI (#29): reads open, writes need a session ──
            case 'voidList':
                return Envelope::okData($this->vb->voidList($q('dari') ?? ($b['dari'] ?? ''), $q('sampai') ?? ($b['sampai'] ?? '')));

            case 'briList':
                return Envelope::okData($this->vb->briList($q('dari') ?? ($b['dari'] ?? ''), $q('sampai') ?? ($b['sampai'] ?? '')));

            case 'voidSetting':
                // percentages move every later void total: module ADMIN of cashier or finance
                $u = $this->sessionUser($req);
                if (! Sesi::isModuleAdmin($u, 'cashier') && ! Sesi::isModuleAdmin($u, 'finance')) {
                    return Envelope::error('Hanya admin modul Cashier atau Finance yang boleh mengubah persen tax & service.');
                }

                return self::result($this->vb->saveVoidSetting($b['data'] ?? null, (string) ($u['name'] ?? '')));

            case 'voidSimpan':
            case 'voidBatal':
            case 'briUnggah':
            case 'briTambah':
            case 'briCocok':
            case 'briBatal':
            case 'briAbai':
                // the recorded name comes from the verified session, never from the body
                $u = $this->sessionUser($req);
                if (! Sesi::hasModule($u, 'cashier') && ! Sesi::hasModule($u, 'finance')) {
                    Sesi::rejectNoModule('Cashier atau Finance');
                }
                $nama = (string) ($u['name'] ?? '');
                $uid = (string) ($u['id'] ?? '');
                $dt = $b['data'] ?? null;

                return self::result(match ($req->action) {
                    'voidSimpan' => is_array($dt) && isset($dt['items']) && is_array($dt['items'])
                        ? $this->vb->saveVoidMany($dt, $nama, $uid) : $this->vb->saveVoid($dt, $nama, $uid),
                    'voidBatal' => $this->vb->cancelVoid($b['id'] ?? '', $b['alasan'] ?? '', $nama),
                    'briUnggah' => $this->vb->upload($dt, $nama, $uid),
                    'briTambah' => $this->vb->addManual($dt, $nama, $uid),
                    'briCocok' => $this->vb->match($dt, $nama),
                    'briAbai' => $this->vb->ignoreDp($dt, $nama),
                    default => $this->vb->cancelMutation($b['id'] ?? '', $b['alasan'] ?? '', $nama),
                });

            default:
                return Envelope::error('Aksi tidak dikenal: '.$req->action);
        }
    }
}
