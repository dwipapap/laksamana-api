<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A party the company deals with; today only vendors. */
final class Pihak extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'pihak';

    protected $guarded = [];

    /** @return HasMany<PihakRekening, $this> */
    public function rekening(): HasMany
    {
        return $this->hasMany(PihakRekening::class, 'pihak_id');
    }

    /** @return HasOne<Vendor, $this> */
    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class, 'pihak_id');
    }
}
