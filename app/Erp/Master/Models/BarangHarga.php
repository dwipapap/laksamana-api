<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchase price of a Barang from berlaku_dari; harga_per_dasar is computed by the database. */
final class BarangHarga extends CoreRecord
{
    protected $table = 'barang_harga';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'qty_beli' => 'decimal:4',
            'harga_beli' => 'decimal:0',
            'harga_per_dasar' => 'decimal:4',
            'berlaku_dari' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'pihak_id', 'pihak_id');
    }
}
