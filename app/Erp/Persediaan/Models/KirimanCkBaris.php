<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\Satuan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a KirimanCk, in the unit entered and in the Satuan Dasar. */
final class KirimanCkBaris extends CoreRecord
{
    protected $table = 'kiriman_ck_baris';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'qty_input' => 'decimal:4',
            'qty_dasar' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<KirimanCk, $this> */
    public function kirimanCk(): BelongsTo
    {
        return $this->belongsTo(KirimanCk::class, 'kiriman_ck_id');
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    /** @return BelongsTo<Satuan, $this> */
    public function satuanInput(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'satuan_input_id');
    }
}
