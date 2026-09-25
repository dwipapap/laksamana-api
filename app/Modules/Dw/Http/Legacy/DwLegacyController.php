<?php

namespace App\Modules\Dw\Http\Legacy;

use App\Modules\Dw\Services\DwService;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\Legacy\Sesi;
use RuntimeException;
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
 * from the session). Clusters (1) and (2): reads, Pekerja Harian,
 * permintaan, ajuan, attendance (simpanHadir), replacement (gantiOrang),
 * payment ticks (tandaiBayar), settings (simpanSetting) and the full wipe
 * (kosongkanSemua).
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

    private function admin(LegacyRequest $req, string $apa): array
    {
        $u = $this->office($req);
        if (! DwService::isAdmin($u)) {
            Sesi::rejectForbidden($apa.' hanya bisa dilakukan admin modul Daily Worker.');
        }

        return $u;
    }

    /**
     * The division stored on the assignment row — the attendance gates read
     * it before deciding who may touch the shift. '' for a missing row: it
     * still passes the general gate, then the service reports it not found.
     */
    private function divisiAjuan(mixed $id): string
    {
        $a = $this->dw->ajuanById($id);

        return $a ? (string) $a['divisi'] : '';
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

            case 'simpanHadir':
                // Who may confirm is decided per ROW, from the division
                // stored on it — never from the request. A missing row still
                // passes the gate; the service answers "not found" (a
                // mistyped id is not an access problem).
                $u = $dw->assertMinta($this->office($req), $this->divisiAjuan($ambil('id')));

                return Envelope::okData($dw->saveHadir(
                    $ambil('id'), $ambil('hadir'),
                    $ambil('nota'), (string) $u['name']));

                // A replacement on location. Same gate as attendance: who finally
                // came is known by whoever was there.
            case 'gantiOrang':
                $u = $dw->assertMinta($this->office($req), $this->divisiAjuan($ambil('id')));

                return Envelope::okData($dw->gantiOrang(
                    $ambil('id'), $ambil('dwBaru'),
                    $ambil('nota'), (string) $u['name']));

                // The "already transferred" tick — HRD, not admin, because this
                // is pressed dozens of times while doing transfers. Its own path
                // (not simpanSetting) so two people ticking at once never
                // overwrite each other's tariffs and quotas.
            case 'tandaiBayar':
                $u = $this->hrd($req, 'Menandai pembayaran');

                return Envelope::okData($dw->tandaiBayar(
                    $ambil('senin'), $ambil('kunci'),
                    ! empty($b['nyala']), (string) $u['name']));

            case 'simpanSetting':
                // HRD sets tariffs, quotas, default hours and position maps —
                // that is their job. What does not follow: the HRD list
                // itself, kept from the stored value unless the caller is a
                // module admin (see DwService::saveSetting).
                $u = $this->hrd($req, 'Mengubah pengaturan modul');

                return Envelope::okData($dw->saveSetting(
                    $ambil('data', null), (string) $u['name'],
                    DwService::isAdmin($u)));

                // Emptying every request & assignment so the module can start
                // from zero. MODULE ADMIN only — not HRD. Approving shifts is
                // HRD's daily job; deleting all of history is not, and a button
                // sharing a page with tariffs must not be pressable by everyone
                // allowed to set tariffs.
                //
                // `pekerja` (talent pool) only follows on explicit request. The
                // confirmation keyword is checked again here so a stray API call
                // — never passing the screen and its modal — deletes nothing.
            case 'kosongkanSemua':
                $u = $this->admin($req, 'Mengosongkan seluruh data modul');
                if (trim((string) $ambil('konfirmasi', '')) !== 'HAPUS SEMUA') {
                    throw new RuntimeException('Konfirmasi tidak cocok — pengosongan dibatalkan.');
                }

                return Envelope::okData($dw->kosongkanSemua(
                    ! empty($b['pekerja']), (string) $u['name']));

            default:
                return Envelope::error('Aksi tidak dikenal: '.$req->action);
        }
    }
}
