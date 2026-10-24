<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Hris\Models\HrisRosterEvent;
use App\Modules\Hris\Models\HrisRosterSimpan;

/**
 * Append-only trail of roster cell changes made through HRIS (issue #346).
 * One event per changed cell; rejections and cancelled pendings land here
 * only when a cell actually changed. History starts at deploy: nothing
 * backfills past manual SQL writes, and the service layer that bypasses
 * HRIS (legacy panels) is not covered.
 */
final class HrisRosterEventService
{
    public function __construct(
        private readonly HrisAuditService $audit,
        private readonly AccountRepository $users,
    ) {}

    /**
     * @param  array{user_id:string,user_ulid?:?string,simpan_id?:?string,divisi_kode?:?string,divisi_nama?:?string,tanggal_kerja:string,sel_lama?:?array,sel_baru?:?array,ref_pengajuan_id?:?string,diajukan_oleh?:?string,diajukan_at?:?int,diputus_oleh?:?string,diputus_at?:?int,catatan?:?string}  $e
     */
    public function catat(string $sumber, string $aktor, array $e): HrisRosterEvent
    {
        if (! in_array($sumber, [HrisRosterEvent::UBAH_SHIFT, HrisRosterEvent::PENGAJUAN,
            HrisRosterEvent::EDIT_MANUAL, HrisRosterEvent::SISTEM], true)) {
            throw new HrisError('validation_failed', 'Sumber event tidak dikenal.', 422);
        }

        return HrisRosterEvent::query()->create([
            'waktu' => PunchRules::ms(), 'aktor' => mb_substr(trim($aktor), 0, 120), 'sumber' => $sumber,
            'user_id' => trim((string) ($e['user_id'] ?? '')), 'user_ulid' => $e['user_ulid'] ?? null,
            'divisi_kode' => mb_substr(trim((string) ($e['divisi_kode'] ?? '')), 0, 32) ?: null,
            'divisi_nama' => mb_substr(trim((string) ($e['divisi_nama'] ?? '')), 0, 80) ?: null,
            'tanggal_kerja' => $e['tanggal_kerja'] ?? '',
            'simpan_id' => $e['simpan_id'] ?? null,
            'sel_lama' => $e['sel_lama'] ?? null, 'sel_baru' => $e['sel_baru'] ?? null,
            'ref_pengajuan_id' => mb_substr(trim((string) ($e['ref_pengajuan_id'] ?? '')), 0, 64) ?: null,
            'diajukan_oleh' => mb_substr(trim((string) ($e['diajukan_oleh'] ?? '')), 0, 120) ?: null,
            'diajukan_at' => $e['diajukan_at'] ?? null,
            'diputus_oleh' => mb_substr(trim((string) ($e['diputus_oleh'] ?? '')), 0, 120) ?: null,
            'diputus_at' => $e['diputus_at'] ?? null,
            'catatan' => mb_substr(trim((string) ($e['catatan'] ?? '')), 0, 500) ?: null,
        ]);
    }

    public function adaEvent(string $refId): bool
    {
        return HrisRosterEvent::query()->where('ref_pengajuan_id', trim($refId))->exists();
    }

