<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Hris\Services\HrisImporHrLamaService;
use Illuminate\Console\Command;

/**
 * `php artisan hris:impor-hr-lama [--coba]` — moves the remaining legacy HR
 * panel data (violations, KPI templates/actuals, reviews) into the HRIS
 * tables. Idempotent and never overwrites; `--coba` rolls back and only
 * prints the report. Never run it against dev or production (CLAUDE.md §0).
 */
final class HrisImporHrLamaCommand extends Command
{
    protected $signature = 'hris:impor-hr-lama {--coba : jalankan tanpa menyimpan}';

    protected $description = 'Pindahkan pelanggaran, KPI dan review HR lama ke tabel HRIS';

    public function handle(HrisImporHrLamaService $impor): int
    {
        $coba = (bool) $this->option('coba');
        $r = $impor->impor('hris:impor-hr-lama', $coba);

        $this->line('Pelanggaran dibuat: '.$r['pelanggaran_dibuat'].' · sudah ada: '.$r['pelanggaran_sudah_ada']);
        $this->line('KPI template dibuat: '.$r['kpi_template_dibuat'].' · sudah ada: '.$r['kpi_template_sudah_ada']);
        $this->line('KPI item dibuat: '.$r['kpi_item_dibuat'].' · realisasi dibuat: '.$r['kpi_realisasi_dibuat']
            .' · realisasi sudah ada: '.$r['kpi_realisasi_sudah_ada']);
        $this->line('Penilaian dibuat: '.$r['penilaian_dibuat'].' · sudah ada: '.$r['penilaian_sudah_ada']);

        foreach ([
            'divisi_dibuat' => 'Divisi kantor dibuat',
            'pelanggaran_tanpa_orang' => 'Pelanggaran tanpa orang yang cocok (talentaId)',
            'pelanggaran_tidak_valid' => 'Pelanggaran dilewati (data tidak valid)',
            'kpi_tanpa_divisi' => 'KPI dilewati (Divisi tidak dikenal)',
            'kpi_item_dilewati' => 'Item KPI dilewati',
            'kpi_realisasi_tanpa_item' => 'Realisasi dilewati (item tidak ada)',
            'penilaian_tanpa_orang' => 'Review tanpa orang yang cocok (talentaId)',
            'penilaian_tidak_valid' => 'Review dilewati (bulan/aspek tidak bisa dipetakan)',
        ] as $k => $judul) {
            if ($r[$k] === []) {
                continue;
            }
            $this->warn($judul.' ('.count($r[$k]).'):');
            foreach ($r[$k] as $x) {
                $this->line('  - '.$x);
            }
        }

        $this->info($coba ? 'Coba saja: tidak ada yang disimpan.' : 'Selesai.');

        return self::SUCCESS;
    }
}
