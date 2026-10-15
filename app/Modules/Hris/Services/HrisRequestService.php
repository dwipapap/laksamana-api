<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPengajuan;
use App\Modules\Hris\Models\HrisSaldoMutasi;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CUTI/PH/IZIN requests (L1): crew file, Kepala Divisi decides first, HRD
 * decides second. Approval deducts the FIFO ledger and writes Jadwal cells
 * (CUTI→CUTI, IZIN→IZIN, PH→OFF with a "PH 25 Des" note) so the old roster
 * screens keep showing a correct roster; the v1 Jadwal contract is untouched.
 *
 * Balance is deducted at APPROVAL, not at filing, so nothing is locked while
 * a request waits. Cancelling an approved request refunds through new earn
 * rows and deletes the written cells.
 */
final class HrisRequestService
{
    public const JENIS = ['CUTI', 'PH', 'IZIN'];

    public const BULAN_ID = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(
        private readonly HrisBalanceService $saldo,
        private readonly HrisAuditService $audit,
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
        private readonly Divisi $divisi,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    public static function assertJenis(string $jenis): string
    {
        $jenis = strtoupper(trim($jenis));
        if (! in_array($jenis, self::JENIS, true)) {
            throw new HrisError('validation_failed', 'Jenis pengajuan harus CUTI, PH, atau IZIN.', 422);
        }

        return $jenis;
    }

    /** Inclusive calendar-day count. */
    public static function hariInclusive(string $dari, string $sampai): int
    {
        return (int) Carbon::parse($dari, 'Asia/Jakarta')->diffInDays(Carbon::parse($sampai, 'Asia/Jakarta')) + 1;
    }

    // ---------------------------------------------------------------- create

    /** @param  array{id:string,name:string}  $me */
    public function create(array $me, array $in): HrisPengajuan
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        $jenis = self::assertJenis(self::s($in['jenis'] ?? ''));
        $dari = PunchRules::tglValid($in['dari'] ?? '');
        $sampai = PunchRules::tglValid($in['sampai'] ?? $in['dari'] ?? '');
        if ($dari === '' || $sampai === '') {
            throw new HrisError('validation_failed', 'Pengajuan butuh dari & sampai (YYYY-MM-DD).', 422);
        }
        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $hari = self::hariInclusive($dari, $sampai);
        if ($hari > 31) {
            throw new HrisError('validation_failed', 'Satu pengajuan maksimal 31 hari.', 422);
        }
        $alasan = mb_substr(trim(self::s($in['alasan'] ?? '')), 0, 500);
        if ($alasan === '') {
            throw new HrisError('validation_failed', 'Alasan pengajuan wajib diisi.', 422);
        }
        $lampiran = self::storeLampiran($in['lampiran'] ?? null, self::s($in['lampiran_nama'] ?? ''));
        if ($jenis === 'IZIN' && $lampiran === null) {
            throw new HrisError('validation_failed', 'Pengajuan IZIN wajib melampirkan bukti.', 422);
        }
        if ($jenis !== 'IZIN') {
            $sisa = $this->saldo->saldo($uid, $jenis);
            if ($sisa < $hari) {
                throw new HrisError('saldo_kurang', "Saldo {$jenis} kurang: sisa {$sisa}, butuh {$hari}.", 422,
                    ['saldo' => $sisa, 'butuh' => $hari]);
            }
        }
        $row = HrisPengajuan::query()->create([
            'user_id' => $uid, 'user_ulid' => null, 'jenis' => $jenis,
            'tgl_mulai' => $dari, 'tgl_selesai' => $sampai, 'hari' => $hari, 'alasan' => $alasan,
            'lampiran_path' => $lampiran['path'] ?? null,
            'lampiran_nama' => $lampiran['nama'] ?? null,
            'lampiran_mime' => $lampiran['mime'] ?? null,
            'status' => 'MENUNGGU',
        ]);
        $this->audit->record($nama, 'pengajuan_dibuat', 'hris_pengajuan', $row->id,
            ['jenis' => $jenis, 'hari' => $hari]);

        return $row;
    }

    // ---------------------------------------------------------------- read

    /** @param  array<int,string>|null  $userIds */
    public function list(?array $userIds, ?string $status, ?string $jenis): array
    {
        $q = HrisPengajuan::query()->orderByDesc('created_at');
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        $status = strtoupper(self::s($status ?? ''));
        if ($status !== '') {
            $q->where('status', $status);
        }
        $jenis = self::s($jenis ?? '');
        if ($jenis !== '') {
            $q->where('jenis', self::assertJenis($jenis));
        }
        $out = [];
        foreach ($q->limit(500)->get() as $r) {
            $out[] = $this->shape($r);
        }

        return $out;
    }

