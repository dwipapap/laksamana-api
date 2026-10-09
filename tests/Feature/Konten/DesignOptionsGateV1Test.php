<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/*
 * G-16: holders of `marketing` read Konten's brands and active crew through
 * the narrow design-options read — the old Request Design form took them
 * straight from konten getAll. No other Konten state is handed over, and
 * Konten record CRUD stays konten-only.
 * The users are made here, inside the test transaction, holding exactly one
 * module each.
 */

beforeEach(function () {
    config(['laksamana.modules.konten.data_dir' => storage_path('framework/testing/konten-db')]);
});

/** A throwaway active user holding exactly the given modules. */
function koUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

/**
 * Token for a user, dropping the guard's cached user: this file switches
 * identity inside one test, and the guard would otherwise keep answering as
 * whoever made the previous request.
 */
function koToken(array $u): string
{
    // After the login, not before: the login request itself carries the previous
    // test()->withToken() header, so the guard caches that user again.
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}

/** Legacy ids of the rows in a konten table's `data` JSON. */
function koIds(string $table): array
{
    $out = [];
    foreach (Modules::db('konten')->select(ktSql("SELECT data FROM $table")) as $row) {
        $d = json_decode((string) $row->data, true);
        if (is_array($d) && ! empty($d['id'])) {
            $out[] = (string) $d['id'];
        }
    }

    return $out;
}

it('G-16: marketing holders read brands and active crew, nothing else', function () {
    $res = $this->withToken(koToken(koUser('u-g16-mkt', ['marketing'])))->getJson('/api/v1/konten/design-options')
        ->assertOk();

    $data = $res->json('data');
    expect(array_keys($data))->toEqualCanonicalizing(['brands', 'pics']);

    $brands = $data['brands'];
    expect(collect($brands)->pluck('id')->all())->toEqualCanonicalizing(koIds('brands'));
    foreach ($brands as $b) {
        expect(array_keys($b))->toEqualCanonicalizing(['id', 'name']);
    }

    // Active crew only: every inactive id in the table is absent, every active one present.
    $active = [];
    $inactive = [];
    foreach (Modules::db('konten')->select(ktSql('SELECT data FROM users')) as $row) {
        $d = json_decode((string) $row->data, true);
        if (! is_array($d) || empty($d['id'])) {
            continue;
        }
        if (($d['active'] ?? null) === false) {
            $inactive[] = (string) $d['id'];
        } else {
            $active[] = (string) $d['id'];
        }
    }
    $pics = $data['pics'];
    expect(collect($pics)->pluck('id')->all())->toEqualCanonicalizing($active);
    foreach ($pics as $p) {
        expect(array_keys($p))->toEqualCanonicalizing(['id', 'name', 'roles']);
        expect($p['roles'])->toBeArray();
    }
    expect(array_intersect(collect($pics)->pluck('id')->all(), $inactive))->toBe([]);

    // Production roles first, then by name — the legacy picker order.
    $prod = ['designer', 'video_editor', 'photographer', 'content_director', 'content_planner'];
    $seenPlain = false;
    foreach ($pics as $p) {
        $isProd = (bool) array_intersect($p['roles'], $prod);
        if (! $isProd) {
            $seenPlain = true;
        } elseif ($seenPlain) {
            $this->fail('A production crew member is listed after a non-production one.');
        }
    }
});

it('G-16: konten holders keep the narrow read, outsiders get 403', function () {
    $this->withToken(koToken(koUser('u-g16-konten', ['konten'])))->getJson('/api/v1/konten/design-options')
        ->assertOk()->assertJsonStructure(['data' => ['brands', 'pics']]);
    $this->withToken(koToken(koUser('u-g16-luar', [])))->getJson('/api/v1/konten/design-options')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('G-16: konten record CRUD stays konten-only', function () {
    $token = koToken(koUser('u-g16-mkt2', ['marketing']));

    $this->withToken($token)->getJson('/api/v1/konten/brands')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => 'x'])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});
