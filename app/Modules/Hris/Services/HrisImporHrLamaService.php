<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisKpiItem;
use App\Modules\Hris\Models\HrisKpiRealisasi;
use App\Modules\Hris\Models\HrisKpiTemplate;
use App\Modules\Hris\Models\HrisPelanggaran;
use App\Modules\Hris\Models\HrisPenilaianAtasan;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * One-time (idempotent) move of the remaining legacy HR panel data into the
 * HRIS tables that already exist (ADR-0012 E11, Q22/Q32):
 *
 *   violations                        -> hris_pelanggaran
 *   kpi_templates + kpi_actuals       -> hris_kpi_template/item/realisasi
 *   reviews (layer `hr`)              -> hris_penilaian_atasan
 *
 * career_paths and empty tables stay behind. People are matched through the
 * legacy employee's talentaId -> `users.talenta_id`, the same identity the
 * Karyawan importer uses. Rows are never overwritten: an existing natural
 * key wins and the report names whatever could not be mapped.
 */
final class HrisImporHrLamaService
{
    /** Legacy HR tables have no effective date; the earliest possible one lets
     *  the imported KPI template cover the historic realisations. A newer
     *  template written by HR takes over from its own date. */
    public const KPI_BERLAKU_DARI = '2020-01-01';

    private const TINGKAT = ['ringan' => 'RINGAN', 'sedang' => 'SEDANG', 'berat' => 'BERAT'];

    private const STATUS_PELANGGARAN = [
        'tercatat' => 'AKTIF', 'aktif' => 'AKTIF',
        'dibatalkan' => 'DIBATALKAN', 'batal' => 'DIBATALKAN',
    ];

