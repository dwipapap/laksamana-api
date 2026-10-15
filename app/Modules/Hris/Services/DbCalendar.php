<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKalender;

/**
 * The real holiday calendar (B2): hris_kalender rows, memoised per request.
 * Replaces NullCalendar in HrisServiceProvider. Flush after every write.
 */
final class DbCalendar implements HrisCalendar
{
    /** @var array<string,true>|null */
    private ?array $map = null;

    public function isHoliday(string $ymd): bool
    {
        if (PunchRules::tglValid($ymd) === '') {
            return false;
        }
        if ($this->map === null) {
            $this->map = [];
            foreach (HrisKalender::query()->get(['tanggal']) as $r) {
                $this->map[$r->tanggal->format('Y-m-d')] = true;
            }
        }

        return isset($this->map[$ymd]);
    }

    public function flush(): void
    {
        $this->map = null;
    }
}
