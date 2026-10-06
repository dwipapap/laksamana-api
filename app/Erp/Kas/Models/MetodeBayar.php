<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A payment method and the Dompet its takings land in. */
final class MetodeBayar extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'metode_bayar';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }
}
