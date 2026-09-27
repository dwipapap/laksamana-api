<?php

use Illuminate\Support\Facades\DB;

/*
 * #59: bd tables in core, imported from the restored bd DB: indexed columns
 * verbatim, the full row in `data`, legacy ids in `legacy_id`, and
 * people.office_user_id resolved to its core User.
 */

const BD_TABLES = ['people', 'projects', 'tasks', 'routines', 'coord_requests', 'purchase_orders', 'purchase_requests', 'agenda'];

function bdSnapshot(): array
{
    $out = [];
    foreach ([...array_map(fn ($t) => 'bd_'.$t, BD_TABLES), 'bd_pengaturan'] as $t) {
        $out[$t] = DB::connection('core')->table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'bd'])->assertSuccessful();
});

it('copies every legacy row 1:1 and is idempotent', function () {
    foreach (BD_TABLES as $t) {
        $legacy = DB::connection('legacy_bd')->table($t)->orderBy('id')->get();
        $core = DB::connection('core')->table('bd_'.$t)->orderBy('legacy_id')->get()->keyBy('legacy_id');
        expect($core->keys()->all())->toBe($legacy->pluck('id')->map(fn ($v) => (string) $v)->sort()->values()->all(), $t);
        foreach ($legacy as $r) {
            expect($core[$r->id]->data)->toBe($r->data)->and((int) $core[$r->id]->updated_at)->toBe((int) $r->updated_at);
        }
    }
    expect(DB::connection('core')->table('bd_pengaturan')->pluck('k')->sort()->values()->all())
        ->toBe(DB::connection('legacy_bd')->table('settings')->pluck('k')->sort()->values()->all());

    $before = bdSnapshot();
    $this->artisan('core:import', ['module' => 'bd'])->assertSuccessful();
    expect(bdSnapshot())->toBe($before);
});

it('links BD crew to their core User', function () {
    $linked = DB::connection('legacy_bd')->table('people')->whereNotNull('office_user_id')->where('office_user_id', '<>', '')->first();
    if (! $linked) {
        $this->markTestSkipped('no BD crew linked to an Office User in this dump');
    }
    $user = DB::connection('core')->table('user')->where('legacy_id', $linked->office_user_id)->value('id');
    expect(DB::connection('core')->table('bd_people')->where('legacy_id', $linked->id)->value('user_id'))->toBe($user);
});

it('follows legacy edits and deletions on re-import', function () {
    $legacy = DB::connection('legacy_bd');
    $t = $legacy->table('tasks')->orderBy('id')->first();
    $gone = $legacy->table('tasks')->orderBy('id', 'desc')->first();
    $legacy->table('tasks')->where('id', $t->id)->update(['name' => 'Diubah di legacy', 'updated_at' => $t->updated_at + 1]);
    $legacy->table('tasks')->where('id', $gone->id)->delete();

    $this->artisan('core:import', ['module' => 'bd'])->assertSuccessful();
    $row = DB::connection('core')->table('bd_tasks')->where('legacy_id', $t->id)->first();
    expect($row->name)->toBe('Diubah di legacy')->and((int) $row->version)->toBe(2)
        ->and(DB::connection('core')->table('bd_tasks')->where('legacy_id', $gone->id)->exists())->toBeFalse();
});
