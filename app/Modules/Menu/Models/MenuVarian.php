<?php

declare(strict_types=1);

namespace App\Modules\Menu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A price variant of an item (`R`/`L`, `Kaya`/`Matcha`, …); `''` = single price. */
final class MenuVarian extends CoreRecord
{
    protected $table = 'menu_varian';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'harga' => 'integer',
            'urutan' => 'integer',
        ];
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'item_id');
    }
}
