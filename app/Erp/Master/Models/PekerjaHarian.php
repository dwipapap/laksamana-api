<?php

declare(strict_types=1);

namespace App\Erp\Master\Models;

use App\Core\Models\CoreRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The Pekerja Harian role of a Pihak, keyed by pihak_id (1:1). */
final class PekerjaHarian extends CoreRecord
{
    use SoftDeletes;

    protected $table = 'pekerja_harian';

    protected $primaryKey = 'pihak_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'aktif' => 'boolean',
        ];
    }

    /** @return BelongsTo<Pihak, $this> */
    public function pihak(): BelongsTo
    {
        return $this->belongsTo(Pihak::class, 'pihak_id');
    }

    /** @return HasMany<PekerjaHarianDivisi, $this> */
    public function divisi(): HasMany
    {
        return $this->hasMany(PekerjaHarianDivisi::class, 'pihak_id', 'pihak_id');
    }
}
