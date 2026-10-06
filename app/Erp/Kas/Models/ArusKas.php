<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** One amount in or out of a Dompet, pointing at exactly one document. */
final class ArusKas extends CoreRecord
{
    protected $table = 'arus_kas';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
        ];
    }
}
