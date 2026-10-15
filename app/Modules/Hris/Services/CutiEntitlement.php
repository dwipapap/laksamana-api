<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Annual leave quota (L7). The contract rules are still open (⏳): whether
 * the 12 days reset every anniversary inside a long contract, whether PKWTT
 * follows the same rule, whether probation counts. Until the mentor
 * answers, the quota comes from settings and grants are manual by HR —
 * never guessed from contract dates.
 */
interface CutiEntitlement
{
    /** Annual CUTI quota in days for one user. */
    public function jatahTahunan(string $userId): int;
}
