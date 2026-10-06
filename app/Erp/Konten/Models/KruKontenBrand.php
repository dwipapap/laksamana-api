<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;

/** A brand a Konten crew member works on. */
final class KruKontenBrand extends CoreRecord
{
    protected $table = 'kru_konten_brand';

    protected $guarded = [];
}
