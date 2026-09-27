<?php

use App\Auth\AccountUser;
use App\Support\Divisi;
use App\Support\JsonDoc;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #43: identity tables in core, imported from the restored account + jadwal DBs.
 */

const IDENTITY_TABLES = ['user' => 'legacy_id', 'modul' => 'kunci', 'izin_akses' => 'legacy_id', 'larangan' => 'legacy_id',
    'admin_modul' => 'legacy_id', 'divisi' => 'kode', 'divisi_kata' => 'kata', 'kepala_divisi' => 'legacy_id',
    'penempatan_divisi' => 'legacy_id', 'sesi_legacy' => 'token'];

function identitySnapshot(): array
{
    $out = [];
    foreach (IDENTITY_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

/** divisi_kata as the Divisi::resolve() word lists: [synonyms by divisi.urutan, office words]. */
function wordsFromCore(): array
{
    $syn = [];
    $office = [];
    foreach (DB::connection('core')->table('divisi_kata as k')->leftJoin('divisi as d', 'd.id', '=', 'k.divisi_id')
        ->orderBy('d.urutan')->orderBy('k.created_at')->orderBy('k.id')->get(['d.kode', 'k.kata']) as $r) {
        if ($r->kode === null) {
            $office[] = $r->kata;
        } else {
            $syn[$r->kode][] = $r->kata;
        }
    }

    return [$syn, $office];
}

it('imports identity twice into identical rows, mapping every legacy id 1:1', function () {
    $acc = DB::connection('legacy_account');
    $setting = JsonDoc::toArray(JsonDoc::decode(DB::connection('legacy_jadwal')->table('jadwal_setting')->where('id', 1)->value('data')));
    $headIds = [];
    foreach ($setting['heads'] ?? [] as $div => $ids) {
        foreach ($ids as $id) {
            $headIds[] = "$div|$id";
        }
    }

    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $first = identitySnapshot();
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    expect(identitySnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck(IDENTITY_TABLES[$t])->sort()->values()->all();
    $pairs = fn ($rows) => $rows->map(fn ($r) => "$r->user_id|$r->module")->sort()->values()->all();
    expect($keys('user'))->toBe($acc->table('users')->pluck('id')->sort()->values()->all())
        ->and($keys('modul'))->toBe($acc->table('modules')->pluck('key')->sort()->values()->all())
        ->and($keys('izin_akses'))->toBe($pairs($acc->table('grants')->where('access', 1)->get()))
        ->and($keys('larangan'))->toBe($pairs($acc->table('grants')->where('access', 0)->get()))
        ->and($keys('admin_modul'))->toBe($pairs($acc->table('admins')->get()))
        ->and($keys('sesi_legacy'))->toBe($acc->table('sessions')->pluck('token')->sort()->values()->all())
        ->and($keys('kepala_divisi'))->toBe(collect($headIds)->sort()->values()->all())
        ->and(collect($first['user'])->every(fn ($u) => Str::isUlid($u['id']) && $u['version'] === 1))->toBeTrue();

    // `*` is modul_id NULL, not a magic string.
    $superadmins = $acc->table('admins')->where('module', '*')->count();
    expect(DB::connection('core')->table('admin_modul')->whereNull('modul_id')->count())->toBe($superadmins);

    // One copy of the Divisi words, in legacy order.
    expect(wordsFromCore())->toBe([Divisi::SYNONYMS, Divisi::OFFICE_WORDS]);
});

it('refuses by FK to delete a User who is Kepala Divisi', function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $head = DB::connection('core')->table('kepala_divisi')->value('user_id');
    expect($head)->not->toBeNull();

    expect(fn () => DB::connection('core')->table('user')->where('id', $head)->delete())
        ->toThrow(QueryException::class);

    DB::connection('core')->table('kepala_divisi')->where('user_id', $head)->delete();
    expect(DB::connection('core')->table('user')->where('id', $head)->delete())->toBe(1);
});

it('seeds foh as one floor divisi_kata row so Tim "FOH" resolves to floor (#3)', function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $floor = DB::connection('core')->table('divisi')->where('kode', 'floor')->value('id');

    // Owner decision #3: foh -> floor everywhere in this API, so the import
    // writes the word as one divisi_kata row (the identity cutover shape).
    expect(DB::connection('core')->table('divisi_kata')->where('kata', 'foh')->value('divisi_id'))->toBe($floor)
        ->and(Divisi::resolve('x', [], ['keterangan' => 'FOH'], ...wordsFromCore()))->toBe('floor');
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $acc = DB::connection('legacy_account');
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $grant = $acc->table('grants')->where('access', 1)->where('module', '<>', '*')->first();
    $user = (string) $acc->table('users')->orderBy('id')->value('id');

    $acc->table('grants')->where('user_id', $grant->user_id)->where('module', $grant->module)->delete();
    $acc->table('users')->where('id', $user)->update(['name' => 'Nama Baru', 'updated_at' => '2030-01-01 00:00:00']);
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();

    $row = DB::connection('core')->table('user')->where('legacy_id', $user)->first();
    expect(DB::connection('core')->table('izin_akses')->where('legacy_id', "$grant->user_id|$grant->module")->exists())->toBeFalse()
        ->and($row->nama)->toBe('Nama Baru')
        ->and($row->version)->toBe(2);
});

it('re-keys Sanctum tokens to the User ULID', function () {
    $legacy = (string) DB::connection('legacy_account')->table('users')->orderBy('id')->value('id');
    $token = AccountUser::query()->findOrFail($legacy)->createToken('t')->accessToken;

    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $ulid = DB::connection('core')->table('user')->where('legacy_id', $legacy)->value('id');
    expect($token->fresh()->tokenable_id)->toBe($ulid);

    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    expect($token->fresh()->tokenable_id)->toBe($ulid);
});
