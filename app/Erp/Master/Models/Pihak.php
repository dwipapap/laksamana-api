<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A person or organisation outside the Users; what it is to us is the roles it holds. */
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

    /** @return HasOne<Klien, $this> */
    public function klien(): HasOne
    {
        return $this->hasOne(Klien::class, 'pihak_id');
    }

    /** @return HasOne<Talent, $this> */
    public function talent(): HasOne
    {
        return $this->hasOne(Talent::class, 'pihak_id');
    }

    /** @return HasOne<Kol, $this> */
    public function kol(): HasOne
    {
        return $this->hasOne(Kol::class, 'pihak_id');
    }

    /** @return HasOne<PekerjaHarian, $this> */
    public function pekerjaHarian(): HasOne
    {
        return $this->hasOne(PekerjaHarian::class, 'pihak_id');
    }
}
