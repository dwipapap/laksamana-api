<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The Talent role of a Pihak, keyed by pihak_id (1:1). */
final class Talent extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'talent';

    protected $primaryKey = 'pihak_id';

    protected $guarded = [];

    /** @return BelongsTo<Pihak, $this> */
    public function pihak(): BelongsTo
    {
        return $this->belongsTo(Pihak::class, 'pihak_id');
    }
}
