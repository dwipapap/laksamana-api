<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisDivisiKepala;
use App\Modules\Hris\Models\HrisKaryawan;
use Illuminate\Support\Facades\DB;

/**
 * Divisi master data (E4, ADR-0012). `kode` is generated once and never
 * changes, so Jadwal and old data keep pointing at the same Divisi. Heads of
 * a shift Divisi live in Jadwal (`heads`); heads of a kantor Divisi in
 * hris_divisi_kepala. Delete is a soft delete of an empty Divisi only.
 */
final class HrisDivisiService
{
    public function __construct(
        private readonly HrisMasterBawaan $bawaan,
        private readonly HrisImporKaryawanService $impor,
        private readonly HrisJadwalSinkron $jadwal,
        private readonly HrisAuditService $audit,
        private readonly AccountRepository $users,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** @return array<int,array<string,mixed>> */
    public function list(bool $denganNonaktif = true): array
    {
        $this->bawaan->pastikan();
        $q = HrisDivisi::query()->orderBy('urutan')->orderBy('nama');
        if (! $denganNonaktif) {
            $q->where('aktif', true);
        }
        $jumlah = HrisKaryawan::query()->whereNull('tanggal_keluar')->whereNotNull('divisi_id')
            ->selectRaw('divisi_id, COUNT(*) AS n')->groupBy('divisi_id')->pluck('n', 'divisi_id')->all();

        return array_map(fn (HrisDivisi $d) => $this->shape($d, (int) ($jumlah[$d->id] ?? 0)), $q->get()->all());
    }

    public function find(string $id): HrisDivisi
    {
        $d = HrisDivisi::query()->find(self::s($id));
        if (! $d) {
            throw new HrisError('not_found', 'Divisi tidak ada.', 404);
        }

        return $d;
    }

    /** @return array<int,string> heads of one Divisi, whichever kind */
    public function kepala(HrisDivisi $d): array
    {
        if ($d->jenis === 'shift') {
            return $this->jadwal->kepalaShift($d->kode);
        }

        return HrisDivisiKepala::query()->where('divisi_id', $d->id)->orderBy('created_at')->pluck('user_id')->all();
    }

    /** @return array<string,array<int,string>> userId => ids of the Divisi they head (both kinds) */
    public function petaKepala(): array
    {
        $out = [];
        foreach (HrisDivisi::query()->get() as $d) {
            foreach ($this->kepala($d) as $uid) {
                $out[$uid][] = (string) $d->id;
            }
        }

        return $out;
    }

    public function create(array $in, string $by): HrisDivisi
    {
        $nama = mb_substr(self::s($in['nama'] ?? ''), 0, 60);
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama Divisi wajib diisi.', 422);
        }
        $this->assertNamaBebas($nama, null);
        $d = HrisDivisi::query()->create([
            'kode' => $this->impor->kodeBaru($nama), 'nama' => $nama,
            'jenis' => self::jenis($in['jenis'] ?? 'kantor'),
            'urutan' => (int) ($in['urutan'] ?? (HrisDivisi::withTrashed()->max('urutan') + 10)),
            'aktif' => true,
        ]);
        $this->audit->record($by, 'divisi_dibuat', 'hris_divisi', $d->id, ['kode' => $d->kode]);

        return $d;
    }

    public function update(string $id, array $in, string $by): HrisDivisi
    {
        $d = $this->find($id);
        self::assertVersion($d->version, $in);
        if (array_key_exists('nama', $in)) {
            $nama = mb_substr(self::s($in['nama']), 0, 60);
            if ($nama === '') {
                throw new HrisError('validation_failed', 'Nama Divisi wajib diisi.', 422);
            }
            $this->assertNamaBebas($nama, $d->id);
            $d->nama = $nama;
        }
        if (array_key_exists('jenis', $in) && self::jenis($in['jenis']) !== $d->jenis) {
            if ($this->anggota($d) > 0 || $this->kepala($d) !== []) {
                throw new HrisError('divisi_berisi', 'Jenis Divisi hanya bisa diubah saat belum ada anggota dan kepala.', 409);
            }
            $d->jenis = self::jenis($in['jenis']);
        }
        if (array_key_exists('urutan', $in)) {
            $d->urutan = (int) $in['urutan'];
        }
        if (array_key_exists('aktif', $in)) {
            $d->aktif = (bool) $in['aktif'];
        }
        $d->save();
        $this->audit->record($by, 'divisi_diubah', 'hris_divisi', $d->id, ['kode' => $d->kode]);

        return $d->fresh();
    }

