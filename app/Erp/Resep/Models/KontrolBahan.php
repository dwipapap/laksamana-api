<?php

declare(strict_types=1);

namespace App\Erp\Resep\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kontrol Bahan Baku of one Lokasi for one month. */
final class KontrolBahan extends CoreRecord
{
    protected $table = 'kontrol_bahan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'bulan' => 'immutable_date',
        ];
    }

    /** @return HasMany<KontrolBahanBaris, $this> */
    public function baris(): HasMany
    {
        return $this->hasMany(KontrolBahanBaris::class, 'kontrol_bahan_id');
    }
}
