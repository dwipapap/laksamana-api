<?php

use App\Auth\AccountRepository;
use Tests\TestCase;

/*
| Feature tests run against the LOCAL restored legacy databases; every test is
| wrapped in a transaction on every connection (see Tests\TestCase).
*/
pest()->extend(TestCase::class)->in('Feature');

/** A real active user from the restored account DB, with its plain PIN. */
function anyActiveUser(bool $superadmin = false): array
{
    return (array) (app(AccountRepository::class)->activeUserSample($superadmin) ?? []);
}

/** One Office user by id (with plain PIN) from the restored account DB. */
function officeUser(string $id): array
{
    $row = app(AccountRepository::class)->userById($id);
    if (! $row) {
        throw new RuntimeException("Test user $id not found in the restored account DB.");
    }

    return $row;
}

/** Deactivate (or reactivate) a user by id. Tests run inside transactions, so nothing persists. */
function setUserActive(string $id, bool $active): void
{
    app(AccountRepository::class)->setActive($id, $active);
}

/** Sanctum token for a user array (from anyActiveUser/officeUser). */
function loginAs(array $u): string
{
    return test()->postJson('/api/v1/auth/login', ['login' => $u['name'], 'pin' => $u['pin']])
        ->assertOk()->json('data.token');
}

/** Old-style Office session token (lm_session.token) via the legacy account route. */
function legacySesi(array $u): string
{
    $r = test()->call('POST', '/account-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'],
        json_encode(['action' => 'login', 'name' => $u['name'], 'pin' => $u['pin']]))->json();

    return (string) ($r['user']['token'] ?? '');
}
