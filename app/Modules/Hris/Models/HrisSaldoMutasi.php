<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One balance-ledger row (append-only): earned +, used −, expired −.
 * Saldo = SUM(delta) per person per jenis. Earned PH rows are their own
 * batch (batch_id = own id, kedaluwarsa set); usage rows point at the
 * batches they consume, oldest-expiry first (FIFO).
 */
final class HrisSaldoMutasi extends CoreRecord
{
    protected $table = 'hris_saldo_mutasi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'delta' => 'integer',
            'tanggal' => 'immutable_date',
            'kedaluwarsa' => 'immutable_date',
        ];
    }
}
