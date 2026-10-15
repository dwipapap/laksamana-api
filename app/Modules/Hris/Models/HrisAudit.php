<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * Append-only audit trail (aktor, aksi, ref, data). Written, never updated or
 * deleted. `data` carries ids and decision codes only — never face
 * descriptors, photos or GPS coordinates (UU PDP: minimal personal data).
 */
final class HrisAudit extends CoreRecord
{
    protected $table = 'hris_audit';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'waktu' => 'integer',
            'data' => 'array',
        ];
    }
}
