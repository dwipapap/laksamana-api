<?php

declare(strict_types=1);

namespace App\Erp\Resep\Models;

use App\Core\Models\CoreRecord;

/** One Barang's month in Kontrol Bahan Baku, in its Satuan Dasar. */
final class KontrolBahanBaris extends CoreRecord
{
    protected $table = 'kontrol_bahan_baris';

    protected $guarded = [];
}
