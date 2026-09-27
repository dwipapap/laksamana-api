<?php

use App\Auth\AccountRepository;
use App\Modules\Jadwal\Services\HeadDirectory;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;
use App\Support\JsonDoc;

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
    // Through the service, so it lands in jadwal_setting or, with identity on core, penempatan_divisi.
    $jadwal = app(JadwalService::class);
    $data = JsonDoc::toArray($jadwal->setting());
    // u-andry has an empty Tim (nonshift); u-arif has Tim "Bar".
    $data['divOverride'] = ['u-andry' => 'floor', 'u-arif' => 'kitchen'];
    $jadwal->saveSetting($data, 'test');

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
        'floor' => ['floor', 'service', 'waiter', 'waitress', 'host', 'hostess', 'foh'],
        'cashier' => ['cashier', 'kasir'],
    ])->and(Divisi::OFFICE_WORDS)->toBe(['office', 'kantor'])
        ->and(Divisi::NONSHIFT)->toBe('nonshift')
        ->and(Divisi::TIM_BAWAAN_JADWAL)->toContain('hrd', 'bar', 'kasir', 'foh')
        ->and(Divisi::TIM_BOLEH_DW)->toBe(['hrd', 'hr', 'ceo'])
        ->and(Divisi::TIM_ADMIN_ROSTER)->toBe(['hrd', 'hr'])
        ->and(Divisi::MODUL_ADMIN_BAWAAN)->toBe(['jadwal', 'dw']);
});

it('resolves a User whose Tim is FOH to Divisi floor on the jadwal sheet (#3)', function () {
    // Talenta Organization "FOH" lands in the Tim column; before #3 this API
    // treated that crew as Nonshift — no sheet and no Akses Bawaan.
    app(AccountRepository::class)->updateUser('u-andry', ['keterangan' => 'FOH']);

    $rows = $this->withToken(loginAs(officeUser('u-arif')))->getJson('/api/v1/jadwal/roster')
        ->assertOk()->json('data');
    $byId = [];
    foreach ($rows as $r) {
        $byId[$r['id']] = $r['divisi'];
    }
    expect($byId['u-andry'])->toBe('floor');
});
