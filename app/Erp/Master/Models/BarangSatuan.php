<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A valid unit of a Barang and its size (Ukuran Satuan) in the Satuan Dasar, from berlaku_dari. */
final class BarangSatuan extends CoreRecord
{
    protected $table = 'barang_satuan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'ukuran' => 'decimal:4',
            'berlaku_dari' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    /** @return BelongsTo<Satuan, $this> */
    public function satuan(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'satuan_id');
    }
}
