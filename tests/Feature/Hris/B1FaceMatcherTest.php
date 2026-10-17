<?php

use App\Modules\Hris\Services\FaceMatcher;

require_once __DIR__.'/helpers.php';

/*
 * FaceMatcher is pure: distances on fixed vectors, thresholds per model.
 */

it('measures euclidean and cosine distances', function () {
    expect(FaceMatcher::euclidean([0, 0], [3, 4]))->toBe(5.0)
        ->and(FaceMatcher::cosineDistance([1, 0], [1, 0]))->toBe(0.0);
    $lawan = FaceMatcher::cosineDistance(array_fill(0, 128, 0.5), array_fill(0, 128, -0.5));
    expect($lawan)->toBeGreaterThan(1.9);
});

it('matches the same face and rejects another', function () {
    $sama = array_fill(0, 128, 0.5);
    $mirip = array_map(fn ($v) => $v + 0.001, $sama);
    $lain = array_fill(0, 128, -0.5);
    $ambang = ['metric' => 'cosine', 'ambang' => 0.4];

    expect(FaceMatcher::match($sama, $sama, $ambang)['ok'])->toBeTrue()
        ->and(FaceMatcher::match($sama, $mirip, $ambang)['ok'])->toBeTrue()
        ->and(FaceMatcher::match($sama, $lain, $ambang)['ok'])->toBeFalse();
});

it('supports the euclidean metric per model', function () {
    $sama = array_fill(0, 64, 0.5);
    $ambang = ['metric' => 'euclidean', 'ambang' => 0.6];
    expect(FaceMatcher::match($sama, $sama, $ambang))->toMatchArray(['ok' => true, 'terdaftar' => true, 'metric' => 'euclidean']);
});

it('tells never-enrolled apart from a bad probe', function () {
    $ambang = ['metric' => 'cosine', 'ambang' => 0.4];
    expect(FaceMatcher::match(null, array_fill(0, 128, 0.5), $ambang)['terdaftar'])->toBeFalse();
    $m = FaceMatcher::match(array_fill(0, 128, 0.5), null, $ambang);
    expect($m)->toMatchArray(['ok' => false, 'terdaftar' => true]);
    // A short probe is not a match, even against itself.
    expect(FaceMatcher::match(array_fill(0, 128, 0.5), [0.5, 0.5], $ambang)['ok'])->toBeFalse();
});

it('reads the per-model threshold with a safe default', function () {
    $peta = ['sface-2021dec-int8' => ['metric' => 'cosine', 'ambang' => 0.35]];
    expect(FaceMatcher::ambangUntuk('sface-2021dec-int8', $peta))->toBe(['metric' => 'cosine', 'ambang' => 0.35])
        ->and(FaceMatcher::ambangUntuk('model-baru', $peta))->toBe(['metric' => 'cosine', 'ambang' => 0.637])
        ->and(FaceMatcher::ambangUntuk('model-baru', []))->toBe(['metric' => 'cosine', 'ambang' => 0.637]);
});
