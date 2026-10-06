<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;

/** One Kas Kecil transaction; its share per pos is written as ArusKas. */
final class KasKecil extends CoreRecord
{
    protected $table = 'kas_kecil';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'sudah_dibukukan' => 'boolean',
            'ada_bon' => 'boolean',
            'dibatalkan_at' => 'immutable_datetime',
        ];
    }
}
