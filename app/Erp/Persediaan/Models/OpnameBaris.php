<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Barang;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A counted Barang: qty_sistem and qty_fisik, NULL meaning not counted (unlike 0). */
final class OpnameBaris extends CoreRecord
{
    protected $table = 'opname_baris';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'qty_sistem' => 'decimal:4',
            'qty_fisik' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Opname, $this> */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(Opname::class, 'opname_id');
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }
}
