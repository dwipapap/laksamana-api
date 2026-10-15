<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Bd\Services\BdConflict;
use App\Modules\Bd\Services\BdRecords;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;

/**
 * Inter-division requests (R6): HRIS screens for every Divisi, but the data
 * lives in BD Permintaan Koordinasi and is read/written ONLY through
 * BdRecords — same lock, same RowSync, same version guard as the BD panel
 * (a stale version is a 409 with the live record, like BD itself).
 *
 * HRIS authorization on top: anyone may create from their division; the
 * Kepala Divisi of the TARGET division assigns; status moves
 * Diminta → Diproses → Review → Selesai (forward only). Field names follow
 * the BD app's own vocabulary (title, fromDiv, toDiv, by, to, status,
 * priority, due, project) so both panels read the same rows.
 */
final class HrisCoordinationService
{
    public const STATUS = ['Diminta', 'Diproses', 'Review', 'Selesai'];

    public function __construct(
        private readonly BdRecords $bd,
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
        private readonly AccountRepository $users,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ---------------------------------------------------------------- reads

    /** @param  array{id:string,name:string}  $me */
    public function list(array $me, ?string $status, ?string $divisi): array
    {
        $rows = $this->bd->list('coord-requests');
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            if ($status !== null && $status !== '' && (string) ($r['status'] ?? '') !== $status) {
                continue;
            }
            if ($divisi !== null && $divisi !== ''
                && (string) ($r['fromDiv'] ?? '') !== $divisi && (string) ($r['toDiv'] ?? '') !== $divisi) {
                continue;
            }
            if (! $this->bolehLihat($me, $r)) {
                continue;
            }
            $out[] = $this->bentuk($r);
        }

        return $out;
    }

    /** @param  array{id:string,name:string}  $me */
    public function show(array $me, string $id): array
    {
        $found = $this->bd->find('coord-requests', self::s($id));
        if (! $found) {
            throw new HrisError('not_found', 'Tiket tidak ada.', 404);
        }
        if (! $this->bolehLihat($me, $found['row'])) {
            throw new HrisError('forbidden', 'Tidak berhak melihat tiket ini.', 403);
        }

        return $this->bentuk($found['row'], $found['version']);
    }

    /** @param  array{id:string,name:string}  $me */
    private function bolehLihat(array $me, array $r): bool
    {
        if ($this->lingkup->isHr($me)) {
            return true;
        }
        if ((string) ($r['hrisById'] ?? '') !== '' && (string) $r['hrisById'] === self::s($me['id'])) {
            return true;
        }
        $divs = $this->lingkup->isHead($me) ? $this->lingkup->headDivisi($me) : [$this->divisiSendiri($me)];
        $dari = (string) ($r['fromDiv'] ?? '');
        $ke = (string) ($r['toDiv'] ?? '');

        return in_array($dari, $divs, true) || in_array($ke, $divs, true);
    }

    private function divisiSendiri(array $me): string
    {
        try {
            return $this->jadwal->divisiUser(self::s($me['id']));
        } catch (\Throwable) {
            return Divisi::NONSHIFT;
        }
    }

    /** Public projection (assignee id resolved to a name, best-effort). */
    public function bentuk(array $r, ?int $version = null): array
    {
        $to = (string) ($r['to'] ?? '');

        return [
            'id' => (string) ($r['id'] ?? ''), 'judul' => (string) ($r['title'] ?? ''),
            'dariDivisi' => (string) ($r['fromDiv'] ?? ''), 'keDivisi' => (string) ($r['toDiv'] ?? ''),
            'pemohon' => (string) ($r['by'] ?? ''), 'pelaksana' => $to,
            'pelaksana_nama' => $this->namaUser($to),
            'status' => (string) ($r['status'] ?? ''), 'prioritas' => (string) ($r['priority'] ?? ''),
            'tenggat' => (string) ($r['due'] ?? ''), 'proyek' => (string) ($r['project'] ?? ''),
            'detail' => (string) ($r['detail'] ?? ''),
            'berubah' => $version,
        ];
    }

    private function namaUser(string $id): string
    {
        if ($id === '') {
            return '';
        }
        try {
            $u = $this->users->userById($id);
        } catch (\Throwable) {
            return $id;
        }

        return $u ? (string) ($u['name'] ?? $id) : $id;
    }

    // ---------------------------------------------------------------- writes

