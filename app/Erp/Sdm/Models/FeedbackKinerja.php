<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** A guest's positive or negative feedback on a Karyawan. */
final class FeedbackKinerja extends CoreRecord
{
    protected $table = 'feedback_kinerja';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
