<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKaryawan;

/**
 * E10 (ADR-0012): whether a User gets HRIS as Akses Bawaan. Everyone while
 * `hris.hanya_karyawan` (env HRIS_HANYA_KARYAWAN) is off (PR #300); once it
 * is on, only Users with a Karyawan record that has not left. Fails open: a
 * read error must not lock crew out of punching.
 */
final class HrisGerbang
{
    public function bolehBawaan(string $userId): bool
    {
        try {
            if (! config('hris.hanya_karyawan')) {
                return true;
            }

            return HrisKaryawan::query()->where('user_id', $userId)
                ->where(fn ($q) => $q->whereNull('tanggal_keluar')->orWhere('tanggal_keluar', '>=', PunchRules::tglWib(PunchRules::ms())))
                ->exists();
        } catch (\Throwable) {
            return true;
        }
    }
}
