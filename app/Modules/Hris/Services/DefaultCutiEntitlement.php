<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

/**
 * Temporary quota: the settings value (default 12), same for everyone.
 * TODO(mentor): replace with the per-contract rule once the contract
 * questions in docs/modules/hris.md are answered (B7).
 */
final class DefaultCutiEntitlement implements CutiEntitlement
{
    public function __construct(private readonly HrisSettingsService $settings) {}

    public function jatahTahunan(string $userId): int
    {
        return max(0, (int) $this->settings->get('jatahCuti', 12));
    }
}
