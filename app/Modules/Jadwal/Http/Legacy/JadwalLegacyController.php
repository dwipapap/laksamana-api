<?php

namespace App\Modules\Jadwal\Http\Legacy;

use App\Modules\Jadwal\Services\HeadDirectory;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\Legacy\Sesi;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for jadwal-mysql/api.php — `/jadwal-api-mysql/api.php`.
 * Every write carries `sesi`; `by` from the client is ignored (names come
 * from the session). Open on purpose: shiftHari (absensi), headIds (account,
 * dw), ping, stats, probeTulis (CI deploy check).
 */
class JadwalLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly HeadDirectory $heads,
        private readonly Sesi $sesi,
    ) {}

    private function office(LegacyRequest $req): array
    {
        return $this->sesi->requireModule($req, 'jadwal', 'Roster · Jadwal Shift');
    }

    private function admin(LegacyRequest $req, string $apa): array
    {
        $u = $this->office($req);
        if (! JadwalService::isAdmin($u)) {
            Sesi::rejectForbidden($apa.' hanya bisa dilakukan admin modul Jadwal Shift.');
        }

        return $u;
    }

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $j = $this->jadwal;
        $q = fn (string $k) => $req->http->query($k, $b[$k] ?? '');

        switch ($req->action) {
            case 'getAll':
                $this->office($req);

                return Envelope::okData($j->readAll((string) $req->http->query('dari', ''), (string) $req->http->query('sampai', '')));

            case 'shiftHari':
                return Envelope::okData($j->shiftRange((string) $q('user'), (string) $q('dari'), (string) $q('sampai')));

            case 'headIds':
                return Envelope::okData(['heads' => $this->heads->compute()]); // PHP: empty => [] (kept)

            case 'stats':
                return Envelope::okData($j->stats());

            case 'ping':
                return Envelope::okData($j->ping());

            case 'probeTulis':
                return Envelope::okData($j->probe());

            case 'simpanSel':
                $u = $this->office($req);

                return Envelope::okData($j->saveCells($b['rows'] ?? [], $b['hapus'] ?? [], (string) $u['name'], $u));

            case 'simpanSetting':
                $u = $this->admin($req, 'Mengubah pengaturan modul');

                return Envelope::okData($j->saveSetting($b['data'] ?? null, (string) $u['name']));

            case 'simpanPengajuan':
                $u = $this->office($req);
                $row = (array) ($b['row'] ?? []);
                if (! JadwalService::isAdmin($u)) {
                    $row['userId'] = (string) $u['id'];   // crew may only request for themselves
                }

                return Envelope::okData($j->saveRequest($row, (string) $u['name']));

            case 'putusPengajuan':
                $u = $this->office($req);
                $a = $j->pengajuanById((string) ($b['id'] ?? ''));
                if (! $a) {
                    throw new RuntimeException('Pengajuan tidak ditemukan: '.($b['id'] ?? ''));
                }
                $j->assertMayWriteRow($u, (string) $a['user_id']);
                $divAju = $j->divisiUser((string) $a['user_id']);
                $isHead = $j->isHead($u, $divAju) || ! $j->divHasHead($divAju);

                return Envelope::okData($j->decide((string) ($b['id'] ?? ''), $b['status'] ?? '', $b['nota'] ?? '',
                    (string) $u['name'], JadwalService::isAdmin($u), $isHead));

            case 'hapusPengajuan':
                $u = $this->office($req);
                $a = $j->pengajuanById((string) ($b['id'] ?? ''));
                if ($a && (string) $a['user_id'] !== (string) $u['id']) {
                    $j->assertMayWriteRow($u, (string) $a['user_id']);
                }

                return Envelope::okData($j->deleteRequest((string) ($b['id'] ?? '')));

            case 'kosongkanSemua':
                $u = $this->admin($req, 'Mengosongkan seluruh data jadwal');
                if (trim((string) ($b['konfirmasi'] ?? '')) !== 'HAPUS SEMUA') {
                    throw new RuntimeException('Konfirmasi tidak cocok — pengosongan dibatalkan.');
                }

                return Envelope::okData($j->clearAll((string) $u['name']));

            default:
                return Envelope::error('Aksi tidak dikenal: '.$req->action);
        }
    }
}
