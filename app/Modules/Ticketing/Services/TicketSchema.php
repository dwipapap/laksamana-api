<?php

declare(strict_types=1);

namespace App\Modules\Ticketing\Services;

use App\Support\Modules;
use Illuminate\Support\Str;

/**
 * Where the public shop's OWN tables live (#61): seat_holds and the tix_*
 * tables. The EMS tables it uses (events, ticket_classes, seats, orders,
 * tickets) belong to the event module and are reached through EventState —
 * never through these helpers (ADR-0002).
 *
 * `settings` is not here: ticketing does not read the EMS settings.
 */
final class TicketSchema
{
    public const LIB_VERSION = '2026-07-31a';

    /** legacy table => core table */
    private const CORE_TABLES = [
        'seat_holds' => 'ticketing_seat_holds',
        'tix_users' => 'ticketing_buyers',
        'tix_sessions' => 'ticketing_sessions',
        'tix_reset' => 'ticketing_resets',
        'tix_gagal' => 'ticketing_gagal',
    ];

    /** Ticketing served from core (#61)? */
    public static function onCore(): bool
    {
        return Modules::connectionName('ticketing') === 'core';
    }

    /** Physical table for a legacy table name on the current connection. */
    public static function table(string $legacy): string
    {
        if (! self::onCore()) {
            return $legacy;
        }

        return self::CORE_TABLES[$legacy] ?? throw new \InvalidArgumentException("unknown ticketing table $legacy");
    }

    /**
     * The row key. On core the legacy primary key lives in `legacy_id`
     * (ticketing sessions and resets are keyed by `token` in legacy).
     */
    public static function idCol(string $legacy): string
    {
        if (! self::onCore()) {
            return $legacy === 'tix_sessions' || $legacy === 'tix_reset' ? 'token' : 'id';
        }

        return 'legacy_id';
    }

    /** A fresh row id: ULID on core, the legacy `id` column is kept in legacy_id. */
    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }
}
