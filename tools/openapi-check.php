<?php

declare(strict_types=1);

/**
 * OpenAPI drift check (#83).
 *
 * Compares docs/api/openapi.json with the live `/api/v1` routes of the account,
 * jadwal and marketing modules and exits non-zero, listing every difference:
 * a route/method missing from the document, or documented but not routed.
 *
 * Path parameters compare by position, so `{id}` vs `{user}` is not drift
 * (each `{…}` segment normalises to `{}`).
 *
 * Run: php tools/openapi-check.php
 */
$root = dirname(__DIR__);
$docPath = $root.'/docs/api/openapi.json';
$prefixes = ['api/v1/account', 'api/v1/jadwal', 'api/v1/marketing'];

/** `api/v1/x/{id}` and `/api/v1/x/{user}` both normalise to `api/v1/x/{}`. */
function normalize(string $path): string
{
    $p = trim($path, '/');
    if ($p === '') {
        return '';
    }

    return implode('/', array_map(fn (string $s): string => preg_match('/^\{.*\}$/', $s) === 1 ? '{}' : $s, explode('/', $p)));
}

function inScope(string $uri, array $prefixes): bool
{
    foreach ($prefixes as $pre) {
        if (str_starts_with($uri, $pre)) {
            return true;
        }
    }

    return false;
}

/** `php artisan route:list --path=api/v1 --json`, without a shell. */
function artisanRoutes(string $root): string
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, 'artisan', 'route:list', '--path=api/v1', '--json'], $descriptors, $pipes, $root);
    if (! is_resource($proc)) {
        return '';
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // keep only the JSON array (a warning line may precede it)
    $start = strpos($stdout, '[');

    return $start === false ? $stdout : substr($stdout, $start);
}

$out = artisanRoutes($root);
$routes = json_decode($out, true);
if (! is_array($routes)) {
    fwrite(STDERR, "openapi-check: could not read `php artisan route:list --path=api/v1 --json`\n");
    exit(2);
}

$live = [];
foreach ($routes as $r) {
    $uri = (string) ($r['uri'] ?? '');
    if (! inScope($uri, $prefixes)) {
        continue;
    }
    foreach (explode('|', (string) ($r['method'] ?? '')) as $m) {
        $m = strtolower(trim($m));
        if ($m === '' || $m === 'head') {
            continue;
        }
        $live[normalize($uri).'|'.$m] = strtoupper($m).' /'.$uri;
    }
}

if (! is_file($docPath)) {
    fwrite(STDERR, "openapi-check: docs/api/openapi.json is missing\n");
    exit(2);
}
$doc = json_decode((string) file_get_contents($docPath), true);
if (! is_array($doc) || ! isset($doc['paths']) || ! is_array($doc['paths'])) {
    fwrite(STDERR, "openapi-check: docs/api/openapi.json is not valid OpenAPI JSON\n");
    exit(2);
}

$documented = [];
foreach ($doc['paths'] as $path => $methods) {
    $uri = trim((string) $path, '/');
    if (! is_array($methods) || ! inScope($uri, $prefixes)) {
        continue;
    }
    foreach (array_keys($methods) as $m) {
        $m = strtolower((string) $m);
        if (! in_array($m, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
            continue; // path-level keys such as `parameters`
        }
        $documented[normalize($uri).'|'.$m] = strtoupper($m).' '.$path;
    }
}

$missing = array_diff_key($live, $documented);
$extra = array_diff_key($documented, $live);

if ($missing === [] && $extra === []) {
    echo 'openapi-check: OK — '.count($live)." route(s) match docs/api/openapi.json\n";
    exit(0);
}

fwrite(STDERR, "openapi-check: docs/api/openapi.json is out of date\n");
foreach ($missing as $desc) {
    fwrite(STDERR, '  missing in the document: '.$desc."\n");
}
foreach ($extra as $desc) {
    fwrite(STDERR, '  in the document but not routed: '.$desc."\n");
}
exit(1);
