<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A category shared by Kas Kecil and Planning Pembayaran. */
final class KategoriKas extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'kategori_kas';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }
}
