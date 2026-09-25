<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Shared ADR-0003 conventions for every business table in `core`. */
abstract class CoreRecord extends Model
{
    use HasUlids;

    protected $connection = 'core';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if (! $record->exists) {
                $record->version = max(1, (int) $record->version);

                return;
            }

            if ($record->isDirty() && ! $record->isDirty('version')) {
                $record->version = ((int) $record->getRawOriginal('version')) + 1;
            }
        });

        // SoftDeletes updates deleted_at directly and does not fire saving().
        static::registerModelEvent('trashed', function (self $record): void {
            $record->version = ((int) $record->getRawOriginal('version')) + 1;
            $record->saveQuietly();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
