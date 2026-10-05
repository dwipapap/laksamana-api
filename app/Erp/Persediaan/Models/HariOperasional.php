<?php

declare(strict_types=1);

namespace App\Erp\Persediaan\Models;

use App\Core\Models\CoreRecord;
use App\Erp\Master\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One opening of a Lokasi; everything inside [dibuka_at, ditutup_at) belongs to its tanggal_bisnis. */
final class HariOperasional extends CoreRecord
{
    protected $table = 'hari_operasional';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'tanggal_bisnis' => 'immutable_date',
            'dibuka_at' => 'immutable_datetime',
            'ditutup_at' => 'immutable_datetime',
            'ditutup_otomatis' => 'boolean',
        ];
    }

    /** @return BelongsTo<Lokasi, $this> */
    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }
}
