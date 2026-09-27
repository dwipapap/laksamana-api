<?php

/*
| CORS for /api/v1 (ADR-0005): new apps call api.laksamanamuda.id from their own
| origin, so only the origins listed in CORS_ALLOWED_ORIGINS (comma-separated,
| e.g. "https://office.laksamanamuda.id,http://localhost:3000") may read it from
| a browser. Empty = none. The legacy compat URLs are not under api/* and keep
| their own open headers (App\Support\Legacy\LegacyCors), exactly as legacy did.
*/

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['ETag'],
    'max_age' => 0,
    'supports_credentials' => false,
];
