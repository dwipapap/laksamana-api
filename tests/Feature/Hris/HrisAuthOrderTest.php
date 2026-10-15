<?php

require_once __DIR__.'/helpers.php';

/*
 * Auth before validation: HR-only endpoints answer 403 (not 422) when a
 * crew member without HR rights sends an invalid body. One HTTP identity
 * per test.
 */

function hrisCrewToken(): string
{
    return hrisToken('u-dwipa');
}

it('answers 403 for crew posting an invalid location', function () {
    $t = hrisCrewToken();

    $r = test()->postJson('/api/v1/hris/locations', [],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->json('error');

    expect($r['code'])->toBe('forbidden');
});

it('answers 403 for crew posting an invalid balance adjustment', function () {
    $t = hrisCrewToken();

    $r = test()->postJson('/api/v1/hris/balances/adjust', [],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->json('error');

    expect($r['code'])->toBe('forbidden');
});

it('answers 403 for crew posting an invalid calendar entry', function () {
    $t = hrisCrewToken();

    $r = test()->postJson('/api/v1/hris/calendar', [],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->json('error');

    expect($r['code'])->toBe('forbidden');
});

it('answers 403 for crew posting an invalid meal tarif', function () {
    $t = hrisCrewToken();

    $r = test()->postJson('/api/v1/hris/meal/tarifs', [],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->json('error');

    expect($r['code'])->toBe('forbidden');
});

it('answers 403 for crew posting an invalid applicant', function () {
    $t = hrisCrewToken();

    $r = test()->postJson('/api/v1/hris/pelamar', [],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->json('error');

    expect($r['code'])->toBe('forbidden');
});

it('still answers 422 for HR posting an invalid location', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');

    test()->postJson('/api/v1/hris/locations', [],
        ['Authorization' => 'Bearer '.$t])->assertStatus(422);
});
