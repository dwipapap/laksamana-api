<?php

declare(strict_types=1);

namespace App\Erp\Resep\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A recipe: a base, a menu or a prasmanan dish, with its lines and price history. */
final class Resep extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'resep';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }

    /** @return HasMany<ResepBaris, $this> */
    public function baris(): HasMany
    {
        return $this->hasMany(ResepBaris::class, 'resep_id')->orderBy('urutan');
    }

    /** @return HasMany<ResepHarga, $this> */
    public function harga(): HasMany
    {
        return $this->hasMany(ResepHarga::class, 'resep_id');
    }
}
