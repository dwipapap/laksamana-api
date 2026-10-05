<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vendor of a Barang; urutan 0 is the vendor utama. */
final class BarangVendor extends CoreRecord
{
    protected $table = 'barang_vendor';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'urutan' => 'integer',
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
