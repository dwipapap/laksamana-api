<?php

use App\Support\RowSync;

it('fingerprints rows independent of key order and stamps', function () {
    $a = ['id' => 'x', 'b' => ['z' => 1, 'y' => 2], 'updatedAt' => 1, 'baseUpdatedAt' => 5];
    $b = ['b' => ['y' => 2, 'z' => 1], 'id' => 'x', 'updatedAt' => 999];
    expect(RowSync::sidik($a))->toBe(RowSync::sidik($b));
});

it('keeps list order significant in the fingerprint', function () {
    expect(RowSync::sidik(['l' => [1, 2]]))->not->toBe(RowSync::sidik(['l' => [2, 1]]));
});

it('bumps only a legit edit whose stamp is not newer than the server', function () {
    expect(RowSync::capTulis(100, true, 100))->toBe(101)
        ->and(RowSync::capTulis(100, true, 99))->toBe(100)
        ->and(RowSync::capTulis(100, false, 200))->toBe(100)
        ->and(RowSync::capTulis(100, true, null))->toBe(100);
});

it('converts zoned instants to WIB but keeps zone-less wall clock as-is', function () {
    expect(RowSync::datetime('2026-09-24T03:00:00.000Z'))->toBe('2026-09-24 10:00:00')
        ->and(RowSync::datetime('2026-09-24T03:00'))->toBe('2026-09-24 03:00:00')
        ->and(RowSync::datetime(''))->toBeNull();
});

it('reads legacy stamps in every shape', function () {
    expect(RowSync::ms(1790100000000))->toBe(1790100000000)
        ->and(RowSync::ms('1790100000000'))->toBe(1790100000000)
        ->and(RowSync::ms(null))->toBe(0)
        ->and(RowSync::tanggal('2026-9-1'))->toBeNull();
});
