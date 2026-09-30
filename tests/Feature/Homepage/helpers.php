<?php

use App\Modules\Event\Services\EventSchema;
use App\Modules\Homepage\Models\HomepageEvent;
use App\Support\Modules;
use App\Support\RowSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Shared fixtures for the homepage tests. The switch is greenfield on `core`;
 * the events live in EMS and are inserted on whichever storage the Event module
 * is on (`core` in this environment, `legacy_ems` when the dumps are restored),
 * exactly as EventState would write them.
 */

/** A Sanctum token for the superadmin test user (module:homepage passes via '*'). */
function homepageToken(): string
{
    return loginAs(officeUser('u-wandi'));
}

/** Insert one EMS event row. Returns its legacy (EMS) id. */
function emsEvent(array $over = []): string
{
    $id = (string) ($over['id'] ?? 'ev_'.Str::lower(Str::random(8)));
    $now = CarbonImmutable::now('UTC');
    $data = array_merge([
        'id' => $id,
        'title' => 'Event Uji',
        'category' => 'Music',
        'status' => 'Upcoming',
        'venue' => 'Laksamana Muda',
        'start_datetime' => $now->addDays(2)->toIso8601ZuluString(),
        'end_datetime' => $now->addDays(2)->addHours(4)->toIso8601ZuluString(),
        'is_ticketed' => true,
        'description' => 'Deskripsi event uji.',
        'updatedAt' => emsMs(),
    ], $over);

    $columns = [
        'title' => $data['title'],
        'category' => $data['category'],
        'status' => $data['status'],
        'venue' => $data['venue'],
        'start_datetime' => RowSync::datetimeWib($data['start_datetime']),
        'end_datetime' => RowSync::datetimeWib($data['end_datetime']),
        'capacity' => (int) ($data['capacity'] ?? 0),
        'pic' => (string) ($data['pic'] ?? ''),
        'is_ticketed' => ! empty($data['is_ticketed']) ? 1 : 0,
        'updated_at' => $data['updatedAt'],
        'created_at' => $data['updatedAt'],
        'data' => json_encode($data),
    ];
    if (EventSchema::onCore()) {
        $columns = ['id' => strtolower((string) Str::ulid()), 'legacy_id' => $id]
            + $columns + ['version' => 1, 'created_by' => null, 'updated_by' => null];
    } else {
        $columns = ['id' => $id] + $columns;
    }

    emsInsert('events', $columns);

    return $id;
}

/** Insert one EMS ticket class for an event. */
function emsTicketClass(string $eventId, int $price, array $over = []): string
{
    $id = (string) ($over['id'] ?? 'tc_'.Str::lower(Str::random(8)));
    $data = array_merge([
        'id' => $id,
        'event_id' => $eventId,
        'name' => 'Reguler',
        'price' => $price,
        'quota' => 100,
        'sold' => 0,
        'is_seated' => false,
        'updatedAt' => emsMs(),
    ], $over);

    $columns = [
        'event_id' => $eventId,
        'name' => $data['name'],
        'price' => $data['price'],
        'quota' => $data['quota'],
        'sold' => $data['sold'],
        'is_seated' => ! empty($data['is_seated']) ? 1 : 0,
        'updated_at' => $data['updatedAt'],
        'data' => json_encode($data),
    ];
    if (EventSchema::onCore()) {
        // the legacy ticket_classes table has no created_at column; core does
        $columns = ['id' => strtolower((string) Str::ulid()), 'legacy_id' => $id, 'created_at' => $data['updatedAt']]
            + $columns + ['version' => 1, 'created_by' => null, 'updated_by' => null];
    } else {
        $columns = ['id' => $id] + $columns;
    }

    emsInsert('ticketClasses', $columns);

    return $id;
}

/** The EMS connection + physical table for a collection, on either storage. */
function emsInsert(string $collection, array $columns): void
{
    $def = EventSchema::defs()[$collection];

    DB::connection(Modules::connectionName('event'))
        ->table($def['table'])
        ->insert($columns);
}

/** One EMS event row (physical), or null. */
function emsEventRow(string $id): ?object
{
    return DB::connection(Modules::connectionName('event'))
        ->table(EventSchema::table('events'))
        ->where(EventSchema::idCol(), $id)
        ->first();
}

/** Patch an EMS event row (data blob + the derived columns). */
function emsUpdateEvent(string $id, array $over): void
{
    $row = emsEventRow($id);
    $data = json_decode((string) $row->data, true) ?: [];
    $data = array_merge($data, $over);
    $data['updatedAt'] = emsMs();

    $columns = ['updated_at' => $data['updatedAt'], 'data' => json_encode($data)];
    foreach (['title', 'category', 'status', 'venue'] as $field) {
        if (array_key_exists($field, $data)) {
            $columns[$field] = $data[$field];
        }
    }
    if (array_key_exists('start_datetime', $data)) {
        $columns['start_datetime'] = RowSync::datetimeWib($data['start_datetime']);
    }
    if (array_key_exists('end_datetime', $data)) {
        $columns['end_datetime'] = RowSync::datetimeWib($data['end_datetime']);
    }
    if (array_key_exists('is_ticketed', $data)) {
        $columns['is_ticketed'] = ! empty($data['is_ticketed']) ? 1 : 0;
    }
    if (EventSchema::onCore()) {
        $columns['version'] = ((int) $row->version) + 1;
    }

    DB::connection(Modules::connectionName('event'))
        ->table(EventSchema::table('events'))
        ->where(EventSchema::idCol(), $id)
        ->update($columns);
}

/** The raw homepage_event switch row for an event, or [] when none exists. */
function homepageSwitchRow(string $eventId): array
{
    return (array) DB::connection('core')->table('homepage_event')->where('event_id', $eventId)->first();
}

/** Flip the website switch directly (bypassing the API). */
function homepageSwitch(string $eventId, bool $tampil): void
{
    HomepageEvent::query()->updateOrCreate(['event_id' => $eventId], ['tampil' => $tampil]);
}

function emsMs(): int
{
    return (int) round(microtime(true) * 1000);
}
