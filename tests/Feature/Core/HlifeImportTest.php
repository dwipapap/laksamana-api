<?php

use Illuminate\Support\Facades\DB;

/*
 * #65: hlife tables in core, imported from the restored hlife DB: indexed
 * columns verbatim, the full row in `data`, legacy ids in `legacy_id`, and
 * the settings documents verbatim. No Office User links exist in hlife.
 */

const HLIFE_TABLES = ['businesses', 'projects', 'tasks', 'goals', 'dreams', 'roadmap',
    'content', 'learning', 'habits', 'events', 'assets', 'reviews', 'ledger'];

function hlifeSnapshot(): array
{
    $out = [];
    foreach ([...array_map(fn ($t) => 'hlife_'.$t, HLIFE_TABLES), 'hlife_pengaturan'] as $t) {
        $out[$t] = DB::connection('core')->table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'hlife'])->assertSuccessful();
});

it('copies every legacy row 1:1 and is idempotent', function () {
    foreach (HLIFE_TABLES as $t) {
        $legacy = DB::connection('legacy_hlife')->table($t)->orderBy('id')->get();
        $core = DB::connection('core')->table('hlife_'.$t)->orderBy('legacy_id')->get()->keyBy('legacy_id');
        expect($core->keys()->all())->toBe($legacy->pluck('id')->map(fn ($v) => (string) $v)->sort()->values()->all(), $t);
        foreach ($legacy as $r) {
            expect($core[$r->id]->data)->toBe($r->data);
        }
    }
    expect(DB::connection('core')->table('hlife_pengaturan')->pluck('k')->sort()->values()->all())
        ->toBe(DB::connection('legacy_hlife')->table('settings')->pluck('k')->sort()->values()->all());

    $before = hlifeSnapshot();
    $this->artisan('core:import', ['module' => 'hlife'])->assertSuccessful();
    expect(hlifeSnapshot())->toBe($before);
});

it('follows legacy edits and deletions on re-import', function () {
    $legacy = DB::connection('legacy_hlife');
    $t = $legacy->table('tasks')->orderBy('id')->first();
    $gone = $legacy->table('tasks')->orderBy('id', 'desc')->first();
    $legacy->table('tasks')->where('id', $t->id)->update(['nama' => 'Diubah di legacy']);
    $legacy->table('tasks')->where('id', $gone->id)->delete();

    $this->artisan('core:import', ['module' => 'hlife'])->assertSuccessful();
    $row = DB::connection('core')->table('hlife_tasks')->where('legacy_id', $t->id)->first();
    expect($row->nama)->toBe('Diubah di legacy')->and((int) $row->version)->toBe(2)
        ->and(DB::connection('core')->table('hlife_tasks')->where('legacy_id', $gone->id)->exists())->toBeFalse();
});
