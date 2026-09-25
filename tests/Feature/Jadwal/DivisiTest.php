<?php

use App\Modules\Jadwal\Services\HeadDirectory;
use App\Support\Divisi;
use App\Support\Modules;

/*
 * The Divisi service (App\Support\Divisi) is the single copy of Divisi
 * resolution, the heads list and the Tim word lists, used by account
 * (OfficeAccess) and jadwal (JadwalService). The compare-all test against
 * the legacy algorithm lives on the roster endpoint in JadwalTest; here:
 * the Penempatan Divisi branch (the restored dump has an empty divOverride,
 * so nothing else covers it), the account side (headDivisi on whoami), and
 * the word lists themselves (twins in legacy PHP + JS must change in pairs).
 */

it('lets Penempatan Divisi override the Tim words', function () {
    $db = Modules::db('jadwal');
    $data = json_decode($db->selectOne('SELECT data FROM jadwal_setting WHERE id = 1')->data, true);
    // u-andry has an empty Tim (nonshift); u-arif has Tim "Bar".
    $data['divOverride'] = ['u-andry' => 'floor', 'u-arif' => 'kitchen'];
    $db->update('UPDATE jadwal_setting SET data = ? WHERE id = 1',
        [json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    app(HeadDirectory::class)->flush();

    $rows = $this->withToken(loginAs(officeUser('u-arif')))->getJson('/api/v1/jadwal/roster')
        ->assertOk()->json('data');
    $byId = [];
    foreach ($rows as $r) {
        $byId[$r['id']] = $r['divisi'];
    }
    expect($byId['u-andry'])->toBe('floor')
        ->and($byId['u-arif'])->toBe('kitchen')
        ->and($byId['u-mella'])->toBe('floor'); // untouched: Tim "Floor, FOH"
});

it('carries Kepala Divisi from the Divisi service on whoami', function () {
    app(HeadDirectory::class)->flush();
    $me = $this->withToken(loginAs(officeUser('u-arif')))->getJson('/api/v1/me')
        ->assertOk()->json('data');
    expect($me['headDivisi'])->toContain('bar');
});

it('reports no divisions for plain crew on whoami', function () {
    app(HeadDirectory::class)->flush();
    $me = $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->getJson('/api/v1/me')
        ->assertOk()->json('data');
    expect($me['headDivisi'])->toBe([]);
});

it('keeps a single copy of the Divisi, office and Tim words', function () {
    expect(Divisi::SYNONYMS)->toBe([
        'bar' => ['bar', 'bartender'],
        'kitchen' => ['kitchen', 'dapur'],
        'floor' => ['floor', 'service', 'waiter', 'waitress', 'host', 'hostess'],
        'cashier' => ['cashier', 'kasir'],
    ])->and(Divisi::OFFICE_WORDS)->toBe(['office', 'kantor'])
        ->and(Divisi::NONSHIFT)->toBe('nonshift')
        ->and(Divisi::TIM_BAWAAN_JADWAL)->toContain('hrd', 'bar', 'kasir')
        ->and(Divisi::TIM_BOLEH_DW)->toBe(['hrd', 'hr', 'ceo'])
        ->and(Divisi::TIM_ADMIN_ROSTER)->toBe(['hrd', 'hr'])
        ->and(Divisi::MODUL_ADMIN_BAWAAN)->toBe(['jadwal', 'dw']);
});
