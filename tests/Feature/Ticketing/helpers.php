<?php

use App\Modules\Ticketing\Services\TicketSchema;
use Illuminate\Support\Str;

/**
 * A shop SQL statement in legacy table/id names, run on whichever storage the
 * Modul is on. On core the shop's own tables take the `ticketing_` prefix
 * (`tix_users` -> `ticketing_buyers`, `tix_sessions` -> `ticketing_sessions`,
 * `tix_reset` -> `ticketing_resets`, `seat_holds` -> `ticketing_seat_holds`) and
 * the EMS rows it reads live in `event_*` (`orders`, `tickets`, `seats`). The
 * legacy id — or, for sessions/resets, the `token` that IS the legacy primary
 * key — moves to `legacy_id`, and a fresh ULID becomes the PK; reads alias it
 * back. See docs/db/event.md.
 */
function tixSql(string $sql): string
{
    if (! TicketSchema::onCore()) {
        return $sql;
    }

    // sessions/resets: the legacy key column is `token`, stored in `legacy_id` on core
    if (str_contains($sql, 'tix_sessions') || str_contains($sql, 'tix_reset')) {
        $sql = preg_replace('/\bSELECT\s+token\b/', 'SELECT legacy_id AS __TOKEN__', $sql);
        $sql = preg_replace('/\btoken\b/', 'legacy_id', $sql);
        $sql = str_replace('__TOKEN__', 'token', $sql);
    }

    if (preg_match('/^\s*INSERT\s+INTO/i', $sql)) {
        // sessions/resets start with the token column (now `legacy_id`); every other
        // insert starts with `id`. Add the missing legacy PK column, then a ULID per tuple.
        if (preg_match('/\(\s*`?legacy_id`?\s*,/', $sql)) {
            $sql = preg_replace('/\(\s*`?legacy_id`?\s*,/', '(`id`, `legacy_id`,', $sql, 1);
        } else {
            $sql = preg_replace('/\((`?)id\1\s*,/', '(${1}id${1}, `legacy_id`,', $sql, 1);
        }
        $sql = preg_replace_callback('/\bVALUES\b(.*)$/is', function (array $m): string {
            return 'VALUES'.preg_replace_callback('/\(\s*/', fn (): string => "('".strtolower((string) Str::ulid())."',", $m[1]);
        }, $sql);

        return tixTables($sql);
    }

    $sql = preg_replace('/\bSELECT\s+(`?)id\b(`?)/', 'SELECT ${1}legacy_id${2} AS __ID__', $sql);
    $sql = preg_replace('/\bid\b/', 'legacy_id', $sql);
    $sql = str_replace('__ID__', 'id', $sql);

    return tixTables($sql);
}

/** Legacy shop/EMS table -> core table (docs/db/event.md). */
function tixTables(string $sql): string
{
    $map = [
        'orders' => 'event_orders', 'tickets' => 'event_tickets', 'seats' => 'event_seats',
        'tix_users' => 'ticketing_buyers', 'tix_sessions' => 'ticketing_sessions',
        'tix_reset' => 'ticketing_resets', 'tix_gagal' => 'ticketing_gagal',
        'seat_holds' => 'ticketing_seat_holds',
    ];

    return preg_replace_callback('/\b(FROM|INTO|UPDATE)\s+(\w+)\b/', function (array $m) use ($map): string {
        return isset($map[$m[2]]) ? $m[1].' '.$map[$m[2]] : $m[0];
    }, $sql);
}