    /**
     * HR audit search: newest first, paginated, accurate total.
     *
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int}}
     */
    public function riwayat(?string $tglDari, ?string $tglSampai, ?int $waktuDari, ?int $waktuSampai,
        ?string $divisi, ?string $userId, ?string $q, ?string $sumber, int $page, int $perPage,
        ?string $simpanId = null): array
    {
        $query = HrisRosterEvent::query()->orderByDesc('waktu')->orderByDesc('id');
        if (trim($simpanId ?? '') !== '') {
            $query->where('simpan_id', trim((string) $simpanId));
        }
        if (($d = PunchRules::tglValid($tglDari ?? '')) !== '') {
            $query->where('tanggal_kerja', '>=', $d);
        }
        if (($d = PunchRules::tglValid($tglSampai ?? '')) !== '') {
            $query->where('tanggal_kerja', '<=', $d);
        }
        if ($waktuDari !== null && $waktuDari > 0) {
            $query->where('waktu', '>=', $waktuDari);
        }
        if ($waktuSampai !== null && $waktuSampai > 0) {
            $query->where('waktu', '<=', $waktuSampai);
        }
        if (trim($divisi ?? '') !== '') {
            $query->where('divisi_kode', trim((string) $divisi));
        }
        if (trim($userId ?? '') !== '') {
            $query->where('user_id', trim((string) $userId));
        }
        if (trim($sumber ?? '') !== '') {
            $query->where('sumber', strtoupper(trim((string) $sumber)));
        }
        // Names of requesters/deciders are stored on the row (legacy account
        // has no reverse name search), so q matches ids and stored names.
        if (trim($q ?? '') !== '') {
            $like = '%'.mb_strtolower(trim((string) $q)).'%';
            $query->where(fn ($w) => $w->whereRaw('LOWER(user_id) LIKE ?', [$like])
                ->orWhereRaw('LOWER(aktor) LIKE ?', [$like])
                ->orWhereRaw('LOWER(diajukan_oleh) LIKE ?', [$like])
                ->orWhereRaw('LOWER(diputus_oleh) LIKE ?', [$like]));
        }
        $total = (clone $query)->count();
        $rows = $query->skip(max(0, ($page - 1) * $perPage))->take($perPage)->get();
        $nama = $this->users->namesByIds($rows->map(fn ($r) => (string) $r->user_id)->all());
        $out = [];
        foreach ($rows as $r) {
            $row = $this->bentuk($r);
            $row['nama'] = (string) ($nama[(string) $r->user_id] ?? (string) $r->user_id);
            $out[] = $row;
        }

        return ['data' => $out, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]];
    }

    /** @return array<string,mixed> */
    public function bentuk(HrisRosterEvent $r): array
    {
        return [
            'id' => $r->id, 'waktu' => (int) $r->waktu, 'aktor' => $r->aktor, 'sumber' => $r->sumber,
            'user_id' => $r->user_id, 'nama' => null,
            'divisi_kode' => $r->divisi_kode, 'divisi_nama' => $r->divisi_nama,
            'tanggal_kerja' => $r->tanggal_kerja->format('Y-m-d'),
            'sel_lama' => $r->sel_lama, 'sel_baru' => $r->sel_baru,
            'ref_pengajuan_id' => $r->ref_pengajuan_id, 'simpan_id' => $r->simpan_id,
            'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => $r->diajukan_at === null ? null : (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh, 'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'catatan' => $r->catatan, 'berubah' => (int) $r->version,
        ];
    }

    /**
     * Log one roster save (owner decision 2026-10-24) under the id its cell
     * events already carry. A save that changed no cell leaves no entry.
     *
     * @param  array<int,string>  $divisi
     */
    public function catatSimpan(string $id, string $aktor, string $aktorId, bool $hr, array $divisi,
        string $dari, string $sampai, int $isi, int $hapus, int $berubah, string $catatan): ?HrisRosterSimpan
    {
        if ($berubah === 0) {
            return null;
        }

        return HrisRosterSimpan::query()->create([
            'id' => $id, 'waktu' => PunchRules::ms(), 'aktor' => mb_substr(trim($aktor), 0, 120),
            'aktor_user_id' => mb_substr(trim($aktorId), 0, 64),
            'peran' => $hr ? HrisRosterSimpan::HR : HrisRosterSimpan::KEPALA,
            'divisi' => array_values(array_unique($divisi)),
            'tanggal_dari' => $dari, 'tanggal_sampai' => $sampai,
            'jumlah_isi' => $isi, 'jumlah_hapus' => $hapus, 'jumlah_berubah' => $berubah,
            'catatan' => mb_substr(trim($catatan), 0, 255) ?: null,
        ]);
    }

    /**
     * Kelola roster activity: one row per save, newest first. `bulan`
     * matches saves whose work-date range touches that month.
     *
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int}}
     */
    public function aktivitas(?string $bulan, ?string $divisi, ?string $peran, int $page, int $perPage): array
    {
        $query = HrisRosterSimpan::query()->orderByDesc('waktu')->orderByDesc('id');
        if ($bulan !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
            $query->where('tanggal_dari', '<=', date('Y-m-t', strtotime($bulan.'-01')))
                ->where('tanggal_sampai', '>=', $bulan.'-01');
        }
        if (trim($divisi ?? '') !== '') {
            $query->whereJsonContains('divisi', trim((string) $divisi));
        }
        if (in_array($peran, [HrisRosterSimpan::HR, HrisRosterSimpan::KEPALA], true)) {
            $query->where('peran', $peran);
        }
        $total = (clone $query)->count();
        $out = [];
        foreach ($query->skip(max(0, ($page - 1) * $perPage))->take($perPage)->get() as $r) {
            $out[] = [
                'id' => $r->id, 'waktu' => (int) $r->waktu, 'aktor' => $r->aktor,
                'aktor_user_id' => $r->aktor_user_id, 'peran' => $r->peran,
                'divisi' => array_values((array) $r->divisi),
                'tanggal_dari' => $r->tanggal_dari->format('Y-m-d'),
                'tanggal_sampai' => $r->tanggal_sampai->format('Y-m-d'),
                'jumlah_isi' => $r->jumlah_isi, 'jumlah_hapus' => $r->jumlah_hapus,
                'jumlah_berubah' => $r->jumlah_berubah, 'catatan' => $r->catatan,
            ];
        }

        return ['data' => $out, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]];
    }
}
