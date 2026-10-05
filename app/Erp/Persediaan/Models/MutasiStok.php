<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Barang;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement in the stock ledger of a Lokasi; points at exactly one origin line. */
final class MutasiStok extends CoreRecord
{
    protected $table = 'mutasi_stok';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'qty_dasar' => 'decimal:4',
            'tanggal_bisnis' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Lokasi, $this> */
    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    /** @return BelongsTo<Barang, $this> */
    public function barang(): BelongsTo
    {
        return $this->belongsTo(Barang::class, 'barang_id');
    }

    /** @return BelongsTo<PesananBahanBaris, $this> */
    public function pesananBahanBaris(): BelongsTo
    {
        return $this->belongsTo(PesananBahanBaris::class, 'pesanan_bahan_baris_id');
    }

    /** @return BelongsTo<KirimanCkBaris, $this> */
    public function kirimanCkBaris(): BelongsTo
    {
        return $this->belongsTo(KirimanCkBaris::class, 'kiriman_ck_baris_id');
    }

    /** @return BelongsTo<ProduksiCkBaris, $this> */
    public function produksiCkBaris(): BelongsTo
    {
        return $this->belongsTo(ProduksiCkBaris::class, 'produksi_ck_baris_id');
    }

    /** @return BelongsTo<PenyesuaianStokBaris, $this> */
    public function penyesuaianStokBaris(): BelongsTo
    {
        return $this->belongsTo(PenyesuaianStokBaris::class, 'penyesuaian_stok_baris_id');
    }

    /** @return BelongsTo<Waste, $this> */
    public function waste(): BelongsTo
    {
        return $this->belongsTo(Waste::class, 'waste_id');
    }
}
