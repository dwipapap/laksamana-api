<?php

use Illuminate\Support\Facades\DB;

/*
 * #69: the kompas_* tables of core, imported from the restored kompas DB — the
 * two one-row documents, the accountability and investor tables, the analytics
 * access matrix (its `#<legacy user id>` keys resolved to real Users) and the
 * void percentages as the `void` settings document.
 */

const KP_USER = 'u-novi';

/** The core tables the import writes, in dependency-free order. */
const KP_TABLES = ['kompas_app_state', 'kompas_an_state', 'kompas_an_akses', 'kompas_an_peran', 'kompas_void_log',
    'kompas_bri_mutasi', 'kompas_bri_dp_abai', 'kompas_inv_lapor', 'kompas_pengaturan'];

/**
 * The legacy tables the module maps that are EMPTY in the restored dump are
 * seeded here, so the mapping is exercised in every run instead of passing
 * vacuously on zero rows.
 */
function kompasSeed(): void
{
    $l = DB::connection('legacy_kompas');
    $l->table('void_setting')->insert(['id' => 1, 'tax_persen' => '11.500', 'service_persen' => '4.000',
        'updated_at' => 1790000000000, 'updated_by' => 'Seed Admin']);
    $l->table('void_log')->insert([
        ['id' => 'vseed1', 'tgl' => '2026-09-10', 'bill' => 'B-1', 'item' => 'Es Teh', 'penginput' => 'Kasir A',
            'salah' => 'Kasir A', 'alasan' => 'salah ketik', 'nominal' => 11500, 'subtotal' => 10000, 'service' => 500,
            'tax' => 1000, 'oleh' => 'Seed', 'oleh_id' => KP_USER, 'dibuat' => 1790000000000, 'diubah' => 1790000000000,
            'diubah_oleh' => 'Seed'],
        ['id' => 'vseed2', 'tgl' => '2026-09-11', 'bill' => 'B-2', 'item' => 'Nasi', 'penginput' => 'Kasir B',
            'salah' => 'Dapur', 'alasan' => 'batal', 'nominal' => 20000, 'subtotal' => 20000, 'service' => 0,
            'tax' => 0, 'oleh' => 'Seed', 'oleh_id' => '', 'dibuat' => 1790000000001,
            'diubah' => 1790000000001, 'diubah_oleh' => 'Seed'],
    ]);
    $l->table('bri_mutasi')->insert([
        ['id' => 'bseed1', 'sidik' => '2026-09-10|15:46|300000|#0', 'tgl' => '2026-09-10', 'jam' => '15:46',
            'nominal' => 300000, 'ket' => 'QRIS A', 'sumber' => '', 'cara' => '', 'catatan' => '',
            'oleh' => 'Seed', 'oleh_id' => KP_USER,
            'dibuat' => 1790000000000, 'diubah' => 1790000000000],
        ['id' => 'bseed2', 'sidik' => '2026-09-11|16:00|500000|#0', 'tgl' => '2026-09-11', 'jam' => '16:00',
            'nominal' => 500000, 'ket' => 'QRIS B', 'sumber' => 'manual', 'cara' => 'bukan', 'catatan' => 'sewa',
            'oleh' => 'Seed', 'oleh_id' => '', 'dibuat' => 1790000000001, 'diubah' => 1790000000001],
    ]);
    $l->table('bri_dp_abai')->insert(['dp_id' => 'dp-seed', 'res_id' => 'r-1', 'nama' => 'Tamu A', 'tgl' => '2026-09-12',
        'nominal' => 100000, 'alasan' => 'bukan BRI', 'oleh' => 'Seed', 'abai_at' => 1790000000002]);
    $l->table('inv_lapor')->insert(['bulan' => '2026-08', 'jenis' => 'balance', 'kunci' => 'lp_seed.pdf',
        'nama' => 'Balance.pdf', 'ukuran' => 1234, 'at' => 1790000000003, 'oleh' => 'Seed Admin']);
    $l->table('an_akses')->insert([
        ['kunci' => '#'.KP_USER, 'halaman' => 'harian', 'tingkat' => 2],
        ['kunci' => 'staf', 'halaman' => 'harian', 'tingkat' => 1],
    ]);
    $l->table('an_peran')->insert([
        ['kunci' => '#'.KP_USER, 'peran' => 'manajemen'],
        ['kunci' => '@kasir', 'peran' => 'staf'],
    ]);
}

