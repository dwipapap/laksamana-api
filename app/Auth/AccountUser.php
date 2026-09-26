<?php

namespace App\Auth;

use App\Support\Modules;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;

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

    /*
     * Identity on core (#44): the row is `user`, and the key every module uses
     * (getKey(), guards, module data) stays the LEGACY id (`legacy_id`), while
     * Sanctum tokens point at the ULID `id` column (re-keyed by the importer).
     * On the legacy table both are the same `id` column.
     */
    public function getTable(): string
    {
        return AccountRepository::onCore() ? 'user' : 'users';
    }

    public function getKeyName(): string
    {
        return AccountRepository::onCore() ? 'legacy_id' : 'id';
    }

    public function tokens()
    {
        return $this->morphMany(Sanctum::$personalAccessTokenModel, 'tokenable', null, null, 'id');
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn ($v, array $a) => $a['name'] ?? $a['nama'] ?? null);
    }

    public function isActive(): bool
    {
        return (int) ($this->attributes['active'] ?? $this->attributes['aktif'] ?? 0) === 1;
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
