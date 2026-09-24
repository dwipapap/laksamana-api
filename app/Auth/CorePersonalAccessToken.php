<?php

namespace App\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum tokens live in the new `core` database (Laravel-owned), while the
 * users they belong to live in the legacy account database. The morph id is a
 * string column (see the personal_access_tokens migration) because Office ids
 * are strings.
 */
class CorePersonalAccessToken extends PersonalAccessToken
{
    protected $connection = 'core';

    protected $table = 'personal_access_tokens';
}
