<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;

/**
 * Read-only team roster for crew (issue #346): who is scheduled, per
 * division, from Jadwal cells through JadwalService. No attendance is
 * read here: an empty cell stays Belum diatur even when the person has a
 * shift bawaan, and no hours are ever synthesized for office/nonshift
 * crew. Daily Worker rows come from a different dataset and are out of
 * scope. Peer DTOs carry display minimum only.
 */
final class HrisTimRosterService
{
    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly HrisShiftService $shift,
        private readonly AccountRepository $users,
        private readonly HrisGerbang $gerbang,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function hariIni(): string
    {
        return PunchRules::tglWib(PunchRules::ms());
    }

    /** Active divisions crew may filter by (canonical HRIS identity). */
    public function divisi(): array
    {
        return HrisDivisi::query()->where('aktif', true)->orderBy('urutan')->orderBy('nama')->get()
            ->map(fn (HrisDivisi $d) => ['id' => $d->id, 'kode' => $d->kode, 'nama' => $d->nama, 'jenis' => $d->jenis])->all();
    }

    /** The caller's own division code, or '' when none is valid. */
    public function divisiSendiri(string $uid): string
    {
        $k = HrisKaryawan::query()->with('divisi')->where('user_id', self::s($uid))->first();
        $kode = (string) ($k?->divisi?->kode ?? '');
        if ($kode === '') {
            return '';
        }

        return HrisDivisi::query()->where('kode', $kode)->where('aktif', true)->exists() ? $kode : '';
    }

    /**
     * @return array{dari:string,sampai:string,divisi:string,hari:array<int,array<string,mixed>>,masalah:array<int,array<string,string>>}
     */
    public function roster(string $uid, string $dari, string $sampai, ?string $divisi): array
    {
        $dari = PunchRules::tglValid($dari);
        $sampai = PunchRules::tglValid($sampai);
        if ($dari === '' || $sampai === '' || $sampai < $dari) {
            throw new HrisError('validation_failed', 'Rentang tanggal butuh dari & sampai (YYYY-MM-DD).', 422);
        }
        if ((strtotime($sampai) - strtotime($dari)) > 30 * 86400) {
            throw new HrisError('validation_failed', 'Rentang roster tim maksimal 31 hari.', 422);
        }
        $kode = self::s($divisi ?? '');
        if ($kode !== '' && $kode !== 'semua'
            && ! HrisDivisi::query()->where('kode', $kode)->where('aktif', true)->exists()) {
            throw new HrisError('validation_failed', 'Divisi tidak dikenal atau nonaktif.', 422);
        }
        if ($kode === '') {
            $kode = ($sendiri = $this->divisiSendiri($uid)) !== '' ? $sendiri : 'semua';
        }

        $kru = $this->kruBerhak($kode, $uid);
        $ids = array_flip(array_column($kru, 'user_id'));
        $sel = [];
        foreach ($this->jadwal->shiftRange('', $dari, $sampai)['rows'] ?? [] as $c) {
            $c = (array) $c;
            if (isset($ids[(string) ($c['u'] ?? '')])) {
                $sel[(string) $c['u'].'|'.(string) $c['d']] = $c;
            }
        }
        $defs = [];
        foreach ($this->shift->list() as $x) {
            $defs[$x['kode']] = $x;
        }

        $hari = [];
        $masalah = [];
        for ($d = $dari; $d <= $sampai; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
            $baris = [];
            $jumlah = ['dijadwalkan' => 0, 'tidak_masuk' => 0, 'belum_diatur' => 0];
            foreach ($kru as $k) {
                $c = $sel[$k['user_id'].'|'.$d] ?? null;
                if ($c === null) {
                    $jumlah['belum_diatur']++;
                    $baris[] = $k + ['status' => 'Belum diatur', 'shift' => null];

                    continue;
                }
                $kode2 = (string) ($c['t'] ?? '');
                $def = $defs[$kode2] ?? null;
                if ($def === null) {
                    $masalah[] = ['user_id' => $k['user_id'], 'nama' => $k['nama'],
                        'tanggal' => $d, 'kode' => $kode2];

                    continue;
                }
                if ($def['libur']) {
                    $jumlah['tidak_masuk']++;
                    $baris[] = $k + ['status' => 'Tidak masuk', 'shift' => $this->bentukShift($def, $c)];

                    continue;
                }
                $jumlah['dijadwalkan']++;
                $baris[] = $k + ['status' => 'Dijadwalkan', 'shift' => $this->bentukShift($def, $c)];
            }
            $hari[] = ['tanggal' => $d, 'kru' => $baris, 'jumlah' => $jumlah];
        }

        return ['dari' => $dari, 'sampai' => $sampai, 'divisi' => $kode, 'hari' => $hari, 'masalah' => $masalah];
    }

    /**
     * Active crew with HRIS access, display minimum only. Kantor/nonshift
     * crew are included: without an explicit cell they read Belum diatur.
     *
     * @return array<int,array{user_id:string,nama:string,jabatan:?string,divisi:?array{mixed},milik_sendiri:bool}>
     */
    private function kruBerhak(string $divisi, ?string $sendiri = null): array
    {
        $pengguna = [];
        foreach ($this->users->allUsers() as $u) {
            $pengguna[(string) ($u['id'] ?? '')] = $u;
        }
        $out = [];
        foreach (HrisKaryawan::query()->with(['divisi', 'jabatan'])
            ->where(fn ($w) => $w->whereNull('tanggal_keluar')->orWhere('tanggal_keluar', '>=', self::hariIni()))->get() as $k) {
            $uid = (string) $k->user_id;
            $u = $pengguna[$uid] ?? null;
            if (! $u || (int) ($u['active'] ?? 0) !== 1 || ! $this->gerbang->bolehBawaan($uid)) {
                continue;
            }
            $kode = (string) ($k->divisi?->kode ?? '');
            if ($divisi !== 'semua' && $kode !== $divisi) {
                continue;
            }
            $out[] = ['user_id' => $uid, 'nama' => (string) ($u['name'] ?? $uid),
                'jabatan' => $k->jabatan?->nama,
                'divisi' => $k->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null,
                'milik_sendiri' => $sendiri !== null && $uid === $sendiri];
        }
        usort($out, fn ($a, $b) => strcmp(mb_strtolower($a['nama']), mb_strtolower($b['nama'])));

        return $out;
    }

    /** Shift detail for peers: code, name, color, hours, blocks, off flag. */
    private function bentukShift(array $def, array $sel): array
    {
        $mulai = self::s($sel['m'] ?? '') !== '' ? self::s($sel['m']) : (string) ($def['mulai'] ?? '');
        $selesai = self::s($sel['s'] ?? '') !== '' ? self::s($sel['s']) : (string) ($def['selesai'] ?? '');
        $lintas = $mulai !== '' && $selesai !== '' && $selesai < $mulai;

        return ['kode' => (string) ($def['kode'] ?? ''), 'nama' => (string) ($def['nama'] ?? ''),
            'warna' => (string) ($def['warna'] ?? ''), 'mulai' => $mulai, 'selesai' => $selesai,
            'lintas_hari' => $lintas, 'blok' => $def['blok'] ?? [], 'libur' => (bool) ($def['libur'] ?? false)];
    }
}
