<?php

namespace App\Auth;

use App\Support\Modules;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * An Office account — a row of `users` in the EXISTING account database
 * (lakk5493_db_account). Not a new user table: login, roster and module
 * grants stay exactly where every legacy module already reads them.
 *
 * Notes that matter:
 *  - `id` is a string like `u-andi` (never auto-increment).
 *  - `pin` is plain text BY DESIGN of the legacy system (the superadmin console
 *    shows and edits it). It is never serialised by this model.
 *  - timestamps are off: the legacy table has created_at/updated_at with DB
 *    defaults and Eloquent must not start managing them.
 */
class AccountUser extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['pin'];

    public function getConnectionName(): ?string
    {
        return Modules::connectionName('account');
    }

    public function isActive(): bool
    {
        return (int) $this->active === 1;
    }

    // Authenticatable: there is no password column; PIN auth is done in LoginService.
    public function getAuthPasswordName(): string
    {
        return 'pin';
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
