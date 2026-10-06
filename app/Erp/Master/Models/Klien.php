<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The Klien role of a Pihak, keyed by pihak_id (1:1). */
final class Klien extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'klien';

    protected $primaryKey = 'pihak_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_lahir' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Pihak, $this> */
    public function pihak(): BelongsTo
    {
        return $this->belongsTo(Pihak::class, 'pihak_id');
    }
}
