<?php

use Illuminate\Support\Facades\DB;

/*
 * #67: the finance_* tables in core, imported from the restored finance DB:
 * legacy ids in `legacy_id`, the split rows and the page/role maps verbatim,
 * '#<user id>' resolved to its core User, and finance_counter seeded from the
 * legacy AUTO_INCREMENT so a row created on core continues the legacy numbering.
 */

const FIN_TABLES = ['kk_pos', 'kk_kategori', 'kk_trx', 'kk_trx_pos', 'kk_akses', 'kk_peran',
    'bk_akses', 'bk_peran', 'bk_state', 'inv_kwitansi', 'inv_penanda', 'inv_setting'];

/** The k/v maps keep their natural key; every other table its legacy id. */
function finCoreKey(string $t): string
{
    return match ($t) {
        'kk_peran', 'bk_peran' => 'kunci',
        'inv_setting' => 'k',
        'finance_counter' => 'name',
        default => 'legacy_id',
    };
}

function finSnapshot(): array
{
    $out = [];
    foreach ([...FIN_TABLES, 'finance_counter'] as $t) {
        $q = DB::connection('core')->table(str_starts_with($t, 'finance_') ? $t : 'finance_'.$t);
        $out[$t] = $q->orderBy(finCoreKey($t) === 'legacy_id' ? 'id' : finCoreKey($t))->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'finance'])->assertSuccessful();
});

it('copies every legacy row 1:1 and is idempotent', function () {
    foreach (FIN_TABLES as $t) {
        $key = finCoreKey($t);
        $legacy = DB::connection('legacy_finance')->table($t)->get();
        $core = DB::connection('core')->table('finance_'.$t)->get()->keyBy($key);
        expect($core->count())->toBe($legacy->count(), $t);
        foreach ($legacy as $r) {
            expect($core->has((string) ($key === 'legacy_id' ? $r->id : $r->$key)))->toBeTrue("$t row missing");
        }
    }

    // one table per shape: a ledger row, the split rows, a page matrix, the vault
    // document and the two document tables the other modules call
    $trx = DB::connection('legacy_finance')->table('kk_trx')->orderBy('id')->first();
    $row = DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $trx->id)->first();
    expect($row->tgl)->toBe($trx->tgl)->and($row->keterangan)->toBe($trx->keterangan)
        ->and($row->kategori_id)->toBe($trx->kategori_id === null ? null : (string) $trx->kategori_id)
        ->and((int) $row->input)->toBe((int) $trx->input)->and((int) $row->bon)->toBe((int) $trx->bon)
        ->and((int) $row->dibuat_at)->toBe((int) $trx->dibuat_at)->and($row->dibuat_oleh)->toBe($trx->dibuat_oleh)
        ->and((int) $row->created_at)->toBe((int) $trx->dibuat_at); // the legacy creation stamp

    $pos = DB::connection('legacy_finance')->table('kk_trx_pos')->orderBy('id')->first();
    $child = DB::connection('core')->table('finance_kk_trx_pos')->where('legacy_id', (string) $pos->id)->first();
    expect($child->trx_id)->toBe((string) $pos->trx_id)->and($child->pos_id)->toBe((string) $pos->pos_id)
        ->and((int) $child->debet)->toBe((int) $pos->debet)->and((int) $child->kredit)->toBe((int) $pos->kredit);

    $ak = DB::connection('legacy_finance')->table('kk_akses')->orderBy('id')->first();
    expect(DB::connection('core')->table('finance_kk_akses')->where('legacy_id', (string) $ak->id)->value('tingkat'))->toBe((int) $ak->tingkat);

    $st = DB::connection('legacy_finance')->table('bk_state')->first();
    $state = DB::connection('core')->table('finance_bk_state')->where('legacy_id', (string) $st->id)->first();
    expect($state->data)->toBe($st->data)->and((int) $state->updated_at)->toBe((int) $st->updated_at)
        ->and($state->diubah_oleh)->toBe($st->updated_by); // renamed: updated_by is the actor FK now

    $inv = DB::connection('legacy_finance')->table('inv_kwitansi')->orderBy('id')->first();
    $req = DB::connection('core')->table('finance_inv_kwitansi')->where('legacy_id', $inv->id)->first();
    expect($req->res_id)->toBe($inv->res_id)->and($req->no_invoice)->toBe($inv->no_invoice)
        ->and($req->ringkas)->toBe($inv->ringkas)->and($req->penanda)->toBe($inv->penanda)
        ->and($req->jenis)->toBe($inv->jenis)->and($req->status)->toBe($inv->status);

    $pen = DB::connection('legacy_finance')->table('inv_penanda')->orderBy('id')->first();
    $sig = DB::connection('core')->table('finance_inv_penanda')->where('legacy_id', $pen->id)->first();
    expect($sig->nama)->toBe($pen->nama)->and($sig->jabatan)->toBe($pen->jabatan)
        ->and((int) $sig->urut)->toBe((int) $pen->urut)->and(strlen((string) $sig->ttd))->toBe(strlen((string) $pen->ttd));

    expect(DB::connection('core')->table('finance_inv_setting')->pluck('k')->sort()->values()->all())
        ->toBe(DB::connection('legacy_finance')->table('inv_setting')->pluck('k')->sort()->values()->all());

    $before = finSnapshot();
    $this->artisan('core:import', ['module' => 'finance'])->assertSuccessful();
    expect(finSnapshot())->toBe($before);
});

it('links the #<user id> role keys to their core User', function () {
    $row = DB::connection('core')->table('finance_kk_peran')->whereNotNull('user_id')->first();
    if (! $row) {
        $this->markTestSkipped('no finance role key resolves to an Office User in this dump');
    }
    expect(DB::connection('core')->table('user')->where('id', $row->user_id)->value('legacy_id'))
        ->toBe(ltrim((string) $row->kunci, '#'));
});

it('seeds finance_counter so new ids continue the legacy numbering', function () {
    foreach (['kk_pos', 'kk_kategori', 'kk_trx', 'kk_trx_pos', 'kk_akses', 'bk_akses'] as $t) {
        $ddl = (array) DB::connection('legacy_finance')->selectOne('SHOW CREATE TABLE `'.$t.'`');
        $sql = (string) ($ddl['Create Table'] ?? ($ddl ? reset($ddl) : ''));
        $want = preg_match('/AUTO_INCREMENT=(\d+)/', $sql, $m) ? (int) $m[1] : 1;
        expect((int) DB::connection('core')->table('finance_counter')->where('name', $t)->value('next_value'))->toBe($want, $t);
    }
});

it('follows legacy edits and deletions on re-import', function () {
    $legacy = DB::connection('legacy_finance');
    $pos = $legacy->table('kk_pos')->orderBy('id')->first();
    $gone = $legacy->table('kk_trx')->orderBy('id', 'desc')->first();
    $legacy->table('kk_pos')->where('id', $pos->id)->update(['nama' => 'Pos Diubah']);
    $legacy->table('kk_trx')->where('id', $gone->id)->delete();

    $this->artisan('core:import', ['module' => 'finance'])->assertSuccessful();

    $row = DB::connection('core')->table('finance_kk_pos')->where('legacy_id', $pos->id)->first();
    expect($row->nama)->toBe('Pos Diubah')->and((int) $row->version)->toBe(2)
        ->and(DB::connection('core')->table('finance_kk_trx')->where('legacy_id', $gone->id)->exists())->toBeFalse()
        // the transaction's split rows are gone with it (the legacy FK cascaded too)
        ->and(DB::connection('core')->table('finance_kk_trx_pos')->where('trx_id', $gone->id)->exists())->toBeFalse();
});
