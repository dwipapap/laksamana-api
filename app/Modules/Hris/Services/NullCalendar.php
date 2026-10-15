<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * B1 stub: no calendar table yet (it arrives in B2), so no day is a holiday
 * and HARI_LIBUR never fires. Bound in HrisServiceProvider; B2 rebinds.
 */
final class NullCalendar implements HrisCalendar
{
    public function isHoliday(string $ymd): bool
    {
        return false;
    }
}
