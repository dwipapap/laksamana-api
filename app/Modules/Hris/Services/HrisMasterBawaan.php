<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisJabatanLevel;

/**
 * The rows HRIS master data cannot start without (ADR-0012), created on
 * demand instead of in the migration: production gets its core schema empty
 * (tools/core-schema.sh) and the test harness empties every core table.
 *
 * Idempotent. A shift Divisi is recreated only when its kode is missing
 * altogether (a soft-deleted one stays deleted); the levels are seeded only
 * while the table is empty, so HR's renames and removals stick.
 */
final class HrisMasterBawaan
{
    /** Shift Divisi: the Jadwal codes, in Jadwal order (Cleaning is new, Q11). */
    public const DIVISI_SHIFT = ['bar' => 'Bar', 'kitchen' => 'Kitchen', 'floor' => 'Floor',
        'cashier' => 'Cashier', 'cleaning' => 'Cleaning Staff'];

    /** Draft levels (Q25), lowest first; HR edits them in /kelola/jabatan. */
    public const LEVEL = ['Helper/Trainee', 'Crew', 'Senior', 'Captain/Supervisor', 'Head/Kepala', 'Manajemen'];

    public function pastikan(): void
    {
        $i = 0;
        foreach (self::DIVISI_SHIFT as $kode => $nama) {
            $i++;
            if (! HrisDivisi::withTrashed()->where('kode', $kode)->exists()) {
                HrisDivisi::query()->create(['kode' => $kode, 'nama' => $nama, 'jenis' => 'shift',
                    'urutan' => $i * 10, 'aktif' => true]);
            }
        }
        if (! HrisJabatanLevel::withTrashed()->exists()) {
            foreach (self::LEVEL as $n => $nama) {
                HrisJabatanLevel::query()->create(['nama' => $nama, 'urutan' => $n + 1, 'aktif' => true]);
            }
        }
    }
}
