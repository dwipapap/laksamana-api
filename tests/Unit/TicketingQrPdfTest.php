<?php

use App\Modules\Ticketing\Services\QrCode;
use App\Modules\Ticketing\Services\TicketPdf;

/*
 * Vectors recorded from the LEGACY lib_qr.php / lib_pdf.php (tests/Fixtures/ticketing).
 * The legacy PHP matrices equal the frontend JS qrMatrix() (laksamana-office tools/uji-qr.js),
 * so matching them keeps the PDF QR and the on-screen QR the same code.
 */
function tixVectors(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/ticketing/vectors.json'), true);
}

/** Inflate every FlateDecode stream (and drop the offset table) so PDFs compare independently of the zlib build. */
function tixInflated(string $pdf): string
{
    return preg_replace_callback('~<< /Length \d+ /Filter /FlateDecode >>\nstream\n(.*?)\nendstream~s',
        fn ($m) => "<< inflated >>\nstream\n".gzuncompress($m[1])."\nendstream", substr($pdf, 0, strrpos($pdf, "xref\n")));
}

it('draws the same QR matrix as legacy for every vector (versions 1..10)', function () {
    foreach (tixVectors()['qr'] as $text => $rows) {
        if (isset($rows['error'])) {
            expect(fn () => QrCode::matrix((string) $text))->toThrow(Exception::class, $rows['error']);

            continue;
        }
        expect(array_map(fn ($r) => implode('', $r), QrCode::matrix((string) $text)))->toBe($rows, "text: $text");
    }
});

it('writes the same e-ticket PDF as legacy', function () {
    $v = tixVectors();
    foreach ($v['pdf'] as $name => $c) {
        $want = file_get_contents(__DIR__."/../Fixtures/ticketing/$name.pdf");
        $got = TicketPdf::eticket($c['o'], $c['tickets'], $c['ev'], $c['max']);
        // same zlib as the recording: every byte; another zlib build: every byte of the page content
        if ($v['zlib'] === ZLIB_VERSION) {
            expect($got)->toBe($want, "case: $name");
        }
        expect(tixInflated($got))->toBe(tixInflated($want), "case: $name");
    }
});

it('refuses an empty ticket list and names the attachment like legacy', function () {
    expect(fn () => TicketPdf::eticket([], []))->toThrow(Exception::class, 'Tidak ada tiket untuk dicetak.');
    foreach (tixVectors()['fileNames'] as [$o, $name]) {
        expect(TicketPdf::fileName($o))->toBe($name);
    }
});
