<?php

use App\Modules\Reservasi\Services\HapusReservasiGate as G;

/*
 * #241: who may DELETE a reservation — the old CAN.deleteReservation, i.e. the
 * `inputDelete` row of master.perms (deploy/reservasi pagePerm). Pure, so it runs in CI.
 */

$perms = ['inputDelete' => ['host' => 1, 'marketing' => 1, 'cashier' => 0, 'manager' => 2, 'admin' => 2, 'viewer' => 0]];

it('follows the stored matrix row', function () use ($perms) {
    expect(G::allowed('manager', $perms))->toBeTrue()
        ->and(G::allowed('host', $perms))->toBeFalse()
        ->and(G::allowed('marketing', $perms))->toBeFalse()
        ->and(G::allowed('cashier', $perms))->toBeFalse();
});

it('lets a superadmin widen it for a role, but never for viewer', function () use ($perms) {
    $perms['inputDelete']['host'] = 2;
    $perms['inputDelete']['viewer'] = 2;
    expect(G::allowed('host', $perms))->toBeTrue()
        ->and(G::allowed('viewer', $perms))->toBeFalse();
});

it('always lets admin through, even when the row says no', function () {
    expect(G::allowed('admin', ['inputDelete' => ['admin' => 0]]))->toBeTrue();
});

it('denies an unknown person', function () use ($perms) {
    expect(G::allowed('', $perms))->toBeFalse();
});

it('falls back to view when the stored matrix lacks the row or the role, like pagePerm', function () {
    expect(G::allowed('manager', ['input' => ['manager' => 2]]))->toBeFalse()
        ->and(G::allowed('manager', ['inputDelete' => []]))->toBeFalse()
        ->and(G::allowed('manager', ['inputDelete' => ['manager' => '2']]))->toBeFalse();
});

it('uses the old defaults (manager, admin) only while master.perms does not exist', function () {
    expect(G::allowed('manager', null))->toBeTrue()
        ->and(G::allowed('host', null))->toBeFalse();
});
