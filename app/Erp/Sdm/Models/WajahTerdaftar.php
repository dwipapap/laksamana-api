<?php

declare(strict_types=1);

namespace App\Erp\Sdm\Models;

use App\Core\Models\CoreRecord;

/** A registered face of a User or a Pekerja Harian. */
final class WajahTerdaftar extends CoreRecord
{
    protected $table = 'wajah_terdaftar';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'descriptor' => 'array',
            'aktif' => 'boolean',
            'didaftar_at' => 'immutable_datetime',
        ];
    }
}
