<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * One People Score component (S2): same shape in, same shape out, so
 * components are added or removed by editing the formula map — never the
 * scorer. skor and kelengkapan are both 0–100; missing data scores 0 with
 * 0 completeness (S3 ranks only kelengkapan ≥ 60).
 */
interface SkorKomponen
{
    public function kunci(): string;

    /** @return array{skor:float,kelengkapan:float,detail:array} */
    public function hitung(string $userId, string $bulan): array;
}
