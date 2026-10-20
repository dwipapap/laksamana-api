<?php

namespace App\Modules\Account\Services;

use App\Auth\OfficeAccess;
use App\Modules\Absensi\Services\AbsensiService;
use App\Modules\Dw\Services\DwService;
use App\Modules\Event\Services\EventSchema;
use App\Modules\Jadwal\Services\JadwalService;
use App\Modules\Konten\Services\KontenSchema;
use App\Modules\Marketing\Services\MarketingSchema;
use App\Modules\Reservasi\Services\ReservasiRecords;
use App\Support\Modules;
use DateTimeImmutable;
use DateTimeZone;

/**
 * GET /me/badges (api#311): the red menu counts of the Beranda
 * "Perlu tindakan" list in ONE call, so the home screen no longer opens
 * 8–15 full states just for numbers.
 *
 * Every rule is a twin of the client's own lencana() (app/utils/modul-shell.ts
 * + the module's badge composable): a row here is exactly a badge the viewer
 * would see in that module's sidebar. Only modules the caller may open appear
 * (OfficeAccess::modules); counts are raw numbers, zeros included — the
 * client filters `n > 0` for display. READ-ONLY: not one write.
 */
final class BadgesService
{
    public function __construct(
        private readonly OfficeAccess $access,
        private readonly ReservasiRecords $reservasi,
        private readonly AbsensiService $absensi,
        private readonly JadwalService $jadwal,
        private readonly DwService $dw,
    ) {}

    /** @return array<string, array<string, int>> {modul: {layar: n}} */
    public function forUser(string $userId): array
    {
        $mods = $this->access->modules($userId);
        $boleh = fn (string $m) => in_array($m, $mods, true);
        $out = [];

        if ($boleh('absensi')) {
            // Twin of useLencanaAbsensi(): the queue count, HR only.
            $hr = $this->access->isModuleAdmin($userId, 'absensi')
                || in_array($userId, (array) ($this->absensi->setting()['hr'] ?? []), true);
            $out['absensi'] = ['antrean' => $hr ? count($this->absensi->queue()) : 0];
        }
        if ($boleh('reservasi') || $boleh('service_excellent')) {
            // Twin of useLencanaReservasi(): today's bookings still Pending.
            $hari = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            $n = 0;
            foreach ($this->reservasi->list($hari, $hari) as $r) {
                if (($r['status'] ?? null) === 'Pending') {
                    $n++;
                }
            }
            $out['reservasi'] = ['dashboard' => $n, 'lonceng' => $n];
        }
        if ($boleh('marketing')) {
            // Twin of useLencanaMkt(): unread notifications.
            $n = 0;
            foreach ($this->rows('marketing', MarketingSchema::table('notifs')) as $r) {
                if (empty($r['read'])) {
                    $n++;
                }
            }
            $out['marketing'] = ['lonceng' => $n];
        }
        if ($boleh('konten')) {
            // Twin of useLencanaKonten(): items waiting for approval.
            $n = 0;
            foreach ($this->rows('konten', KontenSchema::table('content')) as $r) {
                if (($r['status'] ?? null) === 'Approval') {
                    $n++;
                }
            }
            $out['konten'] = ['approval' => $n];
        }
        if ($boleh('event')) {
            // Twin of useLencanaEvent(): plans waiting for a decision +
            // pending refunds (statusPengajuan() === 'Menunggu' means
            // pengajuan.status is Menunggu).
            $n = 0;
            foreach ($this->rows('event', EventSchema::table('events')) as $r) {
                $aju = (is_array($r['pengajuan'] ?? null) ? ($r['pengajuan']['status'] ?? null) : null)
                    ?? 'Belum diajukan';
                if ($aju === 'Menunggu') {
                    $n++;
                }
            }
            foreach ($this->rows('event', EventSchema::table('refunds')) as $r) {
                if (($r['status'] ?? '') === 'Pending') {
                    $n++;
                }
            }
            $out['event'] = ['approval' => $n, 'lonceng' => $n];
        }
        if ($boleh('jadwal')) {
            $out['jadwal'] = ['pengajuan' => $this->jadwalTerputus($userId)];
        }
        if ($boleh('dw')) {
            $out['dw'] = $this->dwLencana($userId);
        }

        return $out;
    }

