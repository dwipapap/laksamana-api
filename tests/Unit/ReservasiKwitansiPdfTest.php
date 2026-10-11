<?php

use App\Modules\Reservasi\Services\KwitansiPdf;

/* The automatic DP receipt the WhatsApp bot attaches: a hand-written PDF. */

it('renders a one-page PDF with the reservation and DP lines, no phone', function () {
    $row = [
        'id' => 'rmv-test-1',
        'name' => 'Wandi Pranata',
        'phone' => '6282389882273',
        'date' => '2026-10-17',
        'time' => '20:00',
        'tables' => 'U1',
        'pax' => 3,
        'status' => 'Confirmed',
        'dpStatus' => 'Sudah',
        'dps' => [['amount' => 100000, 'method' => 'QRIS'], ['amount' => 50000, 'method' => 'Transfer UOB']],
    ];

    $pdf = KwitansiPdf::render($row, 1791700000);
    $content = KwitansiPdf::content($row, 1791700000);

    expect($pdf)->toStartWith('%PDF-1.4')
        ->and($pdf)->toEndWith("%%EOF\n");
    expect($content)->toContain('Bukti Pembayaran DP')
        ->and($content)->toContain('Wandi Pranata')
        ->and($content)->toContain('150.000')
        ->and($content)->not->toContain('6282389882273');
});

it('falls back to the legacy single-DP shape and names the file by id', function () {
    $row = ['id' => 'rmv x/2', 'name' => 'Tamu', 'dpStatus' => 'Sudah', 'dpAmount' => 200000, 'pax' => 2, 'tables' => ''];

    expect(KwitansiPdf::content($row, 1791700000))->toContain('200.000')
        ->and(KwitansiPdf::fileName($row))->toBe('Bukti-DP-rmvx2.pdf');
});
