<?php

use App\Modules\Event\Services\EventSchema;
use Illuminate\Support\Str;

/**
 * An event SQL statement in legacy table/id names, run on whichever storage the
 * Modul is on. On core the EMS row tables take the `event_` prefix (`events` ->
 * `event_events`, `event_details` -> `event_event_details`, `settings` ->
 * `event_pengaturan`) and the legacy id moves to `legacy_id` (a fresh ULID
 * becomes the PK); reads alias it back to `id` — the same mapping
 * EventSchema::table()/idCol() make. See docs/db/event.md.
 */
function evSql(string $sql): string
{
    if (! EventSchema::onCore()) {
        return $sql;
    }

    if (preg_match('/^\s*INSERT\s+INTO/i', $sql)) {
        // (`id`, …) VALUES ('legacy', …) -> (`id`,`legacy_id`, …) VALUES (<ulid>, 'legacy', …)
        $sql = preg_replace('/\((`?)id\1\s*,/', '(${1}id${1}, `legacy_id`,', $sql, 1);
        $sql = preg_replace_callback('/\bVALUES\b(.*)$/is', function (array $m): string {
            // one fresh ULID per value tuple (the legacy id follows it)
            return 'VALUES'.preg_replace_callback('/\(\s*/', fn (): string => "('".strtolower((string) Str::ulid())."',", $m[1]);
        }, $sql);

        return evTables($sql);
    }

    $sql = preg_replace('/\bSELECT\s+(`?)id\b(`?)/', 'SELECT ${1}legacy_id${2} AS __ID__', $sql);
    $sql = preg_replace('/\bid\b/', 'legacy_id', $sql);
    $sql = str_replace('__ID__', 'id', $sql);

    return evTables($sql);
}

/** Legacy EMS table -> core table (docs/db/event.md). */
function evTables(string $sql): string
{
    $map = [];
    foreach (['talents', 'events', 'schedules', 'recurring_rules', 'talent_payments', 'ticket_classes',
        'seats', 'orders', 'tickets', 'checkins', 'refunds', 'ideas', 'calendar_extra'] as $t) {
        $map[$t] = 'event_'.$t;
    }
    $map['event_details'] = 'event_event_details';
    $map['settings'] = 'event_pengaturan';

    return preg_replace_callback('/\b(FROM|INTO|UPDATE)\s+(\w+)\b/', function (array $m) use ($map): string {
        return isset($map[$m[2]]) ? $m[1].' '.$map[$m[2]] : $m[0];
    }, $sql);
}
