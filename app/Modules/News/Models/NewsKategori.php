<?php

declare(strict_types=1);

namespace App\Modules\News\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A news category (Event, Promo, Kabar, …). */
final class NewsKategori extends CoreRecord
{
    protected $table = 'news_kategori';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'urutan' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    /** @return HasMany<NewsArtikel, $this> */
    public function artikel(): HasMany
    {
        return $this->hasMany(NewsArtikel::class, 'kategori_id');
    }
}
