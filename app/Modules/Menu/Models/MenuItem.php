<?php

declare(strict_types=1);

namespace App\Modules\Menu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One sellable menu item; `jenis` comes from its kategori, `bagian` is its own. */
final class MenuItem extends CoreRecord
{
    protected $table = 'menu_item';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'urutan' => 'integer',
            'unggulan' => 'boolean',
            'rekomendasi' => 'boolean',
            'pedas' => 'boolean',
            'vegetarian' => 'boolean',
            'ramah_anak' => 'boolean',
            'tampil' => 'boolean',
            'tersedia' => 'boolean',
        ];
    }

    /** @return BelongsTo<MenuKategori, $this> */
    public function kategori(): BelongsTo
    {
        return $this->belongsTo(MenuKategori::class, 'kategori_id');
    }

    /** @return HasMany<MenuVarian, $this> */
    public function varian(): HasMany
    {
        return $this->hasMany(MenuVarian::class, 'item_id');
    }
}
