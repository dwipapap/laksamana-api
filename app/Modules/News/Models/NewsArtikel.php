<?php

declare(strict_types=1);

namespace App\Modules\News\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One article. `published_at` (stored in UTC) is the only ordering key. */
final class NewsArtikel extends CoreRecord
{
    protected $table = 'news_artikel';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tampil' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<NewsKategori, $this> */
    public function kategori(): BelongsTo
    {
        return $this->belongsTo(NewsKategori::class, 'kategori_id');
    }
}
