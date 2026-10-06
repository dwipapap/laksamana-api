<?php

use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Master\Models\Klien;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Tamu\Models\Acara;
use App\Erp\Tamu\Models\AcaraPembayaran;
use App\Erp\Tamu\Models\AcaraPersetujuan;
use App\Erp\Tamu\Models\AcaraRincian;
use App\Erp\Tamu\Models\AcaraTugas;
use App\Erp\Tamu\Models\TindakLanjutKlien;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Acara Marketing (docs/erp/acara-marketing.md): the rules the database
 * enforces on its own.
 */

function acaraFixture(): array
{
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $klien = Klien::create(['pihak_id' => Pihak::create(['nama' => 'PT Contoh'])->id, 'perusahaan' => 'PT Contoh']);
    $acara = Acara::create(['nomor' => 'ACR-'.Str::random(6), 'klien_id' => $klien->pihak_id, 'lokasi_id' => $outlet->id,
        'nama' => 'Gathering Akhir Tahun', 'tanggal' => '2026-12-12', 'pax' => 80, 'budget_per_pax' => 250000,
        'status' => 'quotation', 'brief' => ['area' => 'Rooftop', 'highlight' => 'live band']]);

    return compact('outlet', 'klien', 'acara');
}

it('quotes, collects a DP and keeps paid bookings from vanishing', function () {
    $f = acaraFixture();
    AcaraRincian::create(['acara_id' => $f['acara']->id, 'urutan' => 1, 'deskripsi' => 'Paket konsumsi', 'jumlah' => 80, 'harga' => 250000]);
    AcaraRincian::create(['acara_id' => $f['acara']->id, 'urutan' => 2, 'deskripsi' => 'Welcome drink', 'jenis' => 'gratis']);
    AcaraPembayaran::create(['acara_id' => $f['acara']->id, 'nomor_kwitansi' => 'KW-0001', 'nominal' => 5000000, 'tanggal' => '2026-10-07',
        'metode_bayar_id' => MetodeBayar::create(['kode' => 'transfer_uob', 'nama' => 'Transfer UOB'])->id, 'terverifikasi' => true]);
    AcaraTugas::create(['acara_id' => $f['acara']->id, 'tugas' => 'Konfirmasi menu', 'tenggat' => '2026-12-05']);

    expect($f['acara']->fresh()->brief['area'])->toBe('Rooftop')
        ->and(fn () => AcaraPembayaran::create(['acara_id' => $f['acara']->id, 'nomor_kwitansi' => 'KW-0001', 'nominal' => 1, 'tanggal' => '2026-10-08']))
        ->toThrow(QueryException::class)
        ->and(fn () => $f['acara']->forceDelete())->toThrow(QueryException::class);
});

it('refuses values outside the pipeline and quotation rules', function (Closure $write) {
    expect(fn () => $write(acaraFixture()))->toThrow(QueryException::class);
})->with([
    'legacy stage spelling' => [fn (array $f) => $f['acara']->update(['status' => 'Quotation Terkirim'])],
    'ends before it starts' => [fn (array $f) => $f['acara']->update(['tanggal_selesai' => '2026-12-01'])],
    'free line with a price' => [fn (array $f) => AcaraRincian::create(['acara_id' => $f['acara']->id, 'urutan' => 1, 'deskripsi' => 'X', 'jenis' => 'gratis', 'harga' => 1000])],
    'same line number twice' => [function (array $f) {
        AcaraRincian::create(['acara_id' => $f['acara']->id, 'urutan' => 1, 'deskripsi' => 'A']);
        AcaraRincian::create(['acara_id' => $f['acara']->id, 'urutan' => 1, 'deskripsi' => 'B']);
    }],
    'zero payment' => [fn (array $f) => AcaraPembayaran::create(['acara_id' => $f['acara']->id, 'nomor_kwitansi' => 'KW-X', 'nominal' => 0, 'tanggal' => '2026-10-07'])],
    'decided without a time' => [fn (array $f) => AcaraPersetujuan::create(['acara_id' => $f['acara']->id, 'status' => 'disetujui'])],
    'unknown task state' => [fn (array $f) => AcaraTugas::create(['acara_id' => $f['acara']->id, 'tugas' => 'X', 'status' => 'Done'])],
    'follow-up about nothing' => [fn (array $f) => TindakLanjutKlien::create(['dicatat_at' => now(), 'catatan' => 'x'])],
]);
