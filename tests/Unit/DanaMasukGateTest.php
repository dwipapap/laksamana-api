<?php

use App\Modules\Reservasi\Services\DanaMasukGate as G;

/*
 * G-14: what a cashier/finance holder without the reservasi module may write
 * through PATCH /reservasi/reservations/{id} — exactly what the Dana Masuk
 * screen writes (DanaMasukPanel.vue: dps + cerminDp + cerminTfUtama, and the
 * one Pending -> Confirmed flip when money comes in). Pure, so it runs in CI.
 */

$row = ['id' => 'r1', 'name' => 'Tamu', 'status' => 'Pending', 'table' => 'R1', 'pax' => 4, 'dps' => []];

it('lets the DP instalments and every transfer mirror through', function () use ($row) {
    $body = [
        'dps' => [['id' => 'p1', 'amount' => 100000]],
        'dpStatus' => 'Sudah', 'dpMethod' => 'QRIS', 'dpAmount' => 100000, 'dpProofData' => '', 'dpProofName' => '',
        'tfDate' => '2026-09-30', 'tfTime' => '19:30', 'tfBank' => 'BRI', 'tfName' => 'X', 'tfAmount' => 100000,
        'tfStatus' => 'verified', 'tfNote' => '', 'tfSource' => 'manual', 'tfBy' => 'Kasir', 'tfAt' => 1,
        'tfOcrAt' => 0, 'tfOcrText' => '', 'tfOcrSaran' => [], 'tfTanpaData' => true,
    ];
    expect(G::rejectedFields($body, $row))->toBe([]);
});

it('refuses every other field that changes', function () use ($row) {
    expect(G::rejectedFields(['name' => 'Lain', 'table' => 'R2', 'pax' => 4, 'log' => []], $row))
        ->toBe(['name', 'table', 'log']);
});

it('accepts a field sent back unchanged, the id, and the server-set stamps', function () use ($row) {
    expect(G::rejectedFields(['id' => 'r1', 'name' => 'Tamu', 'pax' => 4, 'updatedAt' => 5, 'createdAt' => 1], $row))->toBe([]);
});

it('allows only the Pending -> Confirmed status flip', function () use ($row) {
    expect(G::rejectedFields(['status' => 'Confirmed'], $row))->toBe([])
        ->and(G::rejectedFields(['status' => 'Cancelled'], $row))->toBe(['status'])
        ->and(G::rejectedFields(['status' => 'Confirmed'], ['status' => 'Datang'] + $row))->toBe(['status']);
});

it('reads a tf field only as tf + an upper-case letter', function () {
    expect(G::isDpField('tfStatus'))->toBeTrue()
        ->and(G::isDpField('tf'))->toBeFalse()
        ->and(G::isDpField('tfoo'))->toBeFalse()
        ->and(G::isDpField('dpAmount'))->toBeTrue()
        ->and(G::isDpField('dpX'))->toBeFalse();
});
