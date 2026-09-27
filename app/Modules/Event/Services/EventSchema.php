<?php

namespace App\Modules\Event\Services;

use App\Support\Modules;

/**
 * The collection map of event-mysql (collections() in lib_event_mysql.php),
 * in the RowSync definition format. Order matters: getAll, saveAll's
 * `jumlah` and stats all follow it.
 *
 * String columns use RowSync's `strRaw` (legacy `(string)$v`), datetimes
 * use `datetimeWib` (legacy datetime_valid: strtotime of anything -> WIB).
 * checkins, eventDetails and the settings keys are not table collections;
 * EventState handles them.
 */
final class EventSchema
{
    public const LIB_VERSI = '2026-08-11';

    public const LOCK = 'ems_save';

    public const BUSY = 'Server sedang sibuk menyimpan, coba lagi sebentar.';

    /** Small settings kept as one JSON value each, with their getAll defaults. */
    public const SETTINGS = [
        'entertainmentRules' => [],
        'role' => 'Director',
        'layoutTemplates' => [],
    ];

    /** Tables counted by stats, in legacy order. */
    public const STATS_TABLES = ['talents', 'events', 'event_details', 'schedules', 'recurring_rules',
        'talent_payments', 'ticket_classes', 'seats', 'orders', 'tickets',
        'checkins', 'refunds', 'ideas', 'calendar_extra'];

    public static function collections(): array
    {
        $s = 'strRaw';

        return [
            'talents' => ['table' => 'talents', 'created' => true, 'cols' => [
                'name' => ['name', $s], 'category' => ['category', $s], 'phone' => ['phone', $s],
                'status' => ['status', $s], 'contract_status' => ['contract_status', $s],
                'default_fee' => ['default_fee', 'int'],
            ]],
            'events' => ['table' => 'events', 'created' => true, 'cols' => [
                'title' => ['title', $s], 'category' => ['category', $s], 'status' => ['status', $s],
                'venue' => ['venue', $s],
                'start_datetime' => ['start_datetime', 'datetimeWib'], 'end_datetime' => ['end_datetime', 'datetimeWib'],
                'capacity' => ['capacity', 'int'], 'pic' => ['pic', $s],
                'is_ticketed' => ['is_ticketed', 'bool'], 'idea_id' => ['idea_id', $s],
            ]],
            'schedules' => ['table' => 'schedules', 'created' => true, 'cols' => [
                'talent_id' => ['talent_id', $s], 'event_id' => ['event_id', $s],
                'tanggal' => ['date', 'date'],
                'start_time' => ['start_time', 'time'], 'end_time' => ['end_time', 'time'],
                'performance_type' => ['performance_type', $s], 'fee' => ['fee', 'int'],
                'status' => ['status', $s], 'source' => ['source', $s],
            ]],
            'recurringRules' => ['table' => 'recurring_rules', 'cols' => [
                'talent_id' => ['talent_id', $s], 'valid_from' => ['valid_from', 'date'], 'valid_to' => ['valid_to', 'date'],
            ]],
            'talentPayments' => ['table' => 'talent_payments', 'cols' => [
                'talent_id' => ['talent_id', $s], 'period_month' => ['period_month', $s],
                'show_count' => ['show_count', 'int'], 'total_amount' => ['total_amount', 'int'],
                'status' => ['status', $s],
            ]],
            'ticketClasses' => ['table' => 'ticket_classes', 'cols' => [
                'event_id' => ['event_id', $s], 'name' => ['name', $s], 'price' => ['price', 'int'],
                'quota' => ['quota', 'int'], 'sold' => ['sold', 'int'], 'is_seated' => ['is_seated', 'bool'],
            ]],
            'seats' => ['table' => 'seats', 'cols' => [
                'event_id' => ['event_id', $s], 'ticket_class_id' => ['ticket_class_id', $s],
                'zone' => ['zone', $s], 'table_no' => ['table_no', $s], 'status' => ['status', $s],
            ]],
            // created_at comes from the app field `created`, not createdAt.
            'orders' => ['table' => 'orders', 'created' => true, 'created_field' => 'created', 'cols' => [
                'event_id' => ['event_id', $s], 'buyer_name' => ['buyer_name', $s], 'phone' => ['phone', $s],
                'email' => ['email', $s], 'total' => ['total', 'int'],
                'payment_status' => ['payment_status', $s], 'payment_ref' => ['payment_ref', $s],
            ]],
            'tickets' => ['table' => 'tickets', 'cols' => [
                'order_item_id' => ['order_item_id', $s], 'ticket_class_id' => ['ticket_class_id', $s],
                'seat_id' => ['seat_id', $s], 'ticket_number' => ['ticket_number', $s],
                'qr_token' => ['qr_token', $s], 'status' => ['status', $s],
            ]],
            'ideas' => ['table' => 'ideas', 'cols' => [
                'name' => ['name', $s], 'category' => ['category', $s],
                'frequency' => ['frequency', $s], 'difficulty' => ['difficulty', $s],
            ]],
            'refunds' => ['table' => 'refunds', 'cols' => [
                'order_id' => ['order_id', $s], 'status' => ['status', $s],
            ]],
            'calendarExtra' => ['table' => 'calendar_extra', 'cols' => [
                'type' => ['type', $s], 'title' => ['title', $s], 'tanggal' => ['date', 'date'],
            ]],
        ];
    }

    // ───────────────────────── connection mapping (core cutover, #61) ──

    /** Is event served from `core` (DB_EVENT_CONNECTION=core)? */
    public static function onCore(): bool
    {
        return Modules::connectionName('event') === 'core';
    }

    /** Physical table for a legacy EMS table name on the current connection. */
    public static function table(string $legacy): string
    {
        if (! self::onCore()) {
            return $legacy;
        }

        return $legacy === 'settings' ? 'event_pengaturan' : 'event_'.$legacy;
    }

    /** The row key: the legacy id, kept in `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    /**
     * The collection definitions with the current connection applied. On core
     * every table is `event_*`, the key is `legacy_id`, and rows take the
     * cutover options: a fresh ULID `id` and a `version` that counts accepted
     * writes only (an older row the guard refuses is not a write).
     */
    public static function defs(): array
    {
        $core = self::onCore();
        $out = [];
        foreach (self::collections() as $name => $c) {
            $c['id'] = self::idCol();
            $c['table'] = self::table($c['table']);
            if ($core) {
                $c['ulid'] = true;
                $c['versioned'] = true;
                $c['versionAccepted'] = true;
                $c['coerce'] = true;
            }
            $out[$name] = $c;
        }

        return $out;
    }
}
