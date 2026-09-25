<?php

/*
|--------------------------------------------------------------------------
| Module registry
|--------------------------------------------------------------------------
| One entry per legacy `<modul>-mysql` backend of laksamana-office.
|
| - `connection`: the Laravel DB connection its models use. Today every module
|   points at its own legacy database (hybrid phase). Consolidating a module
|   into the unified `core` database later means copying its tables there and
|   changing ONLY this value — models never hard-code a connection.
| - `database`: default legacy DB name (production naming). Override per
|   environment with DB_<ENV_KEY>_DATABASE (dev: lakk5493_db_dev_<mod>).
| - `data_dir`: existing on-disk folder the legacy backend writes files to.
|   Kept byte-compatible so old and new backends can run side by side.
| - `legacy`: URL path(s) of the old backend, mirrored by the compat routes.
|
| config/database.php reads this file to build the connections, so it must
| stay a plain array without calls to config().
*/

$modules = [
    'account' => ['env' => 'ACCOUNT',   'database' => 'lakk5493_db_account',   'legacy' => 'account-api-mysql'],
    'absensi' => ['env' => 'ABSENSI',   'database' => 'lakk5493_db_absensi',   'legacy' => 'absensi/api'],
    'akademi' => ['env' => 'AKADEMI',   'database' => 'lakk5493_db_akademi',   'legacy' => 'akademi-api-mysql',   'data_dir' => '/home/lakk5493/akademi-db'],
    'bd' => ['env' => 'BD',        'database' => 'lakk5493_db_bd',        'legacy' => 'bd-api-mysql', 'server_sql_mode' => true],
    'dw' => ['env' => 'DW',        'database' => 'lakk5493_db_dw',        'legacy' => 'dw-api-mysql'],
    // event+ticketing share ONE connection (legacy_ems): the flag on either applies to both.
    'event' => ['env' => 'EMS',       'database' => 'lakk5493_db_ems',       'legacy' => 'event-api-mysql',     'data_dir' => '/home/lakk5493/event-db', 'server_sql_mode' => true],
    'ticketing' => ['env' => 'EMS',       'database' => 'lakk5493_db_ems',       'legacy' => 'ticketing-api'],
    'finance' => ['env' => 'FINANCE',   'database' => 'lakk5493_db_finance',   'legacy' => 'finance-api-mysql'],
    // server_sql_mode (hlife, bd, hr): do not force Laravel's strict sql_mode — the legacy PDO used
    // the server default, and production (non-strict) stores e.g. hlife dreams year "" as
    // tahun=0. Strict mode would reject writes legacy accepted (see #97 for the other modules).
    'hlife' => ['env' => 'HLIFE',     'database' => 'lakk5493_db_hlife',     'legacy' => 'howandi-life-api-mysql', 'server_sql_mode' => true],
    'hr' => ['env' => 'HR',        'database' => 'lakk5493_db_hr',        'legacy' => 'hr-api-mysql', 'server_sql_mode' => true],
    'jadwal' => ['env' => 'JADWAL',    'database' => 'lakk5493_db_jadwal',    'legacy' => 'jadwal-api-mysql'],
    'kompas' => ['env' => 'KOMPAS',    'database' => 'lakk5493_db_kompas',    'legacy' => 'kompas-api-mysql',    'data_dir' => '/home/lakk5493/kompas-db'],
    'konten' => ['env' => 'KONTEN',    'database' => 'lakk5493_db_konten',    'legacy' => 'konten-api-mysql',    'data_dir' => '/home/lakk5493/konten-db'],
    'marketing' => ['env' => 'MARKETING', 'database' => 'lakk5493_db_marketing', 'legacy' => 'marketing-api-mysql', 'data_dir' => '/home/lakk5493/marketing-db'],
    'reservasi' => ['env' => 'RESERVASI', 'database' => 'lakk5493_db_reservasi', 'legacy' => 'reservasi-api-mysql', 'data_dir' => '/home/lakk5493/reservasi-db'],
    'stock' => ['env' => 'STOCK',     'database' => 'lakk5493_db_stock',     'legacy' => 'stock-api-mysql',     'data_dir' => '/home/lakk5493/data-latih'],
];

foreach ($modules as $key => &$m) {
    // event & ticketing share the EMS database -> share one connection.
    $m['connection'] = env('DB_'.$m['env'].'_CONNECTION', 'legacy_'.strtolower($m['env']));
    if (isset($m['data_dir'])) {
        $m['data_dir'] = env(strtoupper($key).'_DATA_DIR', $m['data_dir']);
    }
}
unset($m);

return [
    'modules' => $modules,

    // Read-only stubs for databases that have no Office backend yet (out of v1 scope).
    'extra_connections' => [
        'LMB' => 'lakk5493_db_lmb',
        'SITE' => 'lakk5493_laksamanamuda',
    ],

    // Label reported by legacy `ping`/`stats` (the old ENV_LABEL): 'produksi' | 'dev' | 'lokal'.
    'env_label' => env('LAKSAMANA_ENV_LABEL', 'lokal'),

    // Optional shared API_TOKEN the old backends accepted via ?token= / body.token.
    // Empty = open (the default everywhere today). Only honoured on legacy routes.
    'legacy_api_token' => env('LEGACY_API_TOKEN', ''),

    // howandi-life's own API_TOKEN (checked by its compat controller only). It is
    // embedded in the frontend HTML, so it is not a secret. Empty = open.
    'hlife_api_token' => env('HLIFE_API_TOKEN', 'HL-5mHh8Lfu8bpiPMkgtRphSmvM'),
];
