<?php

declare(strict_types=1);

namespace App\Erp\Resep\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Barang;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a recipe: a Barang, another recipe, or a cooking-step note. */
final class ResepBaris extends CoreRecord
{
    protected $table = 'resep_baris';

    protected $guarded = [];

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    /** @return BelongsTo<Resep, $this> */
    public function subResep(): BelongsTo
    {
        return $this->belongsTo(Resep::class, 'sub_resep_id');
    }
}
