<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * #61: EMS tables in core, imported from the restored EMS database: the legacy
 * indexed columns verbatim, the full row in `data`, and the legacy ids in
 * `legacy_id` (event_details keeps its natural key `event_id`).
 */

const EMS_TABLES = ['calendar_extra', 'checkins', 'event_details', 'events', 'ideas', 'orders',
    'recurring_rules', 'refunds', 'schedules', 'seats', 'talent_payments', 'talents',
    'ticket_classes', 'tickets'];

function emsSnapshot(): array
{
    $out = [];
    foreach ([...array_map(fn ($t) => 'event_'.$t, EMS_TABLES), 'event_pengaturan'] as $t) {
        $out[$t] = DB::connection('core')->table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'event'])->assertSuccessful();
});

it('copies every legacy EMS row 1:1 and is idempotent', function () {
    foreach (EMS_TABLES as $t) {
        $legacy = DB::connection('legacy_ems')->table($t)->get();
        $legacyId = $t === 'event_details' ? 'event_id' : 'id';
        $core = DB::connection('core')->table('event_'.$t)->get()->keyBy('legacy_id');
        // The set of ids must match; core's read order (ULID PK) is not the
        // legacy sort order, so sort both sides (#140).
        expect($core->keys()->sort()->values()->all())->toBe($legacy->pluck($legacyId)->map(fn ($v) => (string) $v)->sort()->values()->all(), $t);
        $hasStamp = Schema::connection('legacy_ems')->hasColumn($t, 'updated_at');
        foreach ($legacy as $r) {
            $row = $core[(string) $r->{$legacyId}];
            expect($row->data)->toBe($r->data);
            if ($hasStamp) { // #140: legacy checkins has no updated_at
                expect((int) $row->updated_at)->toBe((int) $r->updated_at, $t);
            }
        }
    }
    expect(DB::connection('core')->table('event_pengaturan')->pluck('k')->sort()->values()->all())
        ->toBe(DB::connection('legacy_ems')->table('settings')->pluck('k')->sort()->values()->all());

    $before = emsSnapshot();
    $this->artisan('core:import', ['module' => 'event'])->assertSuccessful();
    expect(emsSnapshot())->toBe($before);
});

it('follows legacy edits and deletions on re-import', function () {
    $legacy = DB::connection('legacy_ems');
    $t = $legacy->table('talents')->orderBy('id')->first();
    $gone = $legacy->table('talents')->orderBy('id', 'desc')->first();
    $legacy->table('talents')->where('id', $t->id)->update(['name' => 'Diubah di legacy', 'updated_at' => $t->updated_at + 1]);
    $legacy->table('talents')->where('id', $gone->id)->delete();

    $this->artisan('core:import', ['module' => 'event'])->assertSuccessful();

    $row = DB::connection('core')->table('event_talents')->where('legacy_id', $t->id)->first();
    expect($row->name)->toBe('Diubah di legacy')->and((int) $row->version)->toBe(2)
        ->and(DB::connection('core')->table('event_talents')->where('legacy_id', $gone->id)->exists())->toBeFalse();
});

it('keeps the event_details natural key and its legacy id', function () {
    $detail = DB::connection('core')->table('event_event_details')->orderBy('event_id')->first();
    expect($detail->legacy_id)->toBe($detail->event_id)
        ->and((bool) DB::connection('core')->table('event_event_details')->where('event_id', $detail->event_id)->exists())->toBeTrue();
});
