<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A weekday a vendor is closed (0 = Minggu … 6 = Sabtu). */
final class VendorHariTutup extends CoreRecord
{
    protected $table = 'vendor_hari_tutup';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'hari' => 'integer',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'pihak_id', 'pihak_id');
    }
}
