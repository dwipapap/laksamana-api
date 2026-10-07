<?php

use App\Modules\Automation\Support\ReadDb;
use App\Support\Modules;
use Illuminate\Support\Facades\File;

/*
 * #234 architecture guards: the automation_ro connection is a single door,
 * only the Automation module may name it or use ReadDb, and no Automation
 * file reads env() directly (config:cache would freeze those values).
 *
 * These are source scans instead of Pest's `arch()` helpers: an agent
 * worktree links its vendor packages to the main checkout, which makes
 * pest-plugin-arch treat every vendor namespace as user code and fatal on
 * optional Symfony classes. The scans assert the same rules everywhere.
 */

/** app/ files that mention $needle outside $allowedDir. */
function scanAppFor(string $needle, string $allowedDir): array
{
    $offenders = [];
    foreach (File::allFiles(app_path()) as $file) {
        if (! str_ends_with($file->getFilename(), '.php') || ! str_contains($file->getContents(), $needle)) {
            continue;
        }
        if (str_starts_with((string) $file->getRealPath(), $allowedDir.DIRECTORY_SEPARATOR)) {
            continue;
        }
        $offenders[] = $file->getRelativePathname();
    }

    return $offenders;
}

/** Does the source actually call env(...)? Comments and strings do not count. */
function callsEnvFunction(string $code): bool
{
    $tokens = token_get_all($code);
    foreach ($tokens as $i => $token) {
        $isEnvName = is_array($token)
            && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($token[1], '\\')) === 'env';
        if (! $isEnvName) {
            continue;
        }
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $next === '(';
        }
    }

    return false;
}

it('only the Automation module uses ReadDb in app/', function () {
    $root = realpath(app_path('Modules/Automation'));

    expect(scanAppFor('ReadDb', $root))->toBe([]);
});

it('only the Automation module names the automation_ro connection in app/', function () {
    $root = realpath(app_path('Modules/Automation'));

    expect(scanAppFor('automation_ro', $root))->toBe([]);
});

it('Automation code never calls env() directly', function () {
    $offenders = [];
    foreach (File::allFiles(app_path('Modules/Automation')) as $file) {
        if (str_ends_with($file->getFilename(), '.php') && callsEnvFunction($file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('ReadDb falls back to the module connection when AUTOMATION_DB_HOST is empty', function () {
    expect(config('database.connections.automation_ro.host'))->toBe('')
        ->and(ReadDb::for('marketing'))->toBe(Modules::db('marketing'));
});

it('ReadDb swaps the automation_ro database per module when pinned', function () {
    config([
        'database.connections.automation_ro.host' => '127.0.0.1',
        'database.connections.automation_ro.username' => 'recap_ro',
    ]);

    $marketing = ReadDb::for('marketing');
    $event = ReadDb::for('event');

    expect($marketing->getDatabaseName())->toBe((string) config('laksamana.modules.marketing.database'))
        ->and($marketing)->not->toBe(Modules::db('marketing'))
        ->and($event->getDatabaseName())->toBe((string) config('laksamana.modules.event.database'));
});
