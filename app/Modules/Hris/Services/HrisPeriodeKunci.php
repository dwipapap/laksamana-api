<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPeriode;

/**
 * A locked pay period (Kelola → Uang Makan) is final: no correction,
 * roster edit or shift change may touch a work date inside it, or the
 * locked recap silently disagrees with the punches and the roster.
 */
final class HrisPeriodeKunci
{
    public function terkunci(string $tanggal): bool
    {
        return HrisPeriode::query()->where('bulan', substr($tanggal, 0, 7))->where('status', 'TERKUNCI')->exists();
    }

    public function pastikanTerbuka(string $tanggal): void
    {
        if ($this->terkunci($tanggal)) {
            throw new HrisError('periode_terkunci',
                'Periode '.substr($tanggal, 0, 7).' sudah dikunci. Tanggal di dalamnya tidak bisa diubah lagi.', 409,
                ['tanggal' => $tanggal]);
        }
    }
}
