<?php

declare(strict_types=1);

namespace App\Erp\Konten\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A brand Konten produces for, with its KPI and creative profile. */
final class BrandKonten extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'brand_konten';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'profil' => 'array',
        ];
    }
}
