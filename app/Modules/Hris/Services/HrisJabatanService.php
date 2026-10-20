<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisJabatan;
use App\Modules\Hris\Models\HrisJabatanLevel;
use App\Modules\Hris\Models\HrisKaryawan;

/**
 * Jabatan and Level Jabatan (E6, ADR-0012): master lists HR edits in
 * /kelola/jabatan. Both are soft-deleted, and only while nothing uses them.
 */
final class HrisJabatanService
{
    public function __construct(
        private readonly HrisMasterBawaan $bawaan,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** @return array{level:array<int,array<string,mixed>>, jabatan:array<int,array<string,mixed>>} */
    public function list(): array
    {
        $this->bawaan->pastikan();
        $level = HrisJabatanLevel::query()->orderBy('urutan')->get();
        $pakai = HrisKaryawan::query()->whereNull('tanggal_keluar')->whereNotNull('jabatan_id')
            ->selectRaw('jabatan_id, COUNT(*) AS n')->groupBy('jabatan_id')->pluck('n', 'jabatan_id')->all();
        $jabatan = HrisJabatan::query()->with('level')->get()
            ->sortBy(fn (HrisJabatan $j) => sprintf('%05d|%05d|%s', 99999 - (int) ($j->level?->urutan ?? 0), $j->urutan, mb_strtolower($j->nama)))
            ->values();

        return [
            'level' => $level->map(fn (HrisJabatanLevel $l) => $this->shapeLevel($l))->all(),
            'jabatan' => $jabatan->map(fn (HrisJabatan $j) => $this->shape($j, (int) ($pakai[$j->id] ?? 0)))->all(),
        ];
    }

    // ---------------------------------------------------------------- levels

    public function createLevel(array $in, string $by): HrisJabatanLevel
    {
        $nama = $this->namaWajib($in, 60);
        $l = HrisJabatanLevel::query()->create(['nama' => $nama,
            'urutan' => (int) ($in['urutan'] ?? (HrisJabatanLevel::withTrashed()->max('urutan') + 1)), 'aktif' => true]);
        $this->audit->record($by, 'level_jabatan_dibuat', 'hris_jabatan_level', $l->id, []);

        return $l;
    }

    public function updateLevel(string $id, array $in, string $by): HrisJabatanLevel
    {
        $l = HrisJabatanLevel::query()->find(self::s($id)) ?? throw new HrisError('not_found', 'Level tidak ada.', 404);
        HrisDivisiService::assertVersion($l->version, $in);
        if (array_key_exists('nama', $in)) {
            $l->nama = $this->namaWajib($in, 60);
        }
        foreach (['urutan' => 'int', 'aktif' => 'bool'] as $k => $t) {
            if (array_key_exists($k, $in)) {
                $l->{$k} = $t === 'int' ? (int) $in[$k] : (bool) $in[$k];
            }
        }
        $l->save();
        $this->audit->record($by, 'level_jabatan_diubah', 'hris_jabatan_level', $l->id, []);

        return $l->fresh();
    }

    public function deleteLevel(string $id, string $by): void
    {
        $l = HrisJabatanLevel::query()->find(self::s($id)) ?? throw new HrisError('not_found', 'Level tidak ada.', 404);
        if (HrisJabatan::query()->where('level_id', $l->id)->exists()) {
            throw new HrisError('level_dipakai', 'Level masih dipakai jabatan. Pindahkan jabatannya dulu.', 409);
        }
        $l->delete();
        $this->audit->record($by, 'level_jabatan_dihapus', 'hris_jabatan_level', $l->id, []);
    }

    // --------------------------------------------------------------- jabatan

    public function create(array $in, string $by): HrisJabatan
    {
        $nama = $this->namaWajib($in, 120);
        $this->assertNamaBebas($nama, null);
        $j = HrisJabatan::query()->create(['nama' => $nama, 'level_id' => $this->levelId($in['level_id'] ?? ''),
            'divisi_id' => $this->divisiId($in['divisi_id'] ?? null), 'urutan' => (int) ($in['urutan'] ?? 0), 'aktif' => true]);
        $this->audit->record($by, 'jabatan_dibuat', 'hris_jabatan', $j->id, []);

        return $j;
    }

    public function update(string $id, array $in, string $by): HrisJabatan
    {
        $j = $this->find($id);
        HrisDivisiService::assertVersion($j->version, $in);
        if (array_key_exists('nama', $in)) {
            $nama = $this->namaWajib($in, 120);
            $this->assertNamaBebas($nama, $j->id);
            $j->nama = $nama;
        }
        if (array_key_exists('level_id', $in)) {
            $j->level_id = $this->levelId($in['level_id']);
        }
        if (array_key_exists('divisi_id', $in)) {
            $j->divisi_id = $this->divisiId($in['divisi_id']);
        }
        foreach (['urutan' => 'int', 'aktif' => 'bool'] as $k => $t) {
            if (array_key_exists($k, $in)) {
                $j->{$k} = $t === 'int' ? (int) $in[$k] : (bool) $in[$k];
            }
        }
        $j->save();
        $this->audit->record($by, 'jabatan_diubah', 'hris_jabatan', $j->id, []);

        return $j->fresh('level');
    }

    public function delete(string $id, string $by): void
    {
        $j = $this->find($id);
        if (HrisKaryawan::query()->where('jabatan_id', $j->id)->exists()) {
            throw new HrisError('jabatan_dipakai', 'Jabatan masih dipakai karyawan.', 409);
        }
        $j->delete();
        $this->audit->record($by, 'jabatan_dihapus', 'hris_jabatan', $j->id, []);
    }

    public function find(string $id): HrisJabatan
    {
        return HrisJabatan::query()->with('level')->find(self::s($id))
            ?? throw new HrisError('not_found', 'Jabatan tidak ada.', 404);
    }

    public function shape(HrisJabatan $j, int $dipakai = 0): array
    {
        return ['id' => $j->id, 'nama' => $j->nama, 'level_id' => $j->level_id,
            'level' => $j->level?->nama, 'level_urutan' => (int) ($j->level?->urutan ?? 0),
            'divisi_id' => $j->divisi_id, 'urutan' => (int) $j->urutan, 'aktif' => (bool) $j->aktif,
            'dipakai' => $dipakai, 'berubah' => (int) $j->version];
    }

    public function shapeLevel(HrisJabatanLevel $l): array
    {
        return ['id' => $l->id, 'nama' => $l->nama, 'urutan' => (int) $l->urutan,
            'aktif' => (bool) $l->aktif, 'berubah' => (int) $l->version];
    }

    private function namaWajib(array $in, int $max): string
    {
        $nama = mb_substr(preg_replace('/\s+/', ' ', self::s($in['nama'] ?? '')) ?? '', 0, $max);
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama wajib diisi.', 422);
        }

        return $nama;
    }

    private function assertNamaBebas(string $nama, ?string $kecuali): void
    {
        $q = HrisJabatan::withTrashed()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)]);
        if ($kecuali !== null) {
            $q->where('id', '<>', $kecuali);
        }
        if ($q->exists()) {
            throw new HrisError('validation_failed', 'Nama jabatan sudah dipakai.', 422);
        }
    }

    private function levelId(mixed $id): string
    {
        $id = self::s($id);
        if ($id === '' || ! HrisJabatanLevel::query()->whereKey($id)->exists()) {
            throw new HrisError('validation_failed', 'Level jabatan tidak dikenal.', 422);
        }

        return $id;
    }

    private function divisiId(mixed $id): ?string
    {
        $id = self::s($id);
        if ($id === '') {
            return null;
        }
        if (! HrisDivisi::query()->whereKey($id)->exists()) {
            throw new HrisError('validation_failed', 'Divisi tidak dikenal.', 422);
        }

        return $id;
    }
}
