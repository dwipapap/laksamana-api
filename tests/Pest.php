<?php

use App\Support\Modules;
use Tests\TestCase;

/*
| Feature tests run against the LOCAL restored legacy databases; every test is
| wrapped in a transaction on every connection (see Tests\TestCase).
*/
pest()->extend(TestCase::class)->in('Feature');

/** A real active user from the restored account DB, with its plain PIN. */
function anyActiveUser(bool $superadmin = false): array
{
    $db = Modules::db('account');
    $row = $superadmin
        ? $db->selectOne("SELECT u.id, u.name, u.pin FROM users u JOIN admins a ON a.user_id = u.id WHERE a.module = '*' AND u.active = 1 LIMIT 1")
        : $db->selectOne("SELECT u.id, u.name, u.pin FROM users u WHERE u.active = 1 AND u.id NOT IN (SELECT user_id FROM admins WHERE module = '*') LIMIT 1");

    return (array) $row;
}

/** One Office user by id (with plain PIN) from the restored account DB. */
function officeUser(string $id): array
{
    $row = Modules::db('account')->selectOne('SELECT id, name, pin FROM users WHERE id = ?', [$id]);
    if (! $row) {
        throw new RuntimeException("Test user $id not found in the restored account DB.");
    }

    return (array) $row;
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
