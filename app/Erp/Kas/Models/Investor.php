<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The investor role of a Pihak, keyed by pihak_id (1:1). */
final class Investor extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'investor';

    protected $primaryKey = 'pihak_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'target_kembali' => 'immutable_date',
        ];
    }
}
