<?php

use App\Modules\Reservasi\Services\KwitansiPdf;

/* The DP kwitansi the WhatsApp bot attaches: a hand-written PDF that mirrors
 * the Office sheet (kop, table, total, signature block, footer). */

function kwiRow(): array
{
    return [
        'id' => 'rmv-test-1',
        'name' => 'Wandi Pranata',
        'phone' => '6282389882273',
        'date' => '2026-10-17',
        'time' => '20:00',
        'tables' => 'U1',
        'pax' => 3,
        'dpMethod' => 'QRIS',
        'dpStatus' => 'Sudah',
        'dps' => [['amount' => 100000, 'method' => 'QRIS'], ['amount' => 50000, 'method' => 'Transfer UOB']],
    ];
}

it('renders an Office-like kwitansi with the venue number when Finance has not issued', function () {
    $pdf = KwitansiPdf::render(kwiRow(), null, 1791700000);
    $content = KwitansiPdf::build(KwitansiPdf::data(kwiRow(), null), null, 1791700000);

    expect($pdf)->toStartWith('%PDF-1.4')->and($pdf)->toEndWith("%%EOF\n");
    expect($content['content'])
        ->toContain('KWITANSI')
        ->toContain('PT. LAKSAMANA MUDA BERSATU')
        ->toContain('KW/20261017/ST-1')
        ->toContain('150.000')
        ->toContain('DP DITERIMA')
        ->toContain('319-303-3588')
        ->toContain('Kota Pekanbaru, Riau 28282');
});

it('uses the official issued number and signature names when Finance approved', function () {
    $inv = ['no' => 'KW/2026/10/0007', 'cap' => '', 'penanda' => [['nama' => 'Cindy Juliarti', 'jabatan' => 'Finance', 'ttd' => '']]];
    $d = KwitansiPdf::data(kwiRow(), $inv);
    $content = KwitansiPdf::build($d, null, 1791700000);

    expect($d['resmi'])->toBeTrue()
        ->and($d['no'])->toBe('KW/2026/10/0007')
        ->and($content['content'])->toContain('No. Invoice')
        ->and($content['content'])->toContain('Cindy Juliarti')
        ->and($content['content'])->toContain('KW/2026/10/0007');
});

it('falls back to the legacy single-DP shape and names the file by id', function () {
    $row = ['id' => 'rmv x/2', 'name' => 'Tamu', 'dpStatus' => 'Sudah', 'dpAmount' => 200000, 'pax' => 2, 'tables' => ''];

    expect(KwitansiPdf::build(KwitansiPdf::data($row, null), null, 1791700000)['content'])->toContain('200.000')
        ->and(KwitansiPdf::fileName($row))->toBe('Kwitansi-rmvx2.pdf');
});