    /**
     * Twin of useLencanaJadwal(): requests still running that this person can
     * decide now — head of the requester's Divisi at step 1, module admin
     * (HRD) at step 2 (bisaPutusHead / bisaPutusHRD, app/utils/jadwal.ts).
     * A requester outside every known Divisi counts as no Divisi (the client
     * resolves their Divisi to null, which never passes step 1).
     */
    private function jadwalTerputus(string $userId): int
    {
        $admin = $this->access->isModuleAdmin($userId, 'jadwal');
        $me = ['id' => $userId];
        $n = 0;
        foreach ($this->jadwal->listRequests([]) as $a) {
            $st = (string) ($a['status'] ?? '');
            if ($st !== 'MENUNGGU' && $st !== 'MENUNGGU_HRD') {
                continue;
            }
            // Step 2 first: it needs no Divisi lookup.
            if ($st === 'MENUNGGU_HRD' && $admin) {
                $n++;

                continue;
            }
            if ($st !== 'MENUNGGU') {
                continue;
            }
            $div = $this->jadwal->divisiUser((string) ($a['userId'] ?? ''));
            if ($div === '' || $div === 'nonshift') {
                continue;
            }
            if ($this->jadwal->isHead($me, $div)
                || ($admin && ! $this->jadwal->divHasHead($div))) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Twins of useLencanaDw(): the HRD desk (antrean) and the viewer's own
     * division requests. antreanPerluDikerjakanDw() counts MENUNGGU assignments
     * plus requests still needing work (mintaBelumTuntas: MENUNGGU, or
     * DISETUJUI but staffed below jumlah); permintaan counts MENUNGGU requests
     * in the viewer's divisions (divisiKuasaDw: HRD sees all).
     *
     * @return array{antrean: int, permintaan: int}
     */
    private function dwLencana(string $userId): array
    {
        $rows = $this->dw->badgeRows();
        $peran = $this->dw->peran($this->access->profile($this->access->userById($userId) ?? ['id' => $userId]));
        $hrd = ! empty($peran['hrd']);
        $kuasa = $hrd ? null : (is_array($peran['divisi'] ?? null) ? array_map('strval', $peran['divisi']) : []);

        $penuhi = [];
        foreach ($rows['ajuan'] as $a) {
            if (($a['status'] === 'MENUNGGU' || $a['status'] === 'DISETUJUI') && $a['permintaanId'] !== '') {
                $penuhi[$a['permintaanId']] = ($penuhi[$a['permintaanId']] ?? 0) + 1;
            }
        }
        $belumTuntas = function (array $x) use ($penuhi): bool {
            if ($x['status'] === 'MENUNGGU') {
                return true;
            }
            if ($x['status'] !== 'DISETUJUI') {
                return false;
            }

            return ($penuhi[$x['id']] ?? 0) < max(1, $x['jumlah'] ?: 1);
        };

        $antrean = 0;
        foreach ($rows['ajuan'] as $a) {
            if ($a['status'] === 'MENUNGGU') {
                $antrean++;
            }
        }
        $permintaan = 0;
        foreach ($rows['permintaan'] as $x) {
            if ($belumTuntas($x)) {
                $antrean++;
            }
            if ($x['status'] === 'MENUNGGU' && ($kuasa === null || in_array($x['divisi'], $kuasa, true))) {
                $permintaan++;
            }
        }

        return ['antrean' => $antrean, 'permintaan' => $permintaan];
    }

    /** Decoded `data` rows of one legacy table (legacy + core names via Schema::table). */
    private function rows(string $modul, string $table): array
    {
        $out = [];
        foreach (Modules::db($modul)->select("SELECT `data` FROM `{$table}`") as $row) {
            $r = json_decode((string) $row->data, true);
            if (is_array($r)) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
