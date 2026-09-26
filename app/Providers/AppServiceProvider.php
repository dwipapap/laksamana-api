<?php

namespace App\Providers;

use App\Auth\AccountRepository;
use App\Auth\AccountUser;
use App\Auth\CoreAccountRepository;
use App\Auth\CorePersonalAccessToken;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Identity cutover (#44): same repository contract, core tables behind it.
        // Resolved per call, so switching DB_ACCOUNT_CONNECTION back rolls back.
        $this->app->bind(AccountRepository::class,
            fn () => AccountRepository::onCore() ? new CoreAccountRepository : new AccountRepository);
    }

    public function boot(): void
    {
        // Tokens live in `core`; their owners in the legacy account DB.
        Sanctum::usePersonalAccessTokenModel(CorePersonalAccessToken::class);
        Relation::morphMap(['office_user' => AccountUser::class]);

        // A deactivated Office account loses API access immediately, like legacy sessions.
        Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $isValid) => $isValid && $token->tokenable instanceof AccountUser && $token->tokenable->isActive()
        );
    }
}