    /** Soft delete; refused while anyone (still employed) is in it. */
    public function delete(string $id, string $by): void
    {
        $d = $this->find($id);
        if ($this->anggota($d) > 0) {
            throw new HrisError('divisi_berisi', 'Divisi masih punya anggota. Pindahkan semua anggota dulu.', 409);
        }
        if ($this->kepala($d) !== []) {
            throw new HrisError('divisi_berisi', 'Divisi masih punya Kepala Divisi. Kosongkan kepalanya dulu.', 409);
        }
        $d->delete();
        $this->audit->record($by, 'divisi_dihapus', 'hris_divisi', $d->id, ['kode' => $d->kode]);
    }

    /**
     * Replace the heads of one Divisi. Every head must be an employed
     * Karyawan; shift heads are written to Jadwal, kantor heads here.
     *
     * @param  array<int,string>  $userIds
     */
    public function setKepala(string $id, array $userIds, string $by): array
    {
        $d = $this->find($id);
        $ids = array_values(array_unique(array_filter(array_map(fn ($x) => self::s($x), $userIds))));
        $ada = HrisKaryawan::query()->whereIn('user_id', $ids === [] ? ['__'] : $ids)
            ->whereNull('tanggal_keluar')->pluck('user_id')->all();
        if ($bukan = array_values(array_diff($ids, $ada))) {
            throw new HrisError('validation_failed', 'Kepala Divisi harus karyawan aktif: '.implode(', ', $bukan).'.', 422);
        }
        if ($d->jenis === 'shift') {
            $this->jadwal->setKepalaShift($d->kode, $ids, $by);
        } else {
            DB::connection('core')->transaction(function () use ($d, $ids) {
                HrisDivisiKepala::query()->where('divisi_id', $d->id)->whereNotIn('user_id', $ids === [] ? ['__'] : $ids)->delete();
                foreach ($ids as $uid) {
                    HrisDivisiKepala::query()->firstOrCreate(['divisi_id' => $d->id, 'user_id' => $uid]);
                }
            });
        }
        $this->audit->record($by, 'kepala_divisi_diubah', 'hris_divisi', $d->id, ['kepala' => $ids]);

        return $this->shape($d->fresh(), $this->anggota($d));
    }

    public function anggota(HrisDivisi $d): int
    {
        return HrisKaryawan::query()->where('divisi_id', $d->id)->whereNull('tanggal_keluar')->count();
    }

    public function shape(HrisDivisi $d, int $anggota): array
    {
        $kepala = [];
        foreach ($this->kepala($d) as $uid) {
            $u = $this->users->userById($uid);
            $kepala[] = ['user_id' => $uid, 'nama' => $u ? (string) ($u['name'] ?? $uid) : $uid];
        }

        return ['id' => $d->id, 'kode' => $d->kode, 'nama' => $d->nama, 'jenis' => $d->jenis,
            'urutan' => (int) $d->urutan, 'aktif' => (bool) $d->aktif, 'anggota' => $anggota,
            'kepala' => $kepala, 'berubah' => (int) $d->version];
    }

    private function assertNamaBebas(string $nama, ?string $kecuali): void
    {
        $q = HrisDivisi::query()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)]);
        if ($kecuali !== null) {
            $q->where('id', '<>', $kecuali);
        }
        if ($q->exists()) {
            throw new HrisError('validation_failed', 'Nama Divisi sudah dipakai.', 422);
        }
    }

    private static function jenis(mixed $v): string
    {
        $j = strtolower(self::s($v));
        if (! in_array($j, ['shift', 'kantor'], true)) {
            throw new HrisError('validation_failed', 'Jenis Divisi harus shift atau kantor.', 422);
        }

        return $j;
    }

    /** `berubah` (alias `version`) must match when sent: a stale tab gets a 409. */
    public static function assertVersion(mixed $current, array $in): void
    {
        $v = $in['berubah'] ?? $in['version'] ?? null;
        if ($v !== null && (int) $v !== (int) $current) {
            throw new HrisError('conflict', 'Data sudah diubah orang lain. Muat ulang dulu.', 409);
        }
    }
}
