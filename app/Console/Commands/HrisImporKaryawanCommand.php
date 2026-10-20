<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Hris\Services\HrisImporKaryawanService;
use Illuminate\Console\Command;

/**
 * `php artisan hris:impor-karyawan [--coba]` — fills hris_divisi (kantor),
 * hris_jabatan and hris_karyawan from the Office users, the legacy HR panel
 * and Jadwal (ADR-0012). Idempotent and never overwrites; `--coba` rolls back
 * and only prints the report.
 */
final class HrisImporKaryawanCommand extends Command
{
    protected $signature = 'hris:impor-karyawan {--coba : jalankan tanpa menyimpan}';

    protected $description = 'Isi data karyawan, divisi dan jabatan HRIS dari Office, HR lama dan Jadwal';

    public function handle(HrisImporKaryawanService $impor): int
    {
        $coba = (bool) $this->option('coba');
        $r = $impor->impor('hris:impor-karyawan', $coba);

        $this->line('Karyawan dibuat: '.$r['dibuat'].' · sudah ada: '.$r['sudah_ada'].' · User nonaktif dilewati: '.$r['nonaktif']);
        foreach (['divisi_dibuat' => 'Divisi kantor dibuat', 'jabatan_dibuat' => 'Jabatan dibuat',
            'tanpa_divisi' => 'Tanpa Divisi (atur di /kelola/karyawan)',
            'bukan_karyawan' => 'Tidak diimpor, tanpa data HR ("Jadikan karyawan" bila perlu)'] as $k => $judul) {
            if ($r[$k] !== []) {
                $this->warn($judul.' ('.count($r[$k]).'):');
                foreach ($r[$k] as $x) {
                    $this->line('  - '.$x);
                }
            }
        }
        $this->info($coba ? 'Coba saja: tidak ada yang disimpan.' : 'Selesai.');

        return self::SUCCESS;
    }
}
