<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods an outlet sends (back) to the Central Kitchen. */
final class KirimanCk extends CoreRecord
{
    protected $table = 'kiriman_ck';

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

    /** @return BelongsTo<Lokasi, $this> */
    public function keLokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'ke_lokasi_id');
    }

    /** @return HasMany<KirimanCkBaris, $this> */
    public function baris(): HasMany
    {
        return $this->hasMany(KirimanCkBaris::class, 'kiriman_ck_id');
    }
}
