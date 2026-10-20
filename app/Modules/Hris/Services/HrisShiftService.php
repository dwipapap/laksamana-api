<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;

/**
 * Master shift (J1–J4, ADR-0012), edited from /kelola/shift and stored where
 * Jadwal and the old panels read it: the `shifts` map of the Jadwal setting
 * blob. Each definition keeps the legacy keys (n, m, s, w, libur, urut) and
 * gains three that the old screens ignore:
 *
 *   blok   [{m, s}, …]  two or more blocks; m/s then span the first start
 *                      to the last end, so Jadwal v1 still reads one window
 *   divisi [kode, …]    the shift Divisi it is for; absent = every Divisi
 *   aktif  0|1          0 hides it from new roster cells (absent = 1)
 *
 * Codes are generated from the name and never change. A code ever used in a
 * roster cell is deactivated, never deleted. OFF/IZIN/CUTI/LAIN are locked:
 * HRIS requests and PH write them.
 */
final class HrisShiftService
{
    public const TERKUNCI = ['OFF', 'IZIN', 'CUTI', 'LAIN'];

    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly HrisAuditService $audit,
        private readonly HrisMasterBawaan $bawaan,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** @return array<string,mixed> */
    private function blob(): array
    {
        return JsonDoc::toArray($this->jadwal->setting());
    }

    /** @return array<string,array<string,mixed>> */
    private function defs(array $blob): array
    {
        $out = [];
        foreach (is_array($blob['shifts'] ?? null) ? $blob['shifts'] : [] as $k => $d) {
            $out[(string) $k] = is_array($d) ? $d : [];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $out = [];
        foreach ($this->defs($this->blob()) as $kode => $d) {
            $out[] = $this->shape($kode, $d);
        }
        usort($out, fn ($a, $b) => [$a['urutan'], $a['kode']] <=> [$b['urutan'], $b['kode']]);

        return $out;
    }

    public function create(array $in, string $by): array
    {
        $blob = $this->blob();
        $defs = $this->defs($blob);
        $nama = mb_substr(self::s($in['nama'] ?? ''), 0, 32);
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama shift wajib diisi.', 422);
        }
        $kode = $this->kodeBaru($nama, array_keys($defs));
        $urut = 0;
        foreach ($defs as $d) {
            $urut = max($urut, (int) ($d['urut'] ?? 0));
        }
        $def = ['n' => $nama, 'w' => self::warna($in['warna'] ?? '#7A756B'), 'libur' => 0, 'urut' => $urut + 10];
        $def = $this->terapkan($def, $in, false);
        $defs[$kode] = $def;
        $this->simpan($blob, $defs, $by, 'shift_dibuat', $kode);

        return $this->shape($kode, $def);
    }

    public function update(string $kode, array $in, string $by): array
    {
        $blob = $this->blob();
        $defs = $this->defs($blob);
        if (! isset($defs[$kode])) {
            throw new HrisError('not_found', 'Shift tidak ada.', 404);
        }
        $kunci = in_array($kode, self::TERKUNCI, true);
        if ($kunci && (array_key_exists('aktif', $in) || array_key_exists('libur', $in)
            || array_key_exists('blok', $in) || array_key_exists('divisi', $in))) {
            throw new HrisError('shift_terkunci', 'OFF, IZIN, CUTI dan LAIN hanya bisa diganti nama, warna dan urutannya.', 409);
        }
        $def = $defs[$kode];
        if (array_key_exists('nama', $in)) {
            $nama = mb_substr(self::s($in['nama']), 0, 32);
            if ($nama === '') {
                throw new HrisError('validation_failed', 'Nama shift wajib diisi.', 422);
            }
            $def['n'] = $nama;
        }
        if (array_key_exists('warna', $in)) {
            $def['w'] = self::warna($in['warna']);
        }
        if (array_key_exists('urutan', $in)) {
            $def['urut'] = (int) $in['urutan'];
        }
        $def = $this->terapkan($def, $in, true);
        $defs[$kode] = $def;
        $this->simpan($blob, $defs, $by, 'shift_diubah', $kode);

        return $this->shape($kode, $def);
    }

    public function delete(string $kode, string $by): void
    {
        $blob = $this->blob();
        $defs = $this->defs($blob);
        if (! isset($defs[$kode])) {
            throw new HrisError('not_found', 'Shift tidak ada.', 404);
        }
        if (in_array($kode, self::TERKUNCI, true)) {
            throw new HrisError('shift_terkunci', 'Shift ini dipakai pengajuan HRIS dan tidak bisa dihapus.', 409);
        }
        if ($this->jadwal->kodeDipakai($kode)) {
            throw new HrisError('shift_dipakai', 'Shift sudah pernah dipakai di jadwal. Nonaktifkan saja.', 409);
        }
        unset($defs[$kode]);
        $this->simpan($blob, $defs, $by, 'shift_dihapus', $kode);
    }

