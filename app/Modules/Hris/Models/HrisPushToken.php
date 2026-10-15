<?php

declare(strict_types=1);

namespace App\Modules\Hris\Models;

use App\Core\Models\CoreRecord;

/**
 * One Expo push token of one user. Re-registering a token moves it (a
 * hand-me-down phone); Expo's DeviceNotRegistered deletes it.
 */
final class HrisPushToken extends CoreRecord
{
    protected $table = 'hris_push_token';

    protected $guarded = [];

    protected function casts(): array
    {
        return parent::casts() + [
            'terakhir_dilihat' => 'immutable_datetime',
        ];
    }
}
