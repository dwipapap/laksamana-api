<?php

use App\Modules\Bd\Services\BdState;
use App\Modules\Event\Services\EventSchema;
use App\Modules\Homepage\Models\HomepageBanner;
use App\Modules\Homepage\Models\HomepageEvent;
use App\Modules\Homepage\Services\HomepagePhotos;
use App\Support\Modules;
use App\Support\RowSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

// ─────────────────────────── promo fixtures ──

/** The raw BD `promos` document as stored (for before/after comparisons). */
function bdPromosDocument(): ?string
{
    $row = DB::connection(Modules::connectionName('bd'))
        ->table(BdState::table('settings'))->where('k', 'promos')->first();

    return $row?->v;
}

/** Write the BD `promos` document exactly as BD OS owns it. */
function bdSetPromos(array $promos): void
{
    DB::connection(Modules::connectionName('bd'))
        ->table(BdState::table('settings'))
        ->updateOrInsert(['k' => 'promos'], ['v' => json_encode($promos)]);
}

/**
 * One BD promo with every secret field a leak test can look for. Dates are
 * relative to today WIB: running by default (`mulai` in the past, `selesai` in
 * the future), `paused` false.
 */
function bdPromo(array $over = []): array
{
    $wib = CarbonImmutable::now('Asia/Jakarta');
    $id = (string) ($over['id'] ?? 'pr_'.Str::lower(Str::random(7)));

    return array_merge([
        'id' => $id,
        'nama' => 'Promo Uji '.$id,
        'tipe' => 'Diskon',
        'kategori' => 'Minuman',
        'benefit' => 'Diskon 10%',
        'partner' => 'PARTNER_RAHASIA',
        'kode' => 'KODE_RAHASIA',
        'ketentuan' => 'Syarat rahasia',
        'outlet' => 'Laksamana Muda',
        'hari' => 'Senin',
        'jamMulai' => '10:00',
        'jamSelesai' => '22:00',
        'kuota' => 100,
        'lmPIC' => 'PIC_RAHASIA',
        'poster' => bdPosterDataUrl(),
        'mulai' => $wib->subDays(2)->toDateString(),
        'selesai' => $wib->addDays(2)->toDateString(),
        'paused' => false,
    ], $over);
}

function bdPosterDataUrl(string $bytes = 'JPEGBODY'): string
{
    return 'data:image/jpeg;base64,'.base64_encode($bytes);
}

/** Store a real file in the homepage photo folder; returns its key. */
function homepagePhoto(string $key, string $bytes = 'IMG'): string
{
    $dir = app(HomepagePhotos::class)->dir();
    File::put($dir.'/'.$key, $bytes);

    return $key;
}

/** Insert one homepage_banner row directly (an `unggah` row gets a real file). */
function bannerRow(array $over = []): HomepageBanner
{
    $over['sumber'] = (string) ($over['sumber'] ?? 'unggah');
    if ($over['sumber'] === 'unggah') {
        $over['gambar_key'] = (string) ($over['gambar_key'] ?? 'hb_'.Str::lower(Str::random(8)).'.jpg');
        if (! app(HomepagePhotos::class)->exists($over['gambar_key'])) {
            homepagePhoto($over['gambar_key']);
        }
    }

    return HomepageBanner::query()->create(array_merge([
        'alt' => 'Banner uji',
        'tampil' => false,
        'urutan' => 0,
    ], $over));
}

/** The raw homepage_banner row, or [] when none exists. */
function bannerDbRow(string $id): array
{
    return (array) DB::connection('core')->table('homepage_banner')->where('id', $id)->first();
}

/** A real 1x1 PNG (so the upload path sees an image mime). */
function promoPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'promo.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
    );
}
