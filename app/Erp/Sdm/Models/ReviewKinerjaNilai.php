<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** One aspect score (1-10) from one review layer. */
final class ReviewKinerjaNilai extends CoreRecord
{
    protected $table = 'review_kinerja_nilai';

    protected $guarded = [];
}