    /** @param  array{id:string,name:string}  $me */
    public function buat(array $me, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        $judul = self::s($in['judul'] ?? $in['title'] ?? '');
        if ($judul === '') {
            throw new HrisError('validation_failed', 'Judul tiket wajib diisi.', 422);
        }
        $ke = self::s($in['keDivisi'] ?? $in['toDiv'] ?? '');
        if ($ke === '') {
            throw new HrisError('validation_failed', 'Divisi tujuan wajib diisi.', 422);
        }
        $tenggat = self::s($in['tenggat'] ?? $in['due'] ?? '');
        if ($tenggat !== '' && PunchRules::tglValid($tenggat) === '') {
            throw new HrisError('validation_failed', 'Tenggat harus YYYY-MM-DD.', 422);
        }
        try {
            $out = $this->bd->create('coord-requests', [
                'title' => mb_substr($judul, 0, 190),
                'fromDiv' => $this->divisiSendiri($me),
                'toDiv' => mb_substr($ke, 0, 64),
                'by' => mb_substr($nama, 0, 120),
                'hrisById' => $uid,
                'to' => '',
                'status' => 'Diminta',
                'priority' => mb_substr(self::s($in['prioritas'] ?? 'medium'), 0, 16),
                'due' => $tenggat === '' ? null : $tenggat,
                'project' => mb_substr(self::s($in['proyek'] ?? ''), 0, 64),
                'detail' => mb_substr(self::s($in['detail'] ?? ''), 0, 2000),
            ]);
        } catch (BdConflict $e) {
            throw new HrisError('version_conflict', 'Tiket ini baru saja dibuat orang lain.', 409);
        }
        $this->audit->record($nama, 'tiket_dibuat', 'bd_coord', (string) $out['row']['id'], ['ke' => $ke]);

        return $this->bentuk($out['row'], $out['version']);
    }

    /**
     * Assign + advance, with the BD version guard. Assigning takes the head
     * of the target division (or HR); advancing takes the assignee, the
     * target head, or HR. Status moves forward only.
     */
    public function ubah(array $me, string $id, array $in, int $berubah): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $found = $this->bd->find('coord-requests', self::s($id));
        if (! $found) {
            throw new HrisError('not_found', 'Tiket tidak ada.', 404);
        }
        $row = $found['row'];
        if (! $this->bolehLihat($me, $row)) {
            throw new HrisError('forbidden', 'Tidak berhak mengubah tiket ini.', 403);
        }
        $ke = (string) ($row['toDiv'] ?? '');
        $isHr = $this->lingkup->isHr($me);
        $kepalaKe = $isHr || ($this->lingkup->isHead($me) && in_array($ke, $this->lingkup->headDivisi($me), true));
        $pelaksana = (string) ($row['to'] ?? '');
        $bolehMaju = $isHr || $kepalaKe || ($pelaksana !== '' && $pelaksana === $uid);

        $fields = [];
        if (array_key_exists('pelaksana', $in) || array_key_exists('assignee', $in)) {
            if (! $kepalaKe) {
                throw new HrisError('forbidden', 'Hanya Kepala Divisi tujuan atau HR yang menugaskan.', 403);
            }
            $assignee = self::s($in['pelaksana'] ?? $in['assignee'] ?? '');
            if ($assignee !== '') {
                try {
                    $ada = $this->users->userById($assignee);
                } catch (\Throwable) {
                    $ada = null;
                }
                if (! $ada) {
                    throw new HrisError('validation_failed', 'Pelaksana bukan User yang dikenal.', 422);
                }
            }
            $fields['to'] = $assignee;
        }
        if (self::s($in['status'] ?? '') !== '') {
            if (! $bolehMaju) {
                throw new HrisError('forbidden', 'Hanya pelaksana, Kepala Divisi tujuan, atau HR yang memajukan.', 403);
            }
            $baru = self::s($in['status']);
            $dari = (string) ($row['status'] ?? '');
            if ($baru !== $dari) {
                $maju = ['Diminta' => 'Diproses', 'Diproses' => 'Review', 'Review' => 'Selesai'];
                if (! in_array($baru, self::STATUS, true) || ($maju[$dari] ?? null) !== $baru) {
                    throw new HrisError('validation_failed',
                        "Status {$dari} hanya bisa ke ".($maju[$dari] ?? '−').'.', 422);
                }
                $fields['status'] = $baru;
            }
        }
        if ($fields === []) {
            return $this->bentuk($row, $found['version']);
        }
        try {
            $out = $this->bd->update('coord-requests', self::s($id), $fields, $berubah, true);
        } catch (BdConflict $e) {
            throw new HrisError('version_conflict',
                'Tiket ini baru diubah orang lain. Muat ulang lalu coba lagi.', 409,
                ['current' => $e->current]);
        }
        if (! $out) {
            throw new HrisError('not_found', 'Tiket tidak ada.', 404);
        }
        $this->audit->record($nama, 'tiket_diubah', 'bd_coord', self::s($id), $fields);

        return $this->bentuk($out['row'], $out['version']);
    }
}
