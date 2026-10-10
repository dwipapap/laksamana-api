<?php

namespace App\Modules\Kompas\Http\Legacy;

use App\Modules\Kompas\Services\InvestorAnalytics;
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
 * (session + the division's module), ping, stats; void log and BRI matching (#29);
 * investor and analytics (#30). Every legacy action is now served.
 * The blob actions stay open, as legacy.
 */
class KompasLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly KompasState $kompas,
        private readonly VoidBri $vb,
        private readonly InvestorAnalytics $inv,
    ) {}

    /** Session + module `investor` (the investor site's gate). */
    private function investor(LegacyRequest $req): array
    {
        $u = $this->sessionUser($req);
        if (! Sesi::hasModule($u, 'investor')) {
            Sesi::rejectNoModule('Investor Compass');
        }

        return $u;
    }

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

                // ── Investor Compass (#30): session + module `investor` ──
            case 'investorRingkas':
                $u = $this->investor($req);

                return Envelope::json(['ok' => true, 'data' => $this->inv->summary($u, Sesi::isModuleAdmin($u, 'investor')),
                    'user' => ['nama' => $u['name'] ?? '', 'bolehUnggah' => Sesi::isModuleAdmin($u, 'investor')]]);

            case 'investorAgenda':
                $this->investor($req);

                return Envelope::okData($this->inv->agenda());

            case 'investorLaporUpload':
                $u = $this->investor($req);
                if (! Sesi::isModuleAdmin($u, 'investor')) {
                    return Envelope::error('Hanya admin modul Investor yang boleh mengunggah laporan.');
                }
                $berkas = isset($b['berkas']) && is_array($b['berkas']) ? $b['berkas'] : [];
                if (! count($berkas)) {
                    return Envelope::error('Tidak ada berkas yang dikirim.');
                }
                // ONE upload for both reports: what succeeded is kept, what failed is named
                $hasil = [];
                $galat = [];
                foreach ($berkas as $jenis => $p) {
                    $r = $this->inv->saveReport($b['bulan'] ?? '', $jenis, $p, (string) ($u['name'] ?? ''));
                    if (! empty($r['ok'])) {
                        $hasil[] = $jenis;
                    } else {
                        $galat[] = $jenis.': '.($r['error'] ?? 'gagal');
                    }
                }

                return Envelope::json(['ok' => count($hasil) > 0, 'data' => ['tersimpan' => $hasil, 'galat' => $galat, 'lapor' => $this->inv->reports()],
                    'error' => count($hasil) ? null : implode('; ', $galat)]);

            case 'investorLaporHapus':
                $u = $this->sessionUser($req);
                if (! Sesi::isModuleAdmin($u, 'investor')) {
                    return Envelope::error('Hanya admin modul Investor yang boleh menghapus laporan.');
                }
                $this->inv->deleteReport($b['bulan'] ?? '', $b['jenis'] ?? '');

                return Envelope::okData(['lapor' => $this->inv->reports()]);

            case 'investorLaporFile':
                // binary, not base64-in-JSON; POST so the token is not in URLs or logs
                $this->investor($req);
                $f = $this->inv->reportFile($b['bulan'] ?? '', $b['jenis'] ?? '');
                if (is_string($f)) {
                    return new \Illuminate\Http\Response(json_encode(['ok' => false, 'error' => $f]), 404, ['Content-Type' => 'application/json']);
                }

                return response()->file($f['path'], [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['nama']).'"',
                    'Cache-Control' => 'private, max-age=0, no-store',
                ]);

                // ── Analytics (#30): open, as legacy; the `analytics` module gates the pages ──
            case 'analyticsGet':
                return Envelope::okData($this->inv->analytics());

            case 'analyticsSave':
                return self::result($this->inv->saveAnalytics($b['data'] ?? null, $b['oleh'] ?? ''));

            case 'analyticsAkses':
                return self::result($this->inv->saveAnalyticsAccess($b['akses'] ?? null));

            case 'analyticsPeran':
                return self::result($this->inv->saveAnalyticsRoles($b['peran'] ?? null));

            default:
                return Envelope::error('Aksi tidak dikenal: '.$req->action);
        }
    }
}
