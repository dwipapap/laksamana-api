<?php

namespace App\Modules\Dw\Http\Legacy;

use App\Modules\Dw\Services\DwService;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\Legacy\Sesi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for dw-mysql/api.php — `/dw-api-mysql/api.php`.
 *
 * The whole module is HRD-only except three deliberate openings: ping/stats
 * (no data, used by the FTP verify step) and jadwalDW (read by the Jadwal
 * Shift screens of the entire crew and server-to-server by absensi; minimal
 * columns, no phone numbers). Closing jadwalDW would silently kill attendance
 * on both sites until someone hand-edits config.php in cPanel.
 *
 * Every write carries `sesi`; `by` from the client is ignored (names come
 * from the session). Cluster (1) only — simpanHadir, gantiOrang,
 * tandaiBayar, simpanSetting and kosongkanSemua arrive with #14 and answer
 * "unknown action" until then.
 */
class DwLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'getAll';

    public function __construct(
        private readonly DwService $dw,
        private readonly Sesi $sesi,
    ) {}

    /** Office staff holding the dw module ("Roster · Daily Worker"). */
    private function office(LegacyRequest $req): array
    {
        return $this->sesi->requireModule($req, 'dw', 'Roster · Daily Worker');
    }

    private function hrd(LegacyRequest $req, string $apa): array
    {
        return $this->dw->assertHrd($this->office($req), $apa);
    }

    /** Legacy pot(): mb_substr(trim((string)$v), 0, $n) — kept for the gates. */
    private static function pot(mixed $v, int $n): string
    {
        return mb_substr(trim((string) $v), 0, $n);
    }

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $dw = $this->dw;
        $ambil = fn (string $k, mixed $def = '') => $b[$k] ?? $def;

        switch ($req->action) {
            case 'getAll':
                // HRD and heads get the full pool; other staff are still
                // served — Dashboard and Kalender are open to them — but
                // without phone numbers or anyone's transfer details.
                $u = $this->office($req);
                $data = $dw->readAll(
                    (string) $req->http->query('dari', ''),
                    (string) $req->http->query('sampai', ''),
                    $dw->bolehLihat($u)
                );
                // Roles ride along so screens never re-derive them from
                // module lists and settings.
                $data['peran'] = $dw->peran($u);

                return Envelope::okData($data);

            case 'jadwalDW':
                return Envelope::okData($dw->scheduleRange(
                    (string) $req->http->query('dari', ''),
                    (string) $req->http->query('sampai', '')
                ));

            case 'stats':
                return Envelope::okData($dw->stats());

            case 'ping':
                return Envelope::okData($dw->ping());

            case 'simpanPekerja':
                $u = $this->hrd($req, 'Mengubah data daily worker');

                return Envelope::okData($dw->savePekerja($ambil('row', []), (string) $u['name']));

            case 'hapusPekerja':
                $this->hrd($req, 'Menghapus daily worker');

                return Envelope::okData($dw->deletePekerja($ambil('id')));

            case 'simpanAjuan':
                // Way 1 — a head points at the person directly for their own
                // division, or HRD schedules as usual. Always rows of
                // MENUNGGU: a head still cannot approve their own pick.
                $row = (array) $ambil('row', []);
                $u = $dw->assertMinta($this->office($req), self::pot($row['divisi'] ?? '', 16));

                return Envelope::okData($dw->saveAjuan($row, (string) $u['name']));

                // Way 2 — a head requests just the COUNT; HRD points at who.
            case 'simpanPermintaan':
                $row = (array) $ambil('row', []);
                $u = $dw->assertMinta($this->office($req), self::pot($row['divisi'] ?? '', 16));

                return Envelope::okData($dw->savePermintaan($row, (string) $u['name']));

            case 'putusPermintaan':
                // Approving/refusing is HRD-only. A head may CANCEL their own
                // request — taking back what they asked is not an HRD
                // decision, and forcing a phone call for it just piles up
                // ghost requests in the queue.
                $id = $ambil('id');
                $status = strtoupper(trim((string) $ambil('status')));
                $u = $this->office($req);
                if (! $dw->isHrd($u)) {
                    $pm = $dw->permintaanById($id);
                    $h = (isset($u['headDivisi']) && is_array($u['headDivisi'])) ? $u['headDivisi'] : [];
                    if ($status !== 'BATAL' || ! $pm || ! in_array($pm['divisi'], $h, true)) {
                        Sesi::rejectForbidden('Menyetujui atau menolak permintaan hanya bisa dilakukan HRD.');
                    }
                }

                return Envelope::okData($dw->decidePermintaan(
                    $id, $status, $ambil('nota'), (string) $u['name']));

            case 'tugaskanDW':
                // Pointing at the people — HRD only. The head knows how many
                // they need; HRD knows who is free and carries the money.
                $u = $this->hrd($req, 'Menugaskan daily worker');

                return Envelope::okData($dw->assignDw(
                    $ambil('permintaanId'), $ambil('dwIds', []), (string) $u['name']));

            case 'hapusPermintaan':
                $id = $ambil('id');
                $u = $this->office($req);
                if (! $dw->isHrd($u)) {
                    $pm = $dw->permintaanById($id);
                    $h = (isset($u['headDivisi']) && is_array($u['headDivisi'])) ? $u['headDivisi'] : [];
                    if (! $pm || ! in_array($pm['divisi'], $h, true)) {
                        Sesi::rejectForbidden('Permintaan ini bukan milik divisi Anda.');
                    }
                }

                return Envelope::okData($dw->deletePermintaan($id));

            case 'putusAjuan':
                // THE most expensive gate in this module: approvals stand on
                // the Jadwal calendar and count as money to transfer. HRD
                // only — the "DW may cancel their own" exception died with
                // their login gate.
                $u = $this->hrd($req, 'Memutuskan ajuan daily worker');

                return Envelope::okData($dw->decideAjuan(
                    $ambil('id'), $ambil('status'),
                    $ambil('nota'), (string) $u['name']));

            case 'putusBanyak':
                $u = $this->hrd($req, 'Memutuskan ajuan daily worker');

                return Envelope::okData($dw->decideBanyak(
                    $ambil('ids', []), $ambil('status'),
                    $ambil('nota'), (string) $u['name']));

            case 'hapusAjuan':
                $this->hrd($req, 'Menghapus ajuan');

                return Envelope::okData($dw->deleteAjuan($ambil('id')));

            default:
                return Envelope::error('Aksi tidak dikenal: '.$req->action);
        }
    }
}
