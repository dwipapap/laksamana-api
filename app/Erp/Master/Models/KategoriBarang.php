<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Barang category (DRY ITEM, FRESH, CHILLER ITEM, …). */
final class KategoriBarang extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'kategori_barang';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'urutan' => 'integer',
        ];
    }
}
