<?php

namespace App\Modules\Finance\Http\Legacy;

use App\Modules\Finance\Services\KasKecil;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stand-in for finance-mysql/api.php — `/finance-api-mysql/api.php`.
 *
 * `action` is read from the QUERY STRING first (then the body, else getAll —
 * for POST too). Every action is open, as legacy (security follow-up #6).
 * Errors keep ok:false + HTTP 200 with the cPanel hints of petunjuk_galat().
 *
 * Ported so far: Kas Kecil + Akses Halaman (#25). Brankas and the
 * invoice/kwitansi actions answer "Aksi tidak dikenal" until their issues land.
 */
class FinanceLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    protected bool $actionFromQueryFirst = true;

    public function __construct(private readonly KasKecil $kas) {}

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $id = $b['id'] ?? 0;

        return match ($req->action) {
            'getAll' => Envelope::okData($this->kas->read()),
            'simpanTrx' => Envelope::okData($this->kas->saveTrx($b)),
            'hapusTrx' => Envelope::okData($this->kas->deleteTrx($id)),
            'tandai' => Envelope::okData($this->kas->mark($id, isset($b['field']) ? KasKecil::s($b['field']) : '', ! empty($b['nilai']))),

            'simpanPos' => Envelope::okData($this->kas->saveListItem('kk_pos', $b)),
            'nonaktifPos' => Envelope::okData($this->kas->setActive('kk_pos', $id, false)),
            'aktifPos' => Envelope::okData($this->kas->setActive('kk_pos', $id, true)),
            'hapusPos' => Envelope::okData($this->kas->deleteListItem('kk_pos', $id)),

            'simpanKategori' => Envelope::okData($this->kas->saveListItem('kk_kategori', $b)),
            'nonaktifKategori' => Envelope::okData($this->kas->setActive('kk_kategori', $id, false)),
            'aktifKategori' => Envelope::okData($this->kas->setActive('kk_kategori', $id, true)),
            'hapusKategori' => Envelope::okData($this->kas->deleteListItem('kk_kategori', $id)),

            'simpanAkses' => Envelope::okData($this->kas->saveAkses($b['peta'] ?? null)),
            'simpanPeran' => Envelope::okData($this->kas->saveRole($b)),

            'ping' => Envelope::okData($this->kas->ping()),
            'stats' => Envelope::okData($this->kas->stats()),
            default => Envelope::error('Aksi tidak dikenal: '.$req->action),
        };
    }

    /** Legacy wraps every message in petunjuk_galat() (cPanel hints for 1044/1049/1045). */
    protected function failure(Throwable $e): Response
    {
        $res = parent::failure($e);
        $body = json_decode((string) $res->getContent(), true);

        return Envelope::error(KasKecil::hint((string) ($body['error'] ?? '')));
    }
}
