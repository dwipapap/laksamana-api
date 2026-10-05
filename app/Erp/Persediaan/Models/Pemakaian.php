<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods used for one event (RND, Prasmanan). */
final class Pemakaian extends CoreRecord
{
    protected $table = 'pemakaian';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Lokasi, $this> */
    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    /** @return BelongsTo<HariOperasional, $this> */
    public function hariOperasional(): BelongsTo
    {
        return $this->belongsTo(HariOperasional::class, 'hari_operasional_id');
    }

    /** @return HasMany<PemakaianBaris, $this> */
    public function baris(): HasMany
    {
        return $this->hasMany(PemakaianBaris::class, 'pemakaian_id');
    }
}
