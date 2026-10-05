<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Barang;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A signed correction of one Barang in its Satuan Dasar. */
final class PenyesuaianStokBaris extends CoreRecord
{
    protected $table = 'penyesuaian_stok_baris';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'qty_dasar' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PenyesuaianStok, $this> */
    public function penyesuaianStok(): BelongsTo
    {
        return $this->belongsTo(PenyesuaianStok::class, 'penyesuaian_stok_id');
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }
}