    public function shape(HrisPengajuan $r): array
    {
        return [
            'id' => $r->id, 'user_id' => $r->user_id, 'jenis' => $r->jenis,
            'tgl_mulai' => $r->tgl_mulai->format('Y-m-d'), 'tgl_selesai' => $r->tgl_selesai->format('Y-m-d'),
            'hari' => (int) $r->hari, 'alasan' => $r->alasan,
            'ada_lampiran' => $r->lampiran_path ? true : false,
            'lampiran_nama' => $r->lampiran_nama,
            'lampiran_url' => $r->lampiran_path ? "/api/v1/hris/requests/{$r->id}/lampiran" : null,
            'status' => $r->status,
            'head_oleh' => $r->head_oleh, 'head_at' => (int) $r->head_at, 'head_nota' => $r->head_nota,
            'hrd_oleh' => $r->hrd_oleh, 'hrd_at' => (int) $r->hrd_at, 'hrd_nota' => $r->hrd_nota,
            'berubah' => (int) $r->version,
        ];
    }

    public function lampiranPath(string $id): ?array
    {
        $row = HrisPengajuan::query()->find(self::s($id));
        if (! $row || ! $row->lampiran_path) {
            return null;
        }
        $key = 'hris-lampiran/'.$row->lampiran_path;
        if (! Storage::disk('local')->exists($key)) {
            return null;
        }

        return ['file' => Storage::disk('local')->path($key),
            'nama' => $row->lampiran_nama ?: 'lampiran',
            'mime' => $row->lampiran_mime ?: 'application/octet-stream'];
    }

    // ---------------------------------------------------------------- cancel

    /**
     * Cancel: the requester while waiting; HR any time before final, and
     * after approval with refund + Jadwal cell cleanup.
     */
    public function cancel(array $me, HrisPengajuan $row): HrisPengajuan
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $isHr = $this->lingkup->isHr($me);
        $final = in_array($row->status, ['DISETUJUI', 'DITOLAK', 'DIBATALKAN'], true);
        if ($final && ! ($isHr && $row->status === 'DISETUJUI')) {
            throw new HrisError('validation_failed', 'Pengajuan ini sudah final dan tidak bisa dibatalkan.', 422);
        }
        if (! $isHr && $row->user_id !== $uid) {
            throw new HrisError('forbidden', 'Hanya pengaju atau HR yang bisa membatalkan.', 403);
        }
        if ($row->status === 'DISETUJUI') {
            $this->refund($row, $nama);
            $this->writeCells($row, [], true);
        }
        self::deleteLampiran($row->lampiran_path);
        $row->fill(['status' => 'DIBATALKAN', 'lampiran_path' => null,
            'lampiran_nama' => null, 'lampiran_mime' => null])->save();
        $this->audit->record($nama, 'pengajuan_dibatalkan', 'hris_pengajuan', $row->id,
            ['jenis' => $row->jenis]);

