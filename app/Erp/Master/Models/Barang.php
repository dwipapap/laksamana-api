<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Anything bought, stored or used as an ingredient; one catalogue for Stock and HPP. */
final class Barang extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'barang';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
            'dipesan' => 'boolean',
        ];
    }

    /** @return BelongsTo<Satuan, $this> */
    public function satuanDasar(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'satuan_dasar_id');
    }

    /** @return BelongsTo<KategoriBarang, $this> */
    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriBarang::class, 'kategori_id');
    }

    /** @return HasMany<BarangSatuan, $this> */
    public function satuan(): HasMany
    {
        return $this->hasMany(BarangSatuan::class, 'barang_id');
    }

    /** @return HasMany<BarangVendor, $this> */
    public function vendor(): HasMany
    {
        return $this->hasMany(BarangVendor::class, 'barang_id');
    }

    /** @return HasMany<BarangLokasi, $this> */
    public function lokasi(): HasMany
    {
        return $this->hasMany(BarangLokasi::class, 'barang_id');
    }

    /** @return HasMany<BarangHarga, $this> */
    public function harga(): HasMany
    {
        return $this->hasMany(BarangHarga::class, 'barang_id');
    }
}
