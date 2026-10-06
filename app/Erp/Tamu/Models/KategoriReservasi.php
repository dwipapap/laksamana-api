<?php

declare(strict_types=1);

namespace App\Erp\Tamu\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A reservation category (Tamu Umum, Birthday, …). */
final class KategoriReservasi extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'kategori_reservasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }
}
