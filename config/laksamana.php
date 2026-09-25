<?php

/*
|--------------------------------------------------------------------------
| Module registry
|--------------------------------------------------------------------------
| One entry per legacy `<modul>-mysql` backend of laksamana-office.
|
| - `connection`: the Laravel DB connection its models use. Every Modul has
|   its own DB_<KEY>_CONNECTION override, so event and ticketing can move to
|   `core` independently even though they still share `legacy_ems` by default.
| - `maintenance`: opt-in write guard for an offline cutover. Reads continue;
|   set <KEY>_MAINTENANCE=true only after that Modul is ready to freeze.
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
    // event+ticketing share the legacy EMS database, but their connection and maintenance switches are independent.
    'event' => ['env' => 'EMS',       'database' => 'lakk5493_db_ems',       'legacy' => 'event-api-mysql',     'data_dir' => '/home/lakk5493/event-db', 'server_sql_mode' => true],
    'ticketing' => ['env' => 'EMS',       'database' => 'lakk5493_db_ems',       'legacy' => 'ticketing-api'],
    'finance' => ['env' => 'FINANCE',   'database' => 'lakk5493_db_finance',   'legacy' => 'finance-api-mysql', 'server_sql_mode' => true],
    // server_sql_mode (hlife, bd, hr, event, finance, kompas, stock, reservasi): do not force Laravel's strict sql_mode — the legacy PDO used
    // the server default, and production (non-strict) stores e.g. hlife dreams year "" as
    // tahun=0. Strict mode would reject writes legacy accepted (see #97 for the other modules).
    'hlife' => ['env' => 'HLIFE',     'database' => 'lakk5493_db_hlife',     'legacy' => 'howandi-life-api-mysql', 'server_sql_mode' => true],
    'hr' => ['env' => 'HR',        'database' => 'lakk5493_db_hr',        'legacy' => 'hr-api-mysql', 'server_sql_mode' => true],
    'jadwal' => ['env' => 'JADWAL',    'database' => 'lakk5493_db_jadwal',    'legacy' => 'jadwal-api-mysql'],
    'kompas' => ['env' => 'KOMPAS',    'database' => 'lakk5493_db_kompas',    'legacy' => 'kompas-api-mysql',    'data_dir' => '/home/lakk5493/kompas-db', 'server_sql_mode' => true],
    'konten' => ['env' => 'KONTEN',    'database' => 'lakk5493_db_konten',    'legacy' => 'konten-api-mysql',    'data_dir' => '/home/lakk5493/konten-db'],
    'marketing' => ['env' => 'MARKETING', 'database' => 'lakk5493_db_marketing', 'legacy' => 'marketing-api-mysql', 'data_dir' => '/home/lakk5493/marketing-db'],
    'reservasi' => ['env' => 'RESERVASI', 'database' => 'lakk5493_db_reservasi', 'legacy' => 'reservasi-api-mysql', 'data_dir' => '/home/lakk5493/reservasi-db', 'server_sql_mode' => true],
    'stock' => ['env' => 'STOCK',     'database' => 'lakk5493_db_stock',     'legacy' => 'stock-api-mysql',     'data_dir' => '/home/lakk5493/data-latih', 'server_sql_mode' => true],
];

// Legacy routes dispatch by action, not HTTP method. During an offline cutover
// only these actions may continue; unknown and every write action are refused.
$legacyPolicies = [
    'account' => [
        'default' => 'ping',
        'read' => ['whoami', 'listUsers', 'listModules', 'listAccess', 'listModuleMembers', 'listModuleRoster', 'listDivisiRoster', 'sessionRefresh', 'ping', 'stats'],
    ],
    'absensi' => [
        'default' => 'konteks',
        'read' => ['konteks', 'wajahDaftar', 'antrean', 'rekap', 'ping', 'stats'],
    ],
    'akademi' => [
        'default' => 'getAll',
        'read' => ['getAll', 'stats', 'trainingStats', 'receipt', 'ping'],
    ],
    'bd' => [
        'default' => 'getAll',
        'read' => ['getAll', 'stats', 'ping'],
    ],
    'dw' => [
        'default' => 'getAll',
        'read' => ['getAll', 'jadwalDW', 'stats', 'ping'],
    ],
    'event' => [
        'default' => 'getAll',
        'read' => ['getAll', 'stats', 'ping', 'eventsHari', 'file'],
    ],
    'ticketing' => [
        'default' => '',
        'read' => ['ping', 'events', 'event', 'poster', 'denah', 'order', 'saya', 'tiketSaya'],
    ],
    'finance' => [
        'default' => 'getAll',
        'query_first' => true,
        'read' => ['getAll', 'ping', 'stats', 'brankasGet', 'invStatus', 'invBerkas', 'invDaftar', 'invAntre'],
    ],
    'hlife' => [
        'default' => '',
        'read' => ['ping', 'stats', 'getAll'],
    ],
    'hr' => [
        'default' => '',
        'read' => ['ping', 'stats', 'getAll'],
    ],
    'jadwal' => [
        'default' => 'getAll',
        'read' => ['getAll', 'shiftHari', 'headIds', 'stats', 'ping'],
    ],
    'kompas' => [
        'default' => 'getAll',
        'read' => ['getAll', 'omsetPic', 'stats', 'ping', 'performaDivisi', 'investorRingkas', 'investorAgenda', 'investorLaporFile', 'analyticsGet', 'voidList', 'briList'],
    ],
    'konten' => [
        'default' => 'getAll',
        'read' => ['getAll', 'stats', 'ping', 'receipt'],
    ],
    'marketing' => [
        'default' => 'getAll',
        'read' => ['getAll', 'stats', 'ping', 'eventsHari', 'dpMasuk', 'designReqs', 'designReq', 'receipt'],
    ],
    'reservasi' => [
        'default' => 'getAll',
        'read' => ['getAll', 'getFile', 'stats', 'ping'],
    ],
    'stock' => [
        'default' => '',
        'method_guard' => true,
        'error_style' => 'status',
    ],
];

foreach ($modules as $key => &$m) {
    $envKey = strtoupper($key);
    // A per-Modul override wins; the shared env connection keeps existing
    // deployments unchanged, including event+ticketing on legacy_ems.
    $m['connection'] = env(
        'DB_'.$envKey.'_CONNECTION',
        env('DB_'.$m['env'].'_CONNECTION', 'legacy_'.strtolower($m['env']))
    );
    $m['maintenance'] = filter_var(env($envKey.'_MAINTENANCE', false), FILTER_VALIDATE_BOOL);
    if (isset($m['data_dir'])) {
        $m['data_dir'] = env(strtoupper($key).'_DATA_DIR', $m['data_dir']);
    }
}
unset($m);

return [
    'modules' => $modules,
    'legacy_policies' => $legacyPolicies,

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

    // stock: team scoping of usage/waste/serah (legacy BATAS_PER_TIM). Unset = on only when
    // the env label is 'dev' (legacy default); production turns it on deliberately.
    'stock_batas_per_tim' => env('STOCK_BATAS_PER_TIM'),

    // howandi-life's own API_TOKEN (checked by its compat controller only). It is
    // embedded in the frontend HTML, so it is not a secret. Empty = open.
    'hlife_api_token' => env('HLIFE_API_TOKEN', 'HL-5mHh8Lfu8bpiPMkgtRphSmvM'),
];
