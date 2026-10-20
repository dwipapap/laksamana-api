<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use Illuminate\Support\Facades\DB;

/*
 * api#305: a v1 request ran ±8–10 queries before any business logic
 * (grants per hasModule call, the user row twice, last_used_at UPDATE,
 * database cache reads). Two phases, one test:
 *   1. GET /me answers the identical profile with fewer queries;
 *   2. a multi-key module gate queries `grants` exactly once.
 */

function qLog(callable $fn): array
{
    $seen = [];
    DB::listen(function ($q) use (&$seen) {
        $seen[] = $q->connectionName.': '.preg_replace('/\s+/', ' ', $q->sql);
    });
    $res = $fn();

    return [$res, $seen];
}

function qcUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

it('305: auth costs few queries and the profile is unchanged', function () {
    $token = loginAs(officeUser('u-andry'));
    app('auth')->forgetGuards();
    // Fresh per-request state like production: in tests the scoped
    // singletons survive across requests in one process, which would hide
    // the very queries this test counts.
    app()->forgetScopedInstances();

    [$res, $seen] = qLog(fn () => $this->withToken($token)->getJson('/api/v1/me')->assertOk());

    $data = $res->json('data');
    expect($data['id'])->toBe('u-andry')
        ->and($data['name'])->toBe('Andry')
        ->and($data['modules'])->toContain('reservasi')
        ->and(count($seen))->toBeLessThanOrEqual(4);

    // Phase 2: gate `reservasi|service_excellent|cashier|finance` plus the
    // Dana Masuk narrowing call hasModule five times for a cashier-only
    // holder, but `grants` is read once.
    $cashier = loginAs(qcUser('u-qc-cashier', ['cashier']));
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();

    [$res2, $seen2] = qLog(fn () => $this->withToken($cashier)
        ->getJson('/api/v1/reservasi/master/dpMethods')->assertOk());
    $grants = array_values(array_filter($seen2, fn ($s) => str_contains($s, 'FROM `grants`')));
    expect($res2->json('data'))->not->toBeEmpty()
        ->and(count($grants))->toBe(1);
});
