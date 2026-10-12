<?php

/*
|--------------------------------------------------------------------------
| Public denah links
|--------------------------------------------------------------------------
| Base for the temporary table-map links minted by
| POST /api/v1/automation/denah-link and opened by guests. It defaults to
| APP_URL, so nothing changes until the short public domain is wired:
|
|     DENAH_PUBLIC_BASE=https://laksamanamuda.id
|
| hands guests https://laksamanamuda.id/d/<token>, where a small forwarder
| (docs/deploy/denah-forwarder) relays the request to this API. Keep it a
| plain origin with no trailing slash: the token is appended as /d/<token>.
| The API's own /d/<token> route stays valid either way.
*/
return [
    'public_base' => rtrim((string) env('DENAH_PUBLIC_BASE', env('APP_URL', 'http://localhost')), '/'),
];
