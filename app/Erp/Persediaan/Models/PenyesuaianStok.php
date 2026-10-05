<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A ratified correction of stock, with a reason per line; never edited after disahkan. */
final class PenyesuaianStok extends CoreRecord
{
    protected $table = 'penyesuaian_stok';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'disahkan_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Opname, $this> */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(Opname::class, 'opname_id');
    }

    /** @return HasMany<PenyesuaianStokBaris, $this> */
    public function baris(): HasMany
    {
        return $this->hasMany(PenyesuaianStokBaris::class, 'penyesuaian_stok_id');
    }
}
