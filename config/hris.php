<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HRIS AI screening (B5)
|--------------------------------------------------------------------------
| The Muse Spark key lives ONLY here (server .env, never logged, never
| served). HRIS_AI_URL points at the screening endpoint once the API
| research in docs/modules/hris.md lands; until then screening is refused
| with a clear error instead of guessing a provider contract.
*/

return [
    'ai_url' => env('HRIS_AI_URL', ''),
    'ai_key' => env('HRIS_AI_KEY', ''),
];
