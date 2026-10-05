<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A batch of raw-material requests from one Divisi (legacy Ordering batch). */
final class PesananBahan extends CoreRecord
{
    protected $table = 'pesanan_bahan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
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

    /** @return HasMany<PesananBahanBaris, $this> */
    public function baris(): HasMany
    {
        return $this->hasMany(PesananBahanBaris::class, 'pesanan_bahan_id');
    }
}
