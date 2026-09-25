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
        static::creating(function (self $record): void {
            $record->version ??= 1;
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
