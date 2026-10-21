<?php

/*
 * api#312: the servers run `php artisan config:cache`. Under a cached config
 * env() returns null everywhere except config/*.php, so app code that calls it
 * silently loses its .env settings. Read settings with config('…') and keep
 * the env() call in config/. Source scan, no DB — runs in CI.
 */

it('calls env() only inside config/', function () {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    foreach (['app', 'routes', 'bootstrap', 'resources'] as $dir) {
        if (! is_dir("$root/$dir")) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php') || str_contains($path, '/bootstrap/cache/')) {
                continue;
            }
            foreach (file($path) as $n => $line) {
                $code = ltrim($line);
                if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue;
                }
                // env( as a function call; not ->env(), ::env(), function env() or $env(.
                if (preg_match('/(?<![\w$>:])env\s*\(/', $code) && ! preg_match('/function\s+env\s*\(/', $code)) {
                    $offenders[] = substr($path, strlen($root) + 1).':'.($n + 1);
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
