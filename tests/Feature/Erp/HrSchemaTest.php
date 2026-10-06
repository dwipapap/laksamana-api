<?php

use App\Erp\Master\Models\Karyawan;
use App\Erp\Sdm\Models\ImporKehadiran;
use App\Erp\Sdm\Models\InputBulananHr;
use App\Erp\Sdm\Models\KehadiranTalenta;
use App\Erp\Sdm\Models\KpiItem;
use App\Erp\Sdm\Models\KpiRealisasi;
use App\Erp\Sdm\Models\KpiTemplate;
use App\Erp\Sdm\Models\PengaturanHr;
use App\Erp\Sdm\Models\PengaturanHrGrade;
use App\Erp\Sdm\Models\ReviewKinerja;
use App\Erp\Sdm\Models\ReviewKinerjaNilai;
use App\Erp\Sdm\Models\SkorKinerja;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * HR (docs/erp/hr.md): the rules the database enforces on its own. The
 * People Score is computed by a service.
 */

function hrKaryawan(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => 'Kru '.$id, 'pin' => '0000', 'talenta_id' => 'T'.substr($id, -5),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Karyawan::create(['user_id' => $id]);

    return $id;
}

function hrDivisi(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('divisi')->insert(['id' => $id, 'kode' => 'uji_'.substr($id, -6), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('records a month of KPI, reviews and the score copied when the month closes', function () {
    $kru = hrKaryawan();
    $atur = PengaturanHr::create(['berlaku_dari' => '2000-01-01', 'bobot_kpi' => 30, 'nilai_bawaan' => 77]);
    PengaturanHrGrade::create(['pengaturan_hr_id' => $atur->id, 'grade' => 'A', 'skor_min' => 85]);
    $tpl = KpiTemplate::create(['divisi_id' => hrDivisi(), 'berlaku_dari' => '2026-01-01']);
    $waste = KpiItem::create(['kpi_template_id' => $tpl->id, 'nama' => 'Waste %', 'target' => 2, 'arah' => 'turun', 'bobot' => 2]);
    KpiRealisasi::create(['kpi_item_id' => $waste->id, 'bulan' => '2026-09-01', 'nilai' => 1.5]);
    $rv = ReviewKinerja::create(['user_id' => $kru, 'bulan' => '2026-09-01']);
    ReviewKinerjaNilai::create(['review_kinerja_id' => $rv->id, 'lapisan' => 'manager', 'aspek' => 'Initiative', 'nilai' => 8]);
    InputBulananHr::create(['user_id' => $kru, 'bulan' => '2026-09-01', 'kpi_override' => 110]);
    SkorKinerja::create(['user_id' => $kru, 'bulan' => '2026-09-01', 'skor' => 82.5, 'grade' => 'B', 'kelengkapan' => 71, 'kpi' => 100, 'ditutup_at' => now()]);

    expect(fn () => SkorKinerja::create(['user_id' => $kru, 'bulan' => '2026-09-01', 'skor' => 1, 'kelengkapan' => 1, 'ditutup_at' => now()]))->toThrow(QueryException::class)
        ->and(fn () => KpiRealisasi::create(['kpi_item_id' => $waste->id, 'bulan' => '2026-09-01', 'nilai' => 2]))->toThrow(QueryException::class)
        ->and(fn () => ReviewKinerjaNilai::create(['review_kinerja_id' => $rv->id, 'lapisan' => 'manager', 'aspek' => 'Initiative', 'nilai' => 9]))->toThrow(QueryException::class)
        ->and(fn () => DB::connection('core')->table('karyawan')->where('user_id', $kru)->delete())->toThrow(QueryException::class);
});

it('refuses HR values that cannot be right', function (Closure $write) {
    expect(fn () => $write(hrKaryawan()))->toThrow(QueryException::class);
})->with([
    'month not on day 1' => [fn (string $k) => ReviewKinerja::create(['user_id' => $k, 'bulan' => '2026-09-15'])],
    'review score of 11' => [fn (string $k) => ReviewKinerjaNilai::create(['review_kinerja_id' => ReviewKinerja::create(['user_id' => $k, 'bulan' => '2026-09-01'])->id, 'lapisan' => 'hr', 'aspek' => 'Skill', 'nilai' => 11])],
    'unknown review layer' => [fn (string $k) => ReviewKinerjaNilai::create(['review_kinerja_id' => ReviewKinerja::create(['user_id' => $k, 'bulan' => '2026-09-01'])->id, 'lapisan' => 'peer', 'aspek' => 'Skill', 'nilai' => 5])],
    'manual component above 100' => [fn (string $k) => InputBulananHr::create(['user_id' => $k, 'bulan' => '2026-09-01', 'teamwork' => 130])],
    'KPI direction sideways' => [fn (string $k) => KpiItem::create(['kpi_template_id' => KpiTemplate::create(['divisi_id' => hrDivisi(), 'berlaku_dari' => '2026-01-01'])->id, 'nama' => 'X', 'target' => 1, 'arah' => 'datar'])],
    'attendance credit above 1' => [fn (string $k) => KehadiranTalenta::create(['impor_kehadiran_id' => ImporKehadiran::create(['bulan' => '2026-09-01', 'diimpor_at' => now()])->id, 'talenta_id' => 'T1', 'tanggal' => '2026-09-02', 'kredit' => 2])],
]);
