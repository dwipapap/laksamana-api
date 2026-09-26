<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #71: the reservasi tables in core, imported from the restored reservasi DB:
 * indexed columns verbatim, the full row in `data`, the legacy ids in
 * `legacy_id` and the settings documents matched on their legacy key `k`.
 */

function rsSnapshot(): array
{
    $out = [];
    foreach (['reservasi_reservations', 'reservasi_audit', 'reservasi_pengaturan'] as $t) {
        $out[$t] = DB::connection('core')->table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function rsCoreRow(string $table, string $legacyId): ?object
{
    return DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();
}

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'reservasi'])->assertSuccessful();
});

it('copies every reservations and audit row 1:1 and is idempotent', function () {
    $legacy = DB::connection('legacy_reservasi');
    $core = DB::connection('core');

    foreach (['reservations' => 'reservasi_reservations', 'audit' => 'reservasi_audit'] as $lt => $ct) {
        $rows = $legacy->table($lt)->orderBy('id')->get();
        $got = $core->table($ct)->get()->keyBy(fn ($r) => (string) $r->legacy_id);
        expect($got->keys()->sort()->values()->all())->toBe($rows->pluck('id')->map(fn ($v) => (string) $v)->sort()->values()->all(), $lt);
        foreach ($rows as $r) {
            expect((string) $got[(string) $r->id]->data)->toBe((string) $r->data, $lt);
        }
    }

    // the derived columns are exactly what legacy stored, and a row is minted
    // fresh: ULID key, version 1, no actor invented
    $r = $legacy->table('reservations')->orderBy('id')->first();
    $row = rsCoreRow('reservasi_reservations', (string) $r->id);
    expect($row->name)->toBe($r->name)->and($row->phone)->toBe($r->phone)->and($row->tanggal)->toBe($r->tanggal)
        ->and($row->jam)->toBe($r->jam)->and((int) $row->pax)->toBe((int) $r->pax)->and($row->status)->toBe($r->status)
        ->and($row->pic_name)->toBe($r->pic_name)->and($row->source)->toBe($r->source)->and((int) $row->dp_amount)->toBe((int) $r->dp_amount)
        ->and((int) $row->updated_at)->toBe((int) $r->updated_at)->and((int) $row->created_at)->toBe((int) $r->created_at)
        ->and((int) $row->version)->toBe(1)->and(Str::isUlid($row->id))->toBeTrue();

    expect($core->table('reservasi_pengaturan')->pluck('k')->sort()->values()->all())
        ->toBe($legacy->table('settings')->pluck('k')->sort()->values()->all())
        ->and($core->table('reservasi_pengaturan')->where('k', '_ver')->value('v'))
        ->toBe($legacy->table('settings')->where('k', '_ver')->value('v'))
        ->and($core->table('reservasi_pengaturan')->where('k', 'master')->value('v'))
        ->toBe($legacy->table('settings')->where('k', 'master')->value('v'));

    $before = rsSnapshot();
    $this->artisan('core:import', ['module' => 'reservasi'])->assertSuccessful();
    expect(rsSnapshot())->toBe($before);
});

it('invents no User link: the ADR-0003 actors stay NULL behind real FKs', function () {
    $core = DB::connection('core');
    // Reservasi has no Office User per row (the master users map is Service
    // Excellent's blob), so the import never invents one.
    expect($core->table('reservasi_reservations')->whereNotNull('created_by')->orWhereNotNull('updated_by')->count())->toBe(0)
        ->and($core->table('reservasi_audit')->whereNotNull('updated_by')->count())->toBe(0)
        ->and($core->table('reservasi_pengaturan')->whereNotNull('created_by')->count())->toBe(0);

    foreach (['reservasi_reservations', 'reservasi_audit', 'reservasi_pengaturan'] as $t) {
        $fk = $core->select("SELECT CONSTRAINT_NAME n FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = 'user'", [$t]);
        expect($fk)->toHaveCount(2, $t);   // created_by + updated_by
    }
});

it('follows legacy edits and deletions on re-import', function () {
    $legacy = DB::connection('legacy_reservasi');
    $row = $legacy->table('reservations')->orderBy('id')->first();
    $gone = $legacy->table('reservations')->orderBy('id', 'desc')->first();
    $auditGone = $legacy->table('audit')->orderBy('id')->first();
    $legacy->table('reservations')->where('id', $row->id)->update(['name' => 'Diubah di legacy', 'updated_at' => $row->updated_at + 1]);
    $legacy->table('reservations')->where('id', $gone->id)->delete();
    $legacy->table('audit')->where('id', $auditGone->id)->delete();
    $legacy->table('settings')->where('k', '_ver')->update(['v' => '9001']);

    $this->artisan('core:import', ['module' => 'reservasi'])->assertSuccessful();

    $cur = rsCoreRow('reservasi_reservations', (string) $row->id);
    expect($cur->name)->toBe('Diubah di legacy')->and((int) $cur->updated_at)->toBe((int) $row->updated_at + 1)
        ->and((int) $cur->version)->toBe(2)
        ->and(rsCoreRow('reservasi_reservations', (string) $gone->id))->toBeNull()
        ->and(rsCoreRow('reservasi_audit', (string) $auditGone->id))->toBeNull()
        ->and(DB::connection('core')->table('reservasi_pengaturan')->where('k', '_ver')->value('v'))->toBe('9001');
});
