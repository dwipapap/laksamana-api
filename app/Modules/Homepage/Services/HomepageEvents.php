<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Services;

use App\Modules\Event\Services\EventState;
use App\Modules\Homepage\Models\HomepageEvent;
use App\Modules\Ticketing\Services\TicketShop;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * The homepage event feed: which EMS events the website shows, and the
 * public/office shapes around them.
 *
 * The event data stays in EMS and is read ONLY through `EventState::emsRows`
 * (ADR-0002) — this module never writes a single EMS row. The switch lives in
 * the greenfield `homepage_event` table in `core`.
 *
 * One eligibility rule, shared by the public feed, the office list and the
 * toggles: `eligible()` (status Upcoming/Today AND not past, the same status
 * list as TicketShop::sellable()). A row switched on that stops being eligible
 * simply falls out of the website; it is never deleted.
 */
class HomepageEvents
{
    /** The public clock: `start`/`end` are rendered in WIB. */
    public const TZ = 'Asia/Jakarta';

    /** Venue shown when the EMS row has none. */
    public const VENUE_DEFAULT = 'Laksamana Muda';

    /** A switched-on event that just ended stays visible in the Office list this long. */
    public const OFFICE_PAST_DAYS = 7;

    /** Statuses an event may be switched on in (the same list as TicketShop::sellable()). */
    public const ELIGIBLE_STATUSES = ['Upcoming', 'Today'];

    public function __construct(
        private readonly EventState $ems,
        private readonly TicketShop $shop,
    ) {}

    // ─────────────────────────── eligibility ──

    /** Management-approved (status) AND not past — the single "boleh tampil" rule. */
    public static function eligible(array $event, ?CarbonImmutable $now = null): bool
    {
        if (! in_array((string) ($event['status'] ?? ''), self::ELIGIBLE_STATUSES, true)) {
            return false;
        }

        return ! self::isPast($event, $now);
    }

    /**
     * Past when `end_datetime` < now, or — with no end — when the WIB date of
     * `start_datetime` is before today WIB. Both are UTC instants (suffix Z).
     */
    public static function isPast(array $event, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('UTC');
        $end = self::instant($event['end_datetime'] ?? null);
        if ($end !== null) {
            return $end->lessThan($now);
        }
        $start = self::instant($event['start_datetime'] ?? null);
        if ($start === null) {
            return false; // no dates to judge: an undated event is never "past"
        }

        return $start->setTimezone(self::TZ)->startOfDay()
            ->lessThan($now->setTimezone(self::TZ)->startOfDay());
    }

    // ─────────────────────────── public feed ──

    /**
     * Every event with `tampil = true` AND eligible, in start order.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        $on = HomepageEvent::query()->where('tampil', true)->pluck('event_id')->all();
        if ($on === []) {
            return [];
        }
        $on = array_flip($on);

        $items = [];
        foreach ($this->ems->emsRows('events', [], 'start_datetime ASC') as $event) {
            $id = (string) ($event['id'] ?? '');
            if ($id === '' || ! isset($on[$id]) || ! self::eligible($event)) {
                continue;
            }
            $items[] = $this->publicRow($event);
        }

        return $items;
    }

    /** The allow-list projection of §Public (never "everything minus secrets"). @return array<string,mixed> */
    public function publicRow(array $event): array
    {
        $id = (string) ($event['id'] ?? '');

        return [
            'id' => $id,
            'title' => (string) ($event['title'] ?? ''),
            'category' => (string) ($event['category'] ?? ''),
            'start' => self::iso($event['start_datetime'] ?? null),
            'end' => self::iso($event['end_datetime'] ?? null),
            'venue' => self::venue($event),
            'description' => (string) ($event['description'] ?? ''),
            'poster' => self::hasPoster($event) ? '/api/v1/homepage/events/'.$id.'/poster' : null,
            'price_from' => $this->priceFrom($id),
            'is_ticketed' => ! empty($event['is_ticketed']),
        ];
    }

    // ─────────────────────────── office list & toggle ──

