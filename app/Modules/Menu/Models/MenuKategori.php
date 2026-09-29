<?php

declare(strict_types=1);

namespace App\Modules\Menu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A menu category (Nusantara, Coffee, …) under one jenis. */
final class MenuKategori extends CoreRecord
{
    protected $table = 'menu_kategori';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'urutan' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    /** @return HasMany<MenuItem, $this> */
    public function item(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'kategori_id');
    }
}
