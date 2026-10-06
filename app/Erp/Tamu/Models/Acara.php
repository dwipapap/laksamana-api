<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Klien's booking of the venue and F&B, moving through the sales pipeline. */
final class Acara extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'acara';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal' => 'immutable_date',
            'tanggal_selesai' => 'immutable_date',
            'pajak_termasuk' => 'boolean',
            'service_berlaku' => 'boolean',
            'invoice_terkirim_at' => 'immutable_datetime',
            'brief' => 'array',
        ];
    }
}
