<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The vendor details of a Pihak, keyed by pihak_id (1:1). */
final class Vendor extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'vendor';

    protected $primaryKey = 'pihak_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'perlu_jadwal_jemput' => 'boolean',
        ];
    }

    /** @return BelongsTo<Pihak, $this> */
    public function pihak(): BelongsTo
    {
        return $this->belongsTo(Pihak::class, 'pihak_id');
    }

    /** @return HasMany<VendorHariTutup, $this> */
    public function hariTutup(): HasMany
    {
        return $this->hasMany(VendorHariTutup::class, 'pihak_id', 'pihak_id');
    }
}
