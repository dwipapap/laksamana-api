<?php

use App\Support\Tim;

/*
 * Tim: the Beranda teams read from the Tim column. Whole-word, several teams
 * per User, stable order. No DB — runs in CI.
 */

it('matches whole words only, case-insensitive', function () {
    expect(Tim::keys('Kitchen'))->toBe(['kitchen'])
        ->and(Tim::keys('HEAD BAR'))->toBe(['bar'])
        ->and(Tim::keys('Barang'))->toBe([])
        ->and(Tim::keys('Hrdx'))->toBe([]);
});

it('merges several teams in TIM order', function () {
    expect(Tim::keys('Marketing / Kitchen'))->toBe(['kitchen', 'marketing'])
        ->and(Tim::keys('Kasir Office'))->toBe(['cashier']);
});

it('answers empty for an empty or unknown Tim column', function () {
    expect(Tim::keys(null))->toBe([])
        ->and(Tim::keys(''))->toBe([])
        ->and(Tim::keys('Security'))->toBe([]);
});

it('labels each team', function () {
    expect(Tim::of('HRD'))->toBe([['key' => 'hr', 'label' => 'HR']]);
});
