<?php

declare(strict_types=1);

namespace App\Erp\Kas\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A place company money sits: a bank account, the brankas cash or a Kas Kecil pos. */
final class Dompet extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'dompet';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
            'saldo_awal_tanggal' => 'immutable_date',
        ];
    }
}
