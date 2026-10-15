<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Applicant: encrypted contact, private CV, extracted + redacted text, stage, auto-delete date (R2–R4).
 */
final class HrisPelamar extends CoreRecord
{
    protected $table = 'hris_pelamar';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [];
    }
}
