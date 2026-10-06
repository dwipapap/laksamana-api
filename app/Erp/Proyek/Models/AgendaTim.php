<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Models;

use App\Core\Models\CoreRecord;

/** A team agenda entry. */
final class AgendaTim extends CoreRecord
{
    protected $table = 'agenda_tim';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
        ];
    }
}
