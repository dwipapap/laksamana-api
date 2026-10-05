<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A bank account of a Pihak. */
final class PihakRekening extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'pihak_rekening';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'utama' => 'boolean',
        ];
    }

    /** @return BelongsTo<Pihak, $this> */
    public function pihak(): BelongsTo
    {
        return $this->belongsTo(Pihak::class, 'pihak_id');
    }
}
