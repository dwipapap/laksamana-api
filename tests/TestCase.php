<?php

namespace Tests;

use App\Auth\AccountRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * Feature tests run against the LOCAL restored legacy databases
 * (tools/restore-dumps.sh) plus a dedicated `lakk5493_laksamana_core_test`.
 *
 * Every test is wrapped in a transaction on EVERY connection, so tests may
 * write freely and nothing persists. Migrations run only on `core`.
 * Never point these connections at a live server.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Transact all connections: core + every legacy DB. */
    protected function connectionsToTransact(): array
    {
        $names = ['core'];
        foreach (array_keys(config('database.connections')) as $name) {
            if (str_starts_with($name, 'legacy_')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * With identity on core (DB_ACCOUNT_CONNECTION=core, #44) the freshly
     * migrated test core is filled from the restored legacy DBs once per run.
     */
    protected function afterRefreshingDatabase(): void
    {
        if (AccountRepository::onCore()) {
            Artisan::call('core:import', ['module' => 'account']);
        }
    }

    /** POST a legacy text/plain JSON body to an old-style URL. */
    protected function legacyPost(string $uri, array $body): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
    }
}
