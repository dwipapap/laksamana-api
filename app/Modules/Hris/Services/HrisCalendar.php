<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Holiday lookup for the HARI_LIBUR queue reason. B1 ships the null
 * implementation (always false); B2 binds the real calendar backed by
 * hris_kalender, without touching the punch logic.
 */
interface HrisCalendar
{
    public function isHoliday(string $ymd): bool;
}
