<?php

use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Master\Models\Talent;
use App\Erp\Tamu\Models\CheckinTiket;
use App\Erp\Tamu\Models\Event;
use App\Erp\Tamu\Models\JadwalTalent;
use App\Erp\Tamu\Models\KelasTiket;
use App\Erp\Tamu\Models\Kursi;
use App\Erp\Tamu\Models\KursiTahan;
use App\Erp\Tamu\Models\PembayaranTalent;
use App\Erp\Tamu\Models\PesananTiket;
use App\Erp\Tamu\Models\PesananTiketBaris;
use App\Erp\Tamu\Models\Tiket;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Event & Tiket (docs/erp/event-tiket.md): the rules the database enforces
 * on its own. Selling only Upcoming events, server-side prices and the
 * payment webhook are service rules.
 */

function evtFixture(): array
{
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $event = Event::create(['nama' => 'Halloween Night', 'status' => 'upcoming', 'lokasi_id' => $outlet->id, 'bertiket' => true,
        'mulai_at' => '2026-10-31 13:00:00', 'selesai_at' => '2026-10-31 19:00:00', 'rencana' => ['rundown' => [['jam' => '20:00', 'acara' => 'Opening']]]]);
    $vip = KelasTiket::create(['event_id' => $event->id, 'nama' => 'VIP Table', 'harga' => 1500000, 'kuota' => 10, 'bertempat' => true]);
    $meja = Kursi::create(['event_id' => $event->id, 'kelas_tiket_id' => $vip->id, 'kode' => 'T1', 'jenis' => 'table', 'kapasitas' => 6]);

    return compact('outlet', 'event', 'vip', 'meja');
}

function pesanan(array $f, array $over = []): PesananTiket
{
    return PesananTiket::create($over + [
        'nomor' => 'TIX-'.Str::random(6), 'event_id' => $f['event']->id, 'nama_pembeli' => 'Dina',
        'email' => 'dina@contoh.id', 'subtotal' => 1500000, 'biaya' => 5000, 'total' => 1505000,
    ]);
}

it('sells a seated ticket: hold, pay, issue, scan, correct', function () {
    $f = evtFixture();
    $order = pesanan($f);
    KursiTahan::create(['kursi_id' => $f['meja']->id, 'token' => 'hold-1', 'pesanan_tiket_id' => $order->id, 'berakhir_at' => now()->addMinutes(15)]);

    expect(fn () => KursiTahan::create(['kursi_id' => $f['meja']->id, 'token' => 'hold-2', 'berakhir_at' => now()]))->toThrow(QueryException::class)
        ->and(fn () => $order->update(['status_bayar' => 'paid']))->toThrow(QueryException::class);

    $order->update(['status_bayar' => 'paid', 'dibayar_at' => now(), 'xendit_ref' => 'inv_123']);
    $baris = PesananTiketBaris::create(['pesanan_tiket_id' => $order->id, 'kelas_tiket_id' => $f['vip']->id, 'qty' => 1, 'harga' => 1500000]);
    $tiket = Tiket::create(['nomor' => 'LM-0001', 'pesanan_tiket_baris_id' => $baris->id, 'kelas_tiket_id' => $f['vip']->id, 'kursi_id' => $f['meja']->id, 'qr_token' => Str::random(32)]);
    CheckinTiket::create(['tiket_id' => $tiket->id, 'dipindai_at' => now()]);
    CheckinTiket::create(['tiket_id' => $tiket->id, 'jenis' => 'koreksi', 'dipindai_at' => now(), 'alasan' => 'salah pindai']);

    expect($f['event']->fresh()->rencana['rundown'][0]['acara'])->toBe('Opening')
        ->and(fn () => $order->delete())->toThrow(QueryException::class)
        ->and(fn () => CheckinTiket::create(['tiket_id' => $tiket->id, 'jenis' => 'hapus', 'dipindai_at' => now()]))->toThrow(QueryException::class);
});

it('refuses order and event values that cannot be right', function (Closure $write) {
    expect(fn () => $write(evtFixture()))->toThrow(QueryException::class);
})->with([
    'total not subtotal + fee' => [fn (array $f) => pesanan($f, ['total' => 1])],
    'unknown payment state' => [fn (array $f) => pesanan($f, ['status_bayar' => 'kedaluwarsa'])],
    'event ends before it starts' => [fn (array $f) => Event::create(['nama' => 'X', 'mulai_at' => '2026-10-31 13:00:00', 'selesai_at' => '2026-10-31 12:00:00'])],
    'legacy event status' => [fn (array $f) => Event::create(['nama' => 'X', 'status' => 'Today'])],
    'decision without a time' => [fn (array $f) => $f['event']->update(['keputusan' => 'disetujui'])],
    'same seat code twice' => [fn (array $f) => Kursi::create(['event_id' => $f['event']->id, 'kode' => 'T1'])],
    'zero tickets' => [fn (array $f) => PesananTiketBaris::create(['pesanan_tiket_id' => pesanan($f)->id, 'kelas_tiket_id' => $f['vip']->id, 'qty' => 0, 'harga' => 1])],
]);

it('pays a talent once per month, for shows that may run past midnight', function () {
    $talent = Talent::create(['pihak_id' => Pihak::create(['nama' => 'DJ Uji'])->id, 'tarif_bawaan' => 750000]);
    $bayar = PembayaranTalent::create(['talent_id' => $talent->pihak_id, 'bulan' => '2026-09-01', 'jumlah_tampil' => 2, 'total' => 1500000]);
    JadwalTalent::create(['talent_id' => $talent->pihak_id, 'tanggal' => '2026-09-12', 'mulai' => '22:00:00', 'selesai' => '02:00:00', 'tarif' => 750000, 'status' => 'done', 'pembayaran_talent_id' => $bayar->id]);

    expect(fn () => PembayaranTalent::create(['talent_id' => $talent->pihak_id, 'bulan' => '2026-09-01', 'total' => 1]))->toThrow(QueryException::class)
        ->and(fn () => PembayaranTalent::create(['talent_id' => $talent->pihak_id, 'bulan' => '2026-10-15', 'total' => 1]))->toThrow(QueryException::class)
        ->and(fn () => $bayar->update(['status' => 'paid']))->toThrow(QueryException::class)
        ->and(fn () => $bayar->delete())->toThrow(QueryException::class);
});
