<?php

use App\Modules\Bd\Services\BdState;
use App\Modules\Event\Services\EventState;
use App\Modules\Marketing\Services\MarketingState;

/*
 * api#307: the narrow Radar readers return exactly what the full state blobs
 * hold (same rows in the same order, same counts), so the board output built
 * from them cannot drift from the old full reads.
 */

it('reads the same marketing agenda inputs narrowly as the full state', function () {
    $mkt = app(MarketingState::class);
    $state = $mkt->read();
    $from = '2026-01-01';
    $to = '2026-12-31';
    $dalam = fn (array $r): bool => ($r['tanggal'] ?? '') >= $from && ($r['tanggal'] ?? '') <= $to;

    expect($mkt->eventsForRange($from, $to))
        ->toBe(array_values(array_filter($state['events'], $dalam)))
        ->and($mkt->vipForRange($from, $to))
        ->toBe(array_values(array_filter($state['vip'], $dalam)))
        ->and($mkt->countEvents())->toBe(count($state['events']));
});

it('reads the same event agenda inputs narrowly as the full state', function () {
    $ev = app(EventState::class);
    $state = $ev->read();
    $from = '2026-01-01';
    $to = '2026-12-31';
    $masuk = fn (array $e): bool => ($s = (string) ($e['start_datetime'] ?? '')) === ''
        || (substr($s, 0, 10) >= $from && substr($s, 0, 10) <= $to);

    expect($ev->eventsForRange($from, $to))
        ->toBe(array_values(array_filter($state['events'], $masuk)))
        ->and($ev->countEvents())->toBe(count($state['events']));
});

it('reads only the promos setting for the promo board', function () {
    $bd = app(BdState::class);

    expect($bd->setting('promos', []))->toBe($bd->read()['promos']);
});
