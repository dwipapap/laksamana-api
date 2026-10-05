<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A place that holds stock and money (Outlet, Central Kitchen). */
final class Lokasi extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'lokasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }

    /** @return HasMany<BarangLokasi, $this> */
    public function barangLokasi(): HasMany
    {
        return $this->hasMany(BarangLokasi::class, 'lokasi_id');
    }
}