    /** Hours, blocks, Divisi, libur and aktif from input onto a definition. */
    private function terapkan(array $def, array $in, bool $ubah): array
    {
        if (array_key_exists('libur', $in)) {
            $def['libur'] = ! empty($in['libur']) ? 1 : 0;
        }
        if (array_key_exists('aktif', $in)) {
            if (! empty($in['aktif'])) {
                unset($def['aktif']);
            } else {
                $def['aktif'] = 0;
            }
        }
        if (array_key_exists('blok', $in) || ! $ubah) {
            $blok = $this->blokDariInput($in['blok'] ?? [], ! empty($def['libur']));
            if (count($blok) >= 2) {
                $def['blok'] = $blok;
            } else {
                unset($def['blok']);
            }
            $def['m'] = $blok[0]['m'] ?? '';
            $def['s'] = $blok === [] ? '' : $blok[count($blok) - 1]['s'];
        }
        if (array_key_exists('divisi', $in)) {
            $divisi = $this->divisiDariInput($in['divisi']);
            if ($divisi === []) {
                unset($def['divisi']);
            } else {
                $def['divisi'] = $divisi;
            }
        }

        return $def;
    }

    /**
     * 1–3 blocks of HH:MM. Blocks run in order within the day; only the
     * last may end after midnight. A libur shift has none.
     *
     * @return array<int,array{m:string,s:string}>
     */
    private function blokDariInput(mixed $raw, bool $libur): array
    {
        $raw = is_array($raw) ? array_values($raw) : [];
        if ($libur) {
            if ($raw !== []) {
                throw new HrisError('validation_failed', 'Shift libur tidak punya jam.', 422);
            }

            return [];
        }
        if ($raw === [] || count($raw) > 3) {
            throw new HrisError('validation_failed', 'Shift butuh 1 sampai 3 blok jam.', 422);
        }
        $out = [];
        $akhirSebelum = -1;
        foreach ($raw as $i => $b) {
            $b = is_array($b) ? $b : [];
            $m = self::s($b['mulai'] ?? $b['m'] ?? '');
            $sl = self::s($b['selesai'] ?? $b['s'] ?? '');
            $mm = PunchRules::jamKeMenit($m);
            $ss = PunchRules::jamKeMenit($sl);
            if ($mm < 0 || $ss < 0 || $mm === $ss) {
                throw new HrisError('validation_failed', 'Jam blok '.($i + 1).' harus HH:MM dan mulai ≠ selesai.', 422);
            }
            $terakhir = $i === count($raw) - 1;
            if ($mm <= $akhirSebelum || (! $terakhir && $ss < $mm)) {
                throw new HrisError('validation_failed', 'Blok harus berurutan dan tidak bertumpuk; hanya blok terakhir boleh lewat tengah malam.', 422);
            }
            $akhirSebelum = $ss;
            $out[] = ['m' => sprintf('%02d:%02d', intdiv($mm, 60), $mm % 60), 's' => sprintf('%02d:%02d', intdiv($ss, 60), $ss % 60)];
        }

        return $out;
    }

    /** @return array<int,string> shift Divisi codes */
    private function divisiDariInput(mixed $raw): array
    {
        $kode = array_values(array_unique(array_filter(array_map(fn ($x) => self::s($x), is_array($raw) ? $raw : []))));
        if ($kode === []) {
            return [];
        }
        $this->bawaan->pastikan();
        $ada = HrisDivisi::query()->whereIn('kode', $kode)->where('jenis', 'shift')->pluck('kode')->all();
        if ($asing = array_values(array_diff($kode, $ada))) {
            throw new HrisError('validation_failed', 'Bukan Divisi shift: '.implode(', ', $asing).'.', 422);
        }

        return $kode;
    }

    /** @param  array<int,string>  $terpakai */
    private function kodeBaru(string $nama, array $terpakai): string
    {
        $dasar = mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $nama) ?? ''), 0, 14) ?: 'SHIFT';
        $kode = $dasar;
        for ($i = 2; in_array($kode, $terpakai, true); $i++) {
            $kode = $dasar.$i;
        }

        return $kode;
    }

    private static function warna(mixed $v): string
    {
        $w = self::s($v);
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $w)) {
            throw new HrisError('validation_failed', 'Warna harus #RRGGBB.', 422);
        }

        return $w;
    }

    private function simpan(array $blob, array $defs, string $by, string $aksi, string $kode): void
    {
        $blob['shifts'] = $defs;
        $this->jadwal->saveSetting($blob, 'HRIS:'.$by);
        $this->audit->record($by, $aksi, 'jadwal_shift', $kode, []);
    }

    public function shape(string $kode, array $d): array
    {
        $blok = [];
        foreach (PunchRules::blokDari($d) as $b) {
            $blok[] = $b;
        }
        if ($blok === [] && self::s($d['m'] ?? '') !== '') {
            $blok[] = ['mulai' => self::s($d['m']), 'selesai' => self::s($d['s'] ?? '')];
        }

        return [
            'kode' => $kode, 'nama' => self::s($d['n'] ?? $kode), 'warna' => self::s($d['w'] ?? ''),
            'mulai' => self::s($d['m'] ?? ''), 'selesai' => self::s($d['s'] ?? ''),
            'blok' => $blok, 'libur' => ! empty($d['libur']),
            'divisi' => is_array($d['divisi'] ?? null) ? array_values($d['divisi']) : [],
            'aktif' => ! array_key_exists('aktif', $d) || ! empty($d['aktif']),
            'urutan' => (int) ($d['urut'] ?? 0), 'terkunci' => in_array($kode, self::TERKUNCI, true),
        ];
    }
}
