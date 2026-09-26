<?php

namespace App\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum tokens live in the new `core` database (Laravel-owned). Their owner
 * is an AccountUser: a legacy `users` row, or a `user` row once identity is
 * on core (#44). The morph id is a string column (see the
 * personal_access_tokens migration) because Office ids are strings, and it
 * always points at the owner's `id` column (the ULID on core).
 */
class CorePersonalAccessToken extends PersonalAccessToken
{
    protected $connection = 'core';

    protected $table = 'personal_access_tokens';

    public function tokenable()
    {
        return $this->morphTo('tokenable', null, null, 'id');
    }
}