    public function __construct(
        private readonly AccountRepository $users,
        private readonly HrisAuditService $audit,
        private readonly HrisImporKaryawanService $karyawan,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /**
     * @return array<string,mixed>
     */
    public function impor(string $by, bool $coba = false): array
    {
        $lapor = [
            'pelanggaran_dibuat' => 0, 'pelanggaran_sudah_ada' => 0,
            'pelanggaran_tanpa_orang' => [], 'pelanggaran_tidak_valid' => [],
            'kpi_template_dibuat' => 0, 'kpi_template_sudah_ada' => 0,
            'kpi_item_dibuat' => 0, 'kpi_realisasi_dibuat' => 0, 'kpi_realisasi_sudah_ada' => 0,
            'kpi_tanpa_divisi' => [], 'kpi_item_dilewati' => [], 'kpi_realisasi_tanpa_item' => [],
            'penilaian_dibuat' => 0, 'penilaian_sudah_ada' => 0,
            'penilaian_tanpa_orang' => [], 'penilaian_tidak_valid' => [],
            'divisi_dibuat' => [],
        ];

        $employees = $this->hrEmployees();
        $talenta = $this->usersByTalenta();

        DB::connection('core')->beginTransaction();
        try {
            $this->imporPelanggaran($by, $employees, $talenta, $lapor);
            $this->imporKpi($by, $lapor);
            $this->imporReview($by, $employees, $talenta, $lapor);

            $this->audit->record($by, 'hr_lama_diimpor', '', '', [
                'pelanggaran' => $lapor['pelanggaran_dibuat'],
                'kpi_template' => $lapor['kpi_template_dibuat'],
                'kpi_realisasi' => $lapor['kpi_realisasi_dibuat'],
                'penilaian' => $lapor['penilaian_dibuat'],
                'coba' => $coba,
            ]);

            if ($coba) {
                DB::connection('core')->rollBack();
            } else {
                DB::connection('core')->commit();
            }
        } catch (\Throwable $e) {
            DB::connection('core')->rollBack();
            throw $e;
        }

        return $lapor;
    }

    // ------------------------------------------------------------ violations

    /** @param  array<string,object>  $employees  @param  array<string,string>  $talenta */
    private function imporPelanggaran(string $by, array $employees, array $talenta, array &$lapor): void
    {
        foreach ($this->hrRows('violations') as $legacyId => $d) {
            $empId = self::s($d->empId ?? '');
            $uid = $this->userId($empId, $employees, $talenta);
            if ($uid === null) {
                $lapor['pelanggaran_tanpa_orang'][] = $legacyId.' ('.$empId.')';

                continue;
            }
            $tgl = PunchRules::tglValid($d->date ?? '');
            if ($tgl === '') {
                $lapor['pelanggaran_tidak_valid'][] = $legacyId.': tanggal '.self::s($d->date ?? '');

                continue;
            }
            $tingkat = self::TINGKAT[mb_strtolower(self::s($d->severity ?? ''))] ?? 'RINGAN';
            $jenis = mb_substr(self::s($d->type ?? ''), 0, 64);
            $spKe = preg_match('/\d+/', self::s($d->sp ?? ''), $m) === 1 ? (int) $m[0] : 0;
            $status = self::STATUS_PELANGGARAN[mb_strtolower(self::s($d->status ?? ''))] ?? 'AKTIF';

            $ada = HrisPelanggaran::query()->where('user_id', $uid)->where('tanggal', $tgl)
                ->where('jenis', $jenis)->where('sp_ke', $spKe)->where('tingkat', $tingkat)->exists();
            if ($ada) {
                $lapor['pelanggaran_sudah_ada']++;

                continue;
            }
            HrisPelanggaran::query()->create([
                'user_id' => $uid, 'tanggal' => $tgl, 'jenis' => $jenis,
                'tingkat' => $tingkat, 'sp_ke' => max(0, $spKe),
                'keterangan' => mb_substr(self::s($d->note ?? ''), 0, 255),
                'status' => $status, 'dibuat_oleh' => mb_substr($by, 0, 120),
            ]);
            $lapor['pelanggaran_dibuat']++;
        }
    }

    // ------------------------------------------------------------------- KPI

    private function imporKpi(string $by, array &$lapor): void
    {
        /** @var array<string,HrisKpiItem> $peta legacy item id => HRIS item */
        $peta = [];
        foreach ($this->hrRows('kpi_templates') as $legacyId => $d) {
            $divId = self::s($d->divId ?? '');
            $kode = $divId === '' ? null : $this->divisiKode($divId, $lapor);
            if ($kode === null) {
                $lapor['kpi_tanpa_divisi'][] = $legacyId.' ('.$divId.')';

                continue;
            }
            $item = is_array($d->items ?? null) ? $d->items : [];
            $baris = [];
            foreach ($item as $it) {
                $it = is_array($it) ? $it : (array) $it;
                $nama = mb_substr(self::s($it['name'] ?? ''), 0, 120);
                if ($nama === '') {
                    $lapor['kpi_item_dilewati'][] = $legacyId.': item tanpa nama';

                    continue;
                }
                $arah = match (strtolower(self::s($it['dir'] ?? 'up'))) {
                    'up', 'naik' => 'NAIK',
                    'down', 'turun' => 'TURUN',
                    default => null,
                };
                if ($arah === null) {
                    $lapor['kpi_item_dilewati'][] = $legacyId.' '.$nama.': arah '.self::s($it['dir'] ?? '');

                    continue;
                }
                $baris[] = [
                    'legacy' => self::s($it['id'] ?? ''), 'nama' => $nama, 'arah' => $arah,
                    'target' => (float) ($it['target'] ?? 0), 'bobot' => max(0.0, (float) ($it['weight'] ?? 0)),
                ];
            }
            if ($baris === []) {
                $lapor['kpi_item_dilewati'][] = $legacyId.': tidak ada item yang bisa dipetakan';

                continue;
            }

            $t = HrisKpiTemplate::query()->firstOrCreate(
                ['divisi' => $kode, 'berlaku_dari' => self::KPI_BERLAKU_DARI],
                ['dibuat_oleh' => mb_substr($by, 0, 120)],
            );
            if ($t->wasRecentlyCreated) {
                $lapor['kpi_template_dibuat']++;
            } else {
                $lapor['kpi_template_sudah_ada']++;
            }

            foreach ($baris as $b) {
                $row = HrisKpiItem::query()->firstOrCreate(
                    ['template_id' => $t->id, 'nama' => $b['nama']],
                    ['target' => $b['target'], 'arah' => $b['arah'], 'bobot' => $b['bobot'],
                        'sumber' => 'MANUAL', 'kunci_sumber' => null],
                );
                if ($row->wasRecentlyCreated) {
                    $lapor['kpi_item_dibuat']++;
                }
                if ($b['legacy'] !== '') {
                    $peta[$b['legacy']] = $row;
                }
            }
        }

        foreach ($this->hrTable('kpi_actuals') as $r) {
            $bulan = HrisMealService::bulanValid($r->bulan ?? '');
            $itemId = self::s($r->item_id ?? '');
            $item = $peta[$itemId] ?? null;
            if ($bulan === '' || $item === null) {
                $lapor['kpi_realisasi_tanpa_item'][] = $itemId.' ('.self::s($r->div_id ?? '').' '.self::s($r->bulan ?? '').')';

                continue;
            }
            $nilai = $r->nilai === null ? null : (float) $r->nilai;
            if ($nilai === null) {
                $lapor['kpi_realisasi_tanpa_item'][] = $itemId.' ('.self::s($r->bulan ?? '').'): nilai kosong';

                continue;
            }
            $row = HrisKpiRealisasi::query()->firstOrCreate(
                ['item_id' => $item->id, 'bulan' => $bulan],
                ['nilai' => $nilai, 'diinput_oleh' => mb_substr($by, 0, 120)],
            );
            if ($row->wasRecentlyCreated) {
                $lapor['kpi_realisasi_dibuat']++;
            } else {
                $lapor['kpi_realisasi_sudah_ada']++;
            }
        }
    }

    // --------------------------------------------------------------- reviews

    /** @param  array<string,object>  $employees  @param  array<string,string>  $talenta */
    private function imporReview(string $by, array $employees, array $talenta, array &$lapor): void
    {
        foreach ($this->hrRows('reviews') as $legacyId => $d) {
            $empId = self::s($d->empId ?? '');
            $uid = $this->userId($empId, $employees, $talenta);
            if ($uid === null) {
                $lapor['penilaian_tanpa_orang'][] = $legacyId.' ('.$empId.')';

                continue;
            }
            $bulan = HrisMealService::bulanValid($d->month ?? '');
            if ($bulan === '') {
                $lapor['penilaian_tidak_valid'][] = $legacyId.': bulan '.self::s($d->month ?? '');

                continue;
            }
            $layer = $this->lapisanReview($d);
            if ($layer === null) {
                $lapor['penilaian_tidak_valid'][] = $legacyId.': tanpa aspek';

                continue;
            }
            $aspek = $this->aspekReview($layer['aspects'] ?? [], $lapor, $legacyId);
            if ($aspek === null) {
                continue;
            }
            $ada = HrisPenilaianAtasan::query()->where('bulan', $bulan)->where('user_id', $uid)->exists();
            if ($ada) {
                $lapor['penilaian_sudah_ada']++;

                continue;
            }
            HrisPenilaianAtasan::query()->create([
                'bulan' => $bulan, 'user_id' => $uid,
                'penilai_id' => mb_substr(self::s($layer['by'] ?? ''), 0, 64),
                'aspek' => $aspek,
            ]);
            $lapor['penilaian_dibuat']++;
        }
    }

    /** Prefer the HR layer; fall back to the first layer carrying aspects. @return array<string,mixed>|null */
    private function lapisanReview(object $d): ?array
    {
        $layers = $d->layers ?? null;
        if (! is_object($layers)) {
            return null;
        }
        $semua = (array) $layers;
        $hr = $semua['hr'] ?? null;
        if (is_object($hr)) {
            $aspek = $this->aspekArray($hr->aspects ?? null);
            if ($aspek !== []) {
                return ['aspects' => $aspek, 'by' => $hr->by ?? ''];
            }
        }
        foreach ($semua as $l) {
            if (! is_object($l)) {
                continue;
            }
            $aspek = $this->aspekArray($l->aspects ?? null);
            if ($aspek !== []) {
                return ['aspects' => $aspek, 'by' => $l->by ?? ''];
            }
        }

        return null;
    }

    /** Legacy `aspects` decodes as an object; normalise it to a name => value array. */
    private function aspekArray(mixed $v): array
    {
        if (is_object($v)) {
            return (array) $v;
        }

        return is_array($v) ? $v : [];
    }

    /**
     * Legacy aspects are 1–7 (detected from the row); HRIS stores 1–5.
     * Non-numeric or out-of-range values skip the review instead of guessing.
     *
     * @param  array<string,mixed>  $aspects
     * @return array<string,int>|null
     */
    private function aspekReview(array $aspects, array &$lapor, string $legacyId): ?array
    {
        $angka = [];
        foreach ($aspects as $nama => $nilai) {
            $nama = mb_substr(self::s($nama), 0, 64);
            if ($nama === '' || ! is_numeric($nilai)) {
                $lapor['penilaian_tidak_valid'][] = $legacyId.': aspek '.self::s($nama).' bukan angka';

                return null;
            }
            $angka[$nama] = (float) $nilai;
        }
        if ($angka === []) {
            $lapor['penilaian_tidak_valid'][] = $legacyId.': aspek kosong';

            return null;
        }
        $maks = max($angka);
        if ($maks > 7 || min($angka) < 1) {
            $lapor['penilaian_tidak_valid'][] = $legacyId.': skala di luar 1–7';

            return null;
        }
        $skala = $maks > 5 ? 7 : 5;
        $out = [];
        foreach ($angka as $nama => $v) {
            $out[$nama] = $skala === 5
                ? (int) round($v)
                : (int) max(1, min(5, round(1 + ($v - 1) * 4 / 6)));
        }

        return $out;
    }

    // --------------------------------------------------------------- helpers

    /** Legacy employee id => data (talentaId lives here). @return array<string,object> */
    private function hrEmployees(): array
    {
        return $this->hrRows('employees');
    }

    /** talenta_id => Office user id. @return array<string,string> */
    private function usersByTalenta(): array
    {
        $out = [];
        try {
            foreach ($this->users->allUsers() as $u) {
                $t = self::s($u['talenta_id'] ?? '');
                $id = self::s($u['id'] ?? '');
                if ($t !== '' && $id !== '') {
                    $out[$t] = $id;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /** @param  array<string,object>  $employees  @param  array<string,string>  $talenta */
    private function userId(string $empId, array $employees, array $talenta): ?string
    {
        $t = self::s($employees[$empId]->talentaId ?? '');

        return $t === '' ? null : ($talenta[$t] ?? null);
    }

    /** Legacy HR division id => HRIS Divisi kode, creating a kantor Divisi on demand. */
    private function divisiKode(string $hrId, array &$lapor): ?string
    {
        $shift = HrisImporKaryawanService::HR_DIVISI_SHIFT[$hrId] ?? null;
        if ($shift !== null) {
            return $shift;
        }
        $nama = self::s($this->hrRows('divisions')[$hrId]->name ?? '');
        if ($nama === '') {
            return null;
        }
        $row = HrisDivisi::withTrashed()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first();
        if (! $row) {
            $row = HrisDivisi::query()->create([
                'kode' => $this->karyawan->kodeBaru($nama), 'nama' => mb_substr($nama, 0, 60),
                'jenis' => 'kantor', 'urutan' => 100, 'aktif' => true,
            ]);
            $lapor['divisi_dibuat'][] = $nama;
        }

        return (string) $row->kode;
    }

    /** @return array<string,object> id => decoded `data`; [] when unreadable */
    private function hrRows(string $table): array
    {
        $out = [];
        foreach ($this->hrTable($table) as $r) {
            $d = json_decode((string) ($r->data ?? ''));
            $out[(string) ($r->id ?? '')] = is_object($d) ? $d : (object) [];
        }

        return $out;
    }

    /** @return iterable<int,object> */
    private function hrTable(string $table): iterable
    {
        try {
            return Modules::db('hr')->table($table)->get();
        } catch (\Throwable) {
            return [];
        }
    }
}
