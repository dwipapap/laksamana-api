<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\Satuan;
use App\Erp\Master\Models\Vendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One requested Barang; its own status (diajukan, datang, batal) and the vendor written when processed. */
final class PesananBahanBaris extends CoreRecord
{
    protected $table = 'pesanan_bahan_baris';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'qty_input' => 'decimal:4',
            'qty_dasar' => 'decimal:4',
            'tanggal_butuh' => 'immutable_date',
            'tanggal_jemput' => 'immutable_date',
            'diterima_at' => 'immutable_datetime',
            'diarsipkan_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PesananBahan, $this> */
    public function pesananBahan(): BelongsTo
    {
        return $this->belongsTo(PesananBahan::class, 'pesanan_bahan_id');
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

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'pihak_id');
    }
}
