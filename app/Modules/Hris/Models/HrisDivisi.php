<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Divisi (ADR-0012). `kode` is generated once and never changes: shift
 * Divisi share it with Jadwal (bar, kitchen, floor, cashier, cleaning).
 * Master data (soft-deleted, only when empty).
 */
final class HrisDivisi extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'hris_divisi';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['urutan' => 'integer', 'aktif' => 'boolean'];
    }
}