function kpSnapshot(): array
{
    $out = [];
    foreach (KP_TABLES as $t) {
        $out[$t] = DB::connection('core')->table($t)->orderBy('legacy_id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

/** A driver value as a comparable string: an int column and the legacy string are the same value. */
function kpVal(mixed $v): ?string
{
    return $v === null ? null : (string) $v;
}

beforeEach(function () {
    kompasSeed();
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'kompas'])->assertSuccessful();
});

it('copies every legacy row 1:1 and is idempotent', function () {
    $legacy = DB::connection('legacy_kompas');
    $core = DB::connection('core');

    // the two one-row documents: blob, version and author verbatim
    foreach (['app_state' => 'kompas_app_state', 'an_state' => 'kompas_an_state'] as $from => $to) {
        $r = $legacy->table($from)->where('id', 1)->first();
        $c = $core->table($to)->where('legacy_id', '1')->first();
        expect($c->data)->toBe($r->data)
            ->and((int) $c->updated_at)->toBe((int) $r->updated_at)
            ->and((int) $c->created_at)->toBe((int) $r->updated_at)
            ->and($c->oleh)->toBe((string) $r->updated_by);
    }

    // void_setting (one row) as the `void` settings document
    $s = $legacy->table('void_setting')->where('id', 1)->first();
    expect(json_decode($core->table('kompas_pengaturan')->where('k', 'void')->value('v'), true))
        ->toEqual(['tax_persen' => (float) $s->tax_persen, 'service_persen' => (float) $s->service_persen,
            'updated_at' => (int) $s->updated_at, 'oleh' => (string) $s->updated_by]);

    // void_log / bri_mutasi: every legacy column verbatim (minus the legacy id)
    foreach (['void_log' => 'kompas_void_log', 'bri_mutasi' => 'kompas_bri_mutasi'] as $from => $to) {
        foreach ($legacy->table($from)->orderBy('id')->get() as $r) {
            $c = (array) $core->table($to)->where('legacy_id', $r->id)->first();
            foreach ((array) $r as $col => $v) {
                if ($col !== 'id') {
                    expect(kpVal($c[$col] ?? null))->toBe(kpVal($v), "$to.$col");
                }
            }
        }
    }

    // the tables whose legacy id never leaves the database keep the module's key
    $dp = $legacy->table('bri_dp_abai')->first();
    $c = $core->table('kompas_bri_dp_abai')->where('legacy_id', $dp->dp_id)->first();
    expect($c->dp_id)->toBe($dp->dp_id)->and($c->nama)->toBe($dp->nama)->and($c->alasan)->toBe($dp->alasan)
        ->and($c->oleh)->toBe($dp->oleh)->and((int) $c->abai_at)->toBe((int) $dp->abai_at);

    $l = $legacy->table('inv_lapor')->first();
    $c = $core->table('kompas_inv_lapor')->where('legacy_id', $l->bulan.'|'.$l->jenis)->first();
    expect($c->bulan)->toBe($l->bulan)->and($c->jenis)->toBe($l->jenis)->and($c->kunci)->toBe($l->kunci)
        ->and($c->nama)->toBe($l->nama)->and((int) $c->ukuran)->toBe((int) $l->ukuran)->and((int) $c->at)->toBe((int) $l->at);

    $a = $legacy->table('an_akses')->orderBy('id')->first();
    $c = $core->table('kompas_an_akses')->where('legacy_id', $a->kunci.'|'.$a->halaman)->first();
    expect($c->kunci)->toBe($a->kunci)->and($c->halaman)->toBe($a->halaman)->and((int) $c->tingkat)->toBe((int) $a->tingkat);

    $p = $legacy->table('an_peran')->orderBy('kunci')->first();
    expect($core->table('kompas_an_peran')->where('legacy_id', $p->kunci)->value('peran'))->toBe($p->peran);

    // a second run changes nothing (no new row, no version bump)
    $before = kpSnapshot();
    $this->artisan('core:import', ['module' => 'kompas'])->assertSuccessful();
    expect(kpSnapshot())->toBe($before);
});

it('links the analytics access keys and the void/BRI actor to their core User', function () {
    $core = DB::connection('core');
    $user = $core->table('user')->where('legacy_id', KP_USER)->value('id');
    expect($user)->not->toBeNull()   // the account import ran first
        ->and($core->table('kompas_an_akses')->where('legacy_id', '#'.KP_USER.'|harian')->value('user_id'))->toBe($user)
        ->and($core->table('kompas_an_peran')->where('legacy_id', '#'.KP_USER)->value('user_id'))->toBe($user)
        ->and($core->table('kompas_an_akses')->where('legacy_id', 'staf|harian')->value('user_id'))->toBeNull()
        ->and($core->table('kompas_an_peran')->where('legacy_id', '@kasir')->value('user_id'))->toBeNull()
        // void_log/bri_mutasi.oleh_id is the Office User the Sesi sent
        ->and($core->table('kompas_void_log')->where('legacy_id', 'vseed1')->value('user_id'))->toBe($user)
        ->and($core->table('kompas_bri_mutasi')->where('legacy_id', 'bseed1')->value('user_id'))->toBe($user)
        ->and($core->table('kompas_bri_mutasi')->where('legacy_id', 'bseed2')->value('user_id'))->toBeNull();
});

it('follows legacy edits and deletions on re-import', function () {
    $legacy = DB::connection('legacy_kompas');
    $core = DB::connection('core');

    $row = $legacy->table('void_log')->orderBy('id')->first();
    $gone = $legacy->table('bri_mutasi')->orderBy('id')->first();
    $blob = $legacy->table('app_state')->where('id', 1)->first();
    $legacy->table('void_log')->where('id', $row->id)->update(['item' => 'Diubah di legacy', 'diubah' => (int) $row->diubah + 1]);
    $legacy->table('bri_mutasi')->where('id', $gone->id)->delete();
    $legacy->table('app_state')->where('id', 1)->update(['data' => '{"daily":[{"date":"2026-09-10","food":1000}]}',
        'updated_at' => (int) $blob->updated_at + 1, 'updated_by' => 'Diubah']);

    $this->artisan('core:import', ['module' => 'kompas'])->assertSuccessful();

    $c = $core->table('kompas_void_log')->where('legacy_id', $row->id)->first();
    expect($c->item)->toBe('Diubah di legacy')->and((int) $c->version)->toBe(2)
        ->and($core->table('kompas_bri_mutasi')->where('legacy_id', $gone->id)->exists())->toBeFalse();

    $b = $core->table('kompas_app_state')->where('legacy_id', '1')->first();
    expect(json_decode($b->data, true)['daily'])->toHaveCount(1)->and($b->oleh)->toBe('Diubah')
        ->and((int) $b->version)->toBe(2);
});