    /**
     * The candidate rows for the Office switch screen: every EMS event that is
     * not past (any status but the clearly dead Cancelled), plus a switched-on
     * event that ended in the last `OFFICE_PAST_DAYS` days so staff can see why
     * it vanished. Start order.
     *
     * @return list<array<string,mixed>>
     */
    public function officeList(): array
    {
        $now = CarbonImmutable::now('UTC');
        $switches = HomepageEvent::query()->get()->keyBy('event_id');

        $rows = [];
        foreach ($this->ems->emsRows('events', [], 'start_datetime ASC') as $event) {
            $id = (string) ($event['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $past = self::isPast($event, $now);
            $switch = $switches->get($id);
            $tampil = $switch ? (bool) $switch->tampil : false;

            if ($past) {
                if (! ($tampil && self::pastWithin($event, $now, self::OFFICE_PAST_DAYS))) {
                    continue;
                }
            } elseif ((string) ($event['status'] ?? '') === 'Cancelled') {
                continue; // clearly dead and not past: not a candidate
            }

            $rows[] = $this->officeRow($event, $switch, self::eligible($event, $now), $past);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function officeRow(array $event, ?HomepageEvent $switch, bool $eligible, bool $past): array
    {
        $id = (string) ($event['id'] ?? '');

        return [
            'id' => $id,
            'title' => (string) ($event['title'] ?? ''),
            'category' => (string) ($event['category'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'start' => self::iso($event['start_datetime'] ?? null),
            'end' => self::iso($event['end_datetime'] ?? null),
            'venue' => self::venue($event),
            'poster' => self::hasPoster($event) ? '/api/v1/homepage/office/events/'.$id.'/poster' : null,
            'price_from' => $this->priceFrom($id),
            'is_ticketed' => ! empty($event['is_ticketed']),
            'tampil' => $switch ? (bool) $switch->tampil : false,
            'eligible' => $eligible,
            'alasan' => $eligible ? null : ($past ? 'sudah_lewat' : 'belum_upcoming'),
            'version' => $switch ? (int) $switch->version : 0,
        ];
    }

    /**
     * Upsert the switch for one event. Switching OFF is always allowed; switching
     * ON requires the event to be eligible right now. Returns the office row.
     *
     * @return array<string,mixed>
     */
    public function setTampil(string $id, bool $tampil, ?string $actor): array
    {
        $rows = $this->ems->emsRows('events', ['id' => $id]);
        if (! $rows) {
            throw new RuntimeException('not_found');
        }
        $event = $rows[0];
        if ($tampil && ! self::eligible($event)) {
            throw new RuntimeException('tidak_eligible');
        }

        $switch = HomepageEvent::query()->where('event_id', $id)->first();
        if ($switch === null) {
            $switch = new HomepageEvent([
                'event_id' => $id,
                'tampil' => $tampil,
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
        } else {
            $switch->tampil = $tampil;
            $switch->updated_by = $actor;
        }
        $switch->save();

        return $this->officeRow($event, $switch->fresh(), self::eligible($event), self::isPast($event));
    }

    // ─────────────────────────── posters ──

    /**
     * The poster of an event that is BOTH switched on and eligible. Only by way
     * of the event id: the EMS folder also holds talent IDs and transfer proofs,
     * so a raw key is never accepted from a client.
     *
     * @return array{file:string,type:string}|array{redirect:string}|null
     */
    public function posterPublic(string $id): ?array
    {
        $rows = $this->ems->emsRows('events', ['id' => $id]);
        if (! $rows || ! self::eligible($rows[0])) {
            return null;
        }
        if (! HomepageEvent::query()->where('event_id', $id)->where('tampil', true)->exists()) {
            return null;
        }

        return $this->shop->posterAny($id);
    }

    /**
     * The poster of any candidate event for the Office preview. Still only by
     * event id — never a client-supplied key.
     *
     * @return array{file:string,type:string}|array{redirect:string}|null
     */
    public function posterOffice(string $id): ?array
    {
        return $this->shop->posterAny($id);
    }

    // ─────────────────────────── helpers ──

    /** The cheapest ticket class price > 0, or 0 (the same rule as TicketShop::summary()). */
    private function priceFrom(string $eventId): int
    {
        $prices = [];
        foreach ($this->ems->emsRows('ticketClasses', ['event_id' => $eventId]) as $class) {
            $price = (int) ($class['price'] ?? 0);
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        return $prices === [] ? 0 : min($prices);
    }

    private static function hasPoster(array $event): bool
    {
        return ! empty($event['poster_img']['key']);
    }

    private static function venue(array $event): string
    {
        $venue = trim((string) ($event['venue'] ?? ''));

        return $venue === '' ? self::VENUE_DEFAULT : $venue;
    }

    /** The reference instant an event "ended": `end` first, else `start`. */
    private static function pastWithin(array $event, CarbonImmutable $now, int $days): bool
    {
        $ref = self::instant($event['end_datetime'] ?? null) ?? self::instant($event['start_datetime'] ?? null);
        if ($ref === null) {
            return false;
        }

        return $ref->greaterThanOrEqualTo($now->subDays($days));
    }

    /** Parse an EMS instant (UTC, suffix Z) or null. */
    private static function instant(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || ! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** ISO-8601 in WIB (`+07:00`), or null. */
    public static function iso(mixed $value): ?string
    {
        $t = self::instant($value);

        return $t?->setTimezone(self::TZ)->toIso8601String();
    }
}
