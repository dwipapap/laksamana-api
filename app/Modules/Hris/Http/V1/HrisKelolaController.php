<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisDivisiService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisJabatanService;
use App\Modules\Hris\Services\HrisKaryawanService;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisShiftService;
use App\Modules\Hris\Services\PunchRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris/kelola — Karyawan, org chart, Divisi, Jabatan (E1–E9) and
 * master shift (J1–J4), ADR-0012.
 *
 *   Pengelola  HR (HRIS admins + `hr` list) and Pengelola Roster (admins of
 *              jadwal, Tim HRD): read everything, add and change Karyawan.
 *   HR         Divisi, Jabatan and shift master data (shifts are readable
 *              by every HRIS user: they carry no personal data).
 *   Kepala     a Kepala Divisi: their Divisi's Karyawan and their own org
 *              subtree, without personal data; no writes.
 *   Crew       nothing here (Q26).
 */
final class HrisKelolaController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisLingkup $lingkup,
        private readonly HrisKaryawanService $karyawan,
        private readonly HrisDivisiService $divisi,
        private readonly HrisJabatanService $jabatan,
        private readonly HrisShiftService $shift,
    ) {
        parent::__construct($access);
    }

    private function pengelola(array $me): bool
    {
        return $this->lingkup->isHr($me) || $this->access->isModuleAdmin(self::s($me['id'] ?? ''), 'jadwal');
    }

    private function kepala(array $me): bool
    {
        $id = self::s($me['id'] ?? '');

        return $this->karyawan->isKepala($id) || $this->lingkup->isHead($me);
    }

    private function assertPengelola(array $me, string $apa): void
    {
        if (! $this->pengelola($me)) {
            throw new HrisError('forbidden', $apa.' hanya untuk HR dan Pengelola Roster.', 403);
        }
    }

    /** @return array<int,string>|null null = everyone (pengelola) */
    private function scopeBaca(array $me): ?array
    {
        if ($this->pengelola($me)) {
            return null;
        }
        if ($this->kepala($me)) {
            return $this->karyawan->anggotaKepala(self::s($me['id']));
        }
        throw new HrisError('forbidden', 'Data karyawan hanya untuk HR dan Kepala Divisi.', 403);
    }

    private static function by(array $me): string
    {
        return self::s($me['name'] ?? '');
    }

    // ---------------------------------------------------------------- karyawan

    public function index(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $f = $r->validate(['status' => ['nullable', 'in:aktif,keluar,semua'], 'divisi' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:120']]);

        return $this->run(fn () => $this->karyawan->list($this->scopeBaca($me), $this->pengelola($me), $f));
    }

    public function show(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $userId) {
            $scope = $this->scopeBaca($me);
            if ($scope !== null && ! in_array($userId, $scope, true)) {
                throw new HrisError('forbidden', 'Tidak berhak melihat karyawan ini.', 403);
            }

            return $this->karyawan->detail($userId, $this->pengelola($me));
        });
    }

    public function calon(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->assertPengelola($me, 'Daftar calon karyawan');

            return $this->karyawan->calon();
        });
    }

    public function store(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate($this->aturan() + [
            'nama' => ['required', 'string', 'max:120'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'tim' => ['nullable', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'max:190'],
        ]);

        return $this->run(function () use ($me, $d) {
            $this->assertPengelola($me, 'Menambah karyawan');

            return $this->karyawan->create($d, self::by($me));
        }, 201);
    }

    public function jadikan(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate($this->aturan());

        return $this->run(function () use ($me, $userId, $d) {
            $this->assertPengelola($me, 'Menjadikan karyawan');

            return $this->karyawan->jadikan($userId, $d, self::by($me));
        }, 201);
    }

    public function update(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate($this->aturan() + [
            'berlaku_dari' => ['nullable', 'date_format:Y-m-d'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'berubah' => ['nullable', 'integer'],
        ]);

        return $this->run(function () use ($me, $userId, $d) {
            $this->assertPengelola($me, 'Mengubah karyawan');

            return $this->karyawan->update($userId, $d, self::by($me));
        });
    }

    public function keluar(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['tanggal' => ['required', 'date_format:Y-m-d'], 'alasan' => ['required', 'string', 'max:16'],
            'catatan' => ['nullable', 'string', 'max:255'], 'berubah' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $userId, $d) {
            $this->assertPengelola($me, 'Mencatat karyawan keluar');

            return $this->karyawan->keluar($userId, $d, self::by($me));
        });
    }

    public function batalKeluar(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $userId) {
            $this->assertPengelola($me, 'Membatalkan karyawan keluar');

            return $this->karyawan->batalKeluar($userId, self::by($me));
        });
    }

    public function struktur(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            if ($this->pengelola($me)) {
                return $this->karyawan->struktur();
            }
            if ($this->kepala($me)) {
                return $this->karyawan->struktur(self::s($me['id']));
            }
            throw new HrisError('forbidden', 'Struktur organisasi hanya untuk HR dan Kepala Divisi.', 403);
        });
    }

    /** @return array<string,array<int,string>> */
    private function aturan(): array
    {
        return [
            'divisi_id' => ['nullable', 'string', 'max:26'],
            'jabatan_id' => ['nullable', 'string', 'max:26'],
            'atasan_user_id' => ['nullable', 'string', 'max:64'],
            'status_kerja' => ['nullable', 'string', 'max:12'],
            'nik' => ['nullable', 'string', 'max:24'],
            'jenis_kelamin' => ['nullable', 'string', 'max:1'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kontak_darurat_nama' => ['nullable', 'string', 'max:120'],
            'kontak_darurat_hp' => ['nullable', 'string', 'max:32'],
            'tanggal_gabung' => ['nullable', 'date_format:Y-m-d'],
            'akhir_percobaan' => ['nullable', 'date_format:Y-m-d'],
            'akhir_kontrak' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    // ------------------------------------------------------------------ divisi

    public function divisiIndex(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->scopeBaca($me);

            return $this->divisi->list();
        });
    }

    public function divisiStore(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['nama' => ['required', 'string', 'max:60'], 'jenis' => ['required', 'in:shift,kantor'],
            'urutan' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Membuat Divisi');
            $div = $this->divisi->create($d, self::by($me));

            return $this->divisi->shape($div, 0);
        }, 201);
    }

    public function divisiUpdate(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['nama' => ['sometimes', 'string', 'max:60'], 'jenis' => ['sometimes', 'in:shift,kantor'],
            'urutan' => ['sometimes', 'integer'], 'aktif' => ['sometimes', 'boolean'], 'berubah' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $id, $d) {
            $this->lingkup->assertHr($me, 'Mengubah Divisi');
            $div = $this->divisi->update($id, $d, self::by($me));

            return $this->divisi->shape($div, $this->divisi->anggota($div));
        });
    }

    public function divisiDestroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Menghapus Divisi');
            $this->divisi->delete($id, self::by($me));

            return ['dihapus' => true];
        });
    }

    public function divisiKepala(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['string', 'max:64']]);

        return $this->run(function () use ($me, $id, $d) {
            $this->lingkup->assertHr($me, 'Mengatur Kepala Divisi');

            return $this->divisi->setKepala($id, $d['user_ids'], self::by($me));
        });
    }

    public function divisiPindahkan(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['ke' => ['required', 'string', 'max:26'], 'berlaku_dari' => ['nullable', 'date_format:Y-m-d']]);

        return $this->run(function () use ($me, $id, $d) {
            $this->lingkup->assertHr($me, 'Memindahkan anggota Divisi');
            $this->divisi->find($id);
            $n = $this->karyawan->pindahkanDivisi($id, $d['ke'],
                $d['berlaku_dari'] ?? PunchRules::tglWib(PunchRules::ms()), self::by($me));

            return ['dipindah' => $n];
        });
    }

    // ----------------------------------------------------------------- jabatan

    public function jabatanIndex(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->scopeBaca($me);

            return $this->jabatan->list();
        });
    }

    public function jabatanStore(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['nama' => ['required', 'string', 'max:120'], 'level_id' => ['required', 'string', 'max:26'],
            'divisi_id' => ['nullable', 'string', 'max:26'], 'urutan' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Membuat jabatan');

            return $this->jabatan->shape($this->jabatan->create($d, self::by($me))->load('level'));
        }, 201);
    }

    public function jabatanUpdate(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['nama' => ['sometimes', 'string', 'max:120'], 'level_id' => ['sometimes', 'string', 'max:26'],
            'divisi_id' => ['sometimes', 'nullable', 'string', 'max:26'], 'urutan' => ['sometimes', 'integer'],
            'aktif' => ['sometimes', 'boolean'], 'berubah' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $id, $d) {
            $this->lingkup->assertHr($me, 'Mengubah jabatan');

            return $this->jabatan->shape($this->jabatan->update($id, $d, self::by($me)));
        });
    }

    public function jabatanDestroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Menghapus jabatan');
            $this->jabatan->delete($id, self::by($me));

            return ['dihapus' => true];
        });
    }

    public function levelStore(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['nama' => ['required', 'string', 'max:60'], 'urutan' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Membuat level jabatan');

            return $this->jabatan->shapeLevel($this->jabatan->createLevel($d, self::by($me)));
        }, 201);
    }

    public function levelUpdate(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['nama' => ['sometimes', 'string', 'max:60'], 'urutan' => ['sometimes', 'integer'],
            'aktif' => ['sometimes', 'boolean'], 'berubah' => ['nullable', 'integer']]);

        return $this->run(function () use ($me, $id, $d) {
            $this->lingkup->assertHr($me, 'Mengubah level jabatan');

            return $this->jabatan->shapeLevel($this->jabatan->updateLevel($id, $d, self::by($me)));
        });
    }

    public function levelDestroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Menghapus level jabatan');
            $this->jabatan->deleteLevel($id, self::by($me));

            return ['dihapus' => true];
        });
    }

    // ------------------------------------------------------------------- shift

    public function shiftIndex(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->shift->list());
    }

    public function shiftStore(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate($this->aturanShift() + ['nama' => ['required', 'string', 'max:32']]);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Membuat shift');

            return $this->shift->create($d, self::by($me));
        }, 201);
    }

    public function shiftUpdate(Request $r, string $kode): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate($this->aturanShift() + ['nama' => ['sometimes', 'string', 'max:32'],
            'urutan' => ['sometimes', 'integer'], 'aktif' => ['sometimes', 'boolean']]);

        return $this->run(function () use ($me, $kode, $d) {
            $this->lingkup->assertHr($me, 'Mengubah shift');

            return $this->shift->update($kode, $d, self::by($me));
        });
    }

    public function shiftDestroy(Request $r, string $kode): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $kode) {
            $this->lingkup->assertHr($me, 'Menghapus shift');
            $this->shift->delete($kode, self::by($me));

            return ['dihapus' => true];
        });
    }

    /** @return array<string,array<int,string>> */
    private function aturanShift(): array
    {
        return [
            'warna' => ['sometimes', 'string', 'max:7'],
            'libur' => ['sometimes', 'boolean'],
            'blok' => ['sometimes', 'array', 'max:3'],
            'blok.*.mulai' => ['required_with:blok', 'string', 'max:5'],
            'blok.*.selesai' => ['required_with:blok', 'string', 'max:5'],
            'divisi' => ['sometimes', 'array'],
            'divisi.*' => ['string', 'max:32'],
        ];
    }
}