        return $row->fresh();
    }

    // ---------------------------------------------------------------- decide

    /**
     * One decision endpoint for both steps. MENUNGGU takes the Kepala Divisi
     * of the requester's division (HR only when the division has no head);
     * MENUNGGU_HRD takes HRD. Anything else is already decided.
     */
    public function decide(array $me, HrisPengajuan $row, string $status, string $nota): HrisPengajuan
    {
        $nama = self::s($me['name'] ?? '');
        $status = strtoupper(self::s($status));
        if ($status !== 'DISETUJUI' && $status !== 'DITOLAK') {
            throw new HrisError('validation_failed', 'Keputusan hanya boleh DISETUJUI atau DITOLAK.', 422);
        }
        $nota = mb_substr(self::s($nota), 0, 255);
        $now = PunchRules::ms();
        $isHr = $this->lingkup->isHr($me);

        if ($row->status === 'MENUNGGU') {
            $this->assertHeadStep($me, $row, $isHr);
            $row->fill([
                'head_oleh_id' => self::s($me['id'] ?? ''), 'head_oleh' => mb_substr($nama, 0, 120),
                'head_at' => $now, 'head_nota' => $nota,
                'status' => $status === 'DISETUJUI' ? 'MENUNGGU_HRD' : 'DITOLAK',
            ])->save();
            $this->audit->record($nama, 'pengajuan_diputus_kepala', 'hris_pengajuan', $row->id,
                ['status' => $row->status]);

            return $row->fresh();
        }
        if ($row->status === 'MENUNGGU_HRD') {
            if (! $isHr) {
                throw new HrisError('forbidden', 'Tahap ini menunggu keputusan HRD.', 403);
            }
            if ($status === 'DISETUJUI') {
                $this->approve($row, $nama);
            }
            $row->fill([
                'hrd_oleh_id' => self::s($me['id'] ?? ''), 'hrd_oleh' => mb_substr($nama, 0, 120),
                'hrd_at' => $now, 'hrd_nota' => $nota, 'status' => $status,
            ])->save();
            $this->audit->record($nama, 'pengajuan_diputus_hrd', 'hris_pengajuan', $row->id,
                ['status' => $status]);

            return $row->fresh();
        }
        throw new HrisError('already_decided', 'Pengajuan ini sudah diputus.', 409);
    }

    // ---------------------------------------------------------------- approval

    private function approve(HrisPengajuan $row, string $hrdName): void
    {
        // Deduct first (saldo_kurang leaves the state untouched), then write
        // the cells; a cell failure refunds the deduction.
        $pakai = [];
        if ($row->jenis !== 'IZIN') {
            try {
                $pakai = $this->saldo->gunakan($row->user_id, $row->jenis, (int) $row->hari,
                    'PENGAJUAN', $row->id, $row->jenis.' '.$row->tgl_mulai.' s.d. '.$row->tgl_selesai);
            } catch (HrisError $e) {
                if ($e->errorCode === 'saldo_kurang') {
                    throw new HrisError('saldo_kurang',
                        "Saldo {$row->jenis} tidak cukup lagi (dipakai pengajuan lain). Tetap menunggu.", 422, $e->details);
                }
                throw $e;
            }
        }
        try {
            $this->writeCells($row, $this->cellRows($row), false);
        } catch (\Throwable $e) {
            if ($pakai !== []) {
                $this->saldo->kembalikan('PENGAJUAN', $row->id, 'Gagal tulis Jadwal, dikembalikan');
            }
            $msg = $e instanceof HrisError ? $e->getMessage()
                : trim((string) preg_replace('/^tidak_berhak:\s*/', '', $e->getMessage()));
            throw new HrisError('jadwal_tulis_ditolak',
                'Sel Jadwal gagal ditulis ('.$msg.'). Minta admin Jadwal memberi hak tulis, lalu putuskan lagi.', 422);
        }
    }

    /** Refund an approved request: new earn rows mirroring the usage chunks. */
    private function refund(HrisPengajuan $row, string $by): void
    {
        if ($row->jenis !== 'IZIN') {
            $this->saldo->kembalikan('PENGAJUAN', $row->id, 'Pembatalan pengajuan '.$row->id);
        }
        $this->audit->record($by, 'pengajuan_dikembalikan', 'hris_pengajuan', $row->id, []);
    }

    /** @return array<int,array{u:string,d:string,t:string,m:string,s:string,n:string}> */
    private function cellRows(HrisPengajuan $row): array
    {
        $kode = $row->jenis === 'PH' ? 'OFF' : $row->jenis;
        $mulai = Carbon::parse($row->tgl_mulai, 'Asia/Jakarta');
        $rows = [];
        for ($i = 0; $i < (int) $row->hari; $i++) {
            $d = $mulai->copy()->addDays($i)->format('Y-m-d');
            $rows[] = ['u' => $row->user_id, 'd' => $d, 't' => $kode, 'm' => '', 's' => '',
                'n' => $this->cellNote($row, $d)];
        }

        return $rows;
    }

    private function cellNote(HrisPengajuan $row, string $d): string
    {
        if ($row->jenis === 'PH') {
            $c = Carbon::parse($d, 'Asia/Jakarta');

            return 'PH '.$c->day.' '.self::BULAN_ID[$c->month - 1];
        }

        return $row->jenis === 'CUTI' ? 'Cuti' : 'Izin';
    }

    /**
     * Write (or delete) Jadwal cells with the approving head's authority —
     * the head approved the absence, so the roster consequence carries the
     * same authority as the head writing it themselves.
     */
    private function writeCells(HrisPengajuan $row, array $rows, bool $hapus): void
    {
        $u = ['id' => $row->head_oleh_id, 'name' => $row->head_oleh, 'adminModules' => []];
        if ($hapus) {
            $hapusRows = [];
            foreach ($this->cellRows($row) as $c) {
                $hapusRows[] = ['u' => $c['u'], 'd' => $c['d']];
            }
            $this->jadwal->saveCells([], $hapusRows, 'HRIS:'.$row->hrd_oleh, $u);
        } else {
            $this->jadwal->saveCells($rows, [], 'HRIS:'.$row->hrd_oleh ?: 'HRIS', $u);
        }
    }

    // ---------------------------------------------------------------- heads

    private function requesterDivisi(string $userId): string
    {
        try {
            return $this->jadwal->divisiUser($userId);
        } catch (\Throwable) {
            return Divisi::NONSHIFT;
        }
    }

    private function assertHeadStep(array $me, HrisPengajuan $row, bool $isHr): void
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === $row->user_id) {
            throw new HrisError('forbidden', 'Pengajuan sendiri tidak bisa diputus sendiri.', 403);
        }
        $div = $this->requesterDivisi($row->user_id);
        if ($this->lingkup->isHead($me) && in_array($div, $this->lingkup->headDivisi($me), true)) {
            return;
        }
        // No head for this division (or office staff): HR takes the head step
        // too, recorded as such — the trail still shows two decisions.
        try {
            $adaKepala = $div !== Divisi::NONSHIFT && $this->jadwal->divHasHead($div);
        } catch (\Throwable) {
            $adaKepala = false;
        }
        if ($isHr && ! $adaKepala) {
            return;
        }
        throw new HrisError('forbidden', 'Tahap ini menunggu Kepala Divisi yang bersangkutan.', 403);
    }

    // ---------------------------------------------------------------- files

    /** @return array{path:string,nama:string,mime:string}|null */
    private static function storeLampiran(mixed $lampiran, string $nama): ?array
    {
        $lampiran = is_string($lampiran) ? trim($lampiran) : '';
        if ($lampiran === '') {
            return null;
        }
        $mime = 'application/octet-stream';
        if (str_starts_with($lampiran, 'data:')) {
            $pos = strpos($lampiran, ',');
            if ($pos === false) {
                throw new HrisError('validation_failed', 'Lampiran tidak valid.', 422);
            }
            if (preg_match('#^data:([a-z0-9/+.-]+);base64$#i', substr($lampiran, 0, $pos), $m)) {
                $mime = strtolower($m[1]);
            }
            $lampiran = substr($lampiran, $pos + 1);
        }
        $bytes = base64_decode($lampiran, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
            throw new HrisError('validation_failed', 'Lampiran tidak valid (base64, maks 10 MB).', 422);
        }
        $key = 'lampiran_'.strtolower((string) Str::ulid()).'.bin';
        Storage::disk('local')->put('hris-lampiran/'.$key, $bytes);
        $nama = trim($nama) !== '' ? mb_substr(trim($nama), 0, 180) : 'lampiran';

        return ['path' => $key, 'nama' => $nama, 'mime' => mb_substr($mime, 0, 120)];
    }

    public static function deleteLampiran(?string $key): void
    {
        if ($key) {
            Storage::disk('local')->delete('hris-lampiran/'.$key);
        }
    }

    // ---------------------------------------------------------------- transition

    /**
     * Deduct approved CUTI requests made in the old Jadwal panel that the
     * ledger has not seen yet (L1 transition). Requests that exceed the
     * balance are SKIPPED and reported — HR tops up through penyesuaian.
     *
     * @return array{dipakai:int,terlewat:array<int,string>}
     */
    public function syncCutiJadwalLama(): array
    {
        $dipakai = 0;
        $terlewat = [];
        try {
            $rows = $this->jadwal->listRequests(['status' => ['DISETUJUI']]);
        } catch (\Throwable) {
            return ['dipakai' => 0, 'terlewat' => ['jadwal_tidak_terbaca']];
        }
        foreach ($rows as $r) {
            if (strtoupper((string) ($r['jenis'] ?? '')) !== 'CUTI') {
                continue;
            }
            $jid = (string) ($r['id'] ?? '');
            $uid = (string) ($r['userId'] ?? '');
            if ($jid === '' || $uid === '') {
                continue;
            }
            $sudah = HrisSaldoMutasi::query()->where('sumber_tipe', 'JADWAL_LAMA')
                ->where('sumber_id', 'like', $jid.'%')->exists();
            if ($sudah) {
                continue;
            }
            $dari = PunchRules::tglValid($r['dari'] ?? '');
            $sampai = PunchRules::tglValid($r['sampai'] ?? $r['dari'] ?? '');
            if ($dari === '' || $sampai === '') {
                continue;
            }
            $hari = min(62, self::hariInclusive($dari, $sampai));
            try {
                $this->saldo->gunakan($uid, 'CUTI', $hari, 'JADWAL_LAMA', $jid, 'Cuti panel Jadwal lama');
                $dipakai++;
            } catch (HrisError $e) {
                if ($e->errorCode === 'saldo_kurang') {
                    $terlewat[] = $jid;
                    continue;
                }
                throw $e;
            }
        }

        return ['dipakai' => $dipakai, 'terlewat' => array_values($terlewat)];
    }
}
