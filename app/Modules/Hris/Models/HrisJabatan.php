<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Master Jabatan (Q6): a name, its level and an optional Divisi. */
final class HrisJabatan extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'hris_jabatan';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + ['urutan' => 'integer', 'aktif' => 'boolean'];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(HrisJabatanLevel::class, 'level_id');
    }
}
