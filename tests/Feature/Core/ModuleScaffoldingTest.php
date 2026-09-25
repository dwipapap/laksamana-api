<?php

use App\Core\Models\CoreImportProbe;
use App\Support\Modules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * C1 proves the consolidation seams before any real Modul opts in: the
 * registry-driven import is repeatable, and shared legacy connections can be
 * switched per Modul without moving their siblings.
 */

it('imports the dummy records idempotently through the registry', function () {
    $sourceCount = DB::connection('legacy_account')->table('users')->count();

    $this->artisan('core:import', ['module' => 'dummy'])->assertSuccessful();
    $first = CoreImportProbe::query()
        ->orderBy('legacy_id')
        ->get(['id', 'legacy_id', 'name', 'username', 'version', 'created_at', 'updated_at'])
        ->map(fn (CoreImportProbe $row) => $row->toArray())
        ->all();

    $this->artisan('core:import', ['module' => 'dummy'])->assertSuccessful();
    $second = CoreImportProbe::query()
        ->orderBy('legacy_id')
        ->get(['id', 'legacy_id', 'name', 'username', 'version', 'created_at', 'updated_at'])
        ->map(fn (CoreImportProbe $row) => $row->toArray())
        ->all();

    expect($first)->toHaveCount($sourceCount)
        ->and($second)->toBe($first)
        ->and(collect($first)->every(fn (array $row) => Str::isUlid($row['id']) && $row['version'] === 1))->toBeTrue();
});

it('provides ULIDs, one version, technical actors and optional soft deletes', function () {
    $createdBy = strtolower((string) Str::ulid());
    $updatedBy = strtolower((string) Str::ulid());
    $probe = CoreImportProbe::query()->create([
        'legacy_id' => 'probe-technical-columns',
        'name' => 'Technical columns',
        'username' => 'probe',
        'created_by' => $createdBy,
        'updated_by' => $updatedBy,
        'created_at' => Carbon::parse('2026-09-25 10:00:00'),
        'updated_at' => Carbon::parse('2026-09-25 10:00:00'),
    ]);

    expect(Str::isUlid($probe->id))->toBeTrue()
        ->and($probe->version)->toBe(1)
        ->and($probe->created_by)->toBe($createdBy)
        ->and($probe->updated_by)->toBe($updatedBy);

    $probe->delete();
    expect(CoreImportProbe::withTrashed()->findOrFail($probe->id)->trashed())->toBeTrue();

    expect(fn () => CoreImportProbe::query()->create([
        'legacy_id' => 'probe-technical-columns',
        'name' => 'Duplicate',
    ]))->toThrow(QueryException::class);
});

it('keeps every Modul opt-in and switches shared legacy connections independently', function () {
    expect(collect(Modules::all())->pluck('maintenance')->unique()->all())->toBe([false])
        ->and(Modules::connectionName('event'))->toBe('legacy_ems')
        ->and(Modules::connectionName('ticketing'))->toBe('legacy_ems');

    config([
        'laksamana.modules.event.connection' => 'core',
        'laksamana.modules.event.maintenance' => true,
    ]);

    expect(Modules::connectionName('event'))->toBe('core')
        ->and(Modules::databaseName('event'))->toBe(config('database.connections.core.database'))
        ->and(Modules::connectionName('ticketing'))->toBe('legacy_ems')
        ->and(Modules::isInMaintenance('event'))->toBeTrue()
        ->and(Modules::isInMaintenance('ticketing'))->toBeFalse();
});
