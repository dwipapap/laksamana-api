<?php

use App\Erp\Kas\Models\MetodeBayar;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Tamu\Models\DaftarTunggu;
use App\Erp\Tamu\Models\Meja;
use App\Erp\Tamu\Models\Reservasi;
use App\Erp\Tamu\Models\ReservasiDp;
use App\Erp\Tamu\Models\ReservasiKedatangan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Reservasi (docs/erp/reservasi.md): the rules the database enforces on
 * its own. Table clashes depend on time and are checked by the service.
 */

function rsvFixture(): array
{
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $a1 = Meja::create(['lokasi_id' => $outlet->id, 'kode' => 'A1', 'kapasitas' => 4, 'zona' => 'green', 'tata_letak' => ['x' => 10, 'y' => 20, 'w' => 90, 'h' => 80]]);
    $qris = MetodeBayar::create(['kode' => 'qris_bri', 'nama' => 'QRIS BRI']);

    return compact('outlet', 'a1', 'qris');
}

function rsv(array $f, array $over = []): Reservasi
{
    return Reservasi::create($over + [
        'nomor' => 'RSV-'.Str::random(6), 'lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-10-10',
        'jam' => '19:30:00', 'nama_tamu' => 'Rina', 'telepon' => '6281200001111', 'pax' => 6, 'meja_id' => $f['a1']->id,
    ]);
}

it('stores a booking with staged arrivals and a DP paid in instalments', function () {
    $f = rsvFixture();
    $r = rsv($f);
    ReservasiKedatangan::create(['reservasi_id' => $r->id, 'datang_at' => '2026-10-10 12:35:00', 'pax' => 4]);
    ReservasiKedatangan::create(['reservasi_id' => $r->id, 'datang_at' => '2026-10-10 13:10:00', 'pax' => 2]);
    ReservasiDp::create(['reservasi_id' => $r->id, 'urutan' => 1, 'nominal' => 500000, 'metode_bayar_id' => $f['qris']->id]);
    ReservasiDp::create(['reservasi_id' => $r->id, 'urutan' => 2, 'nominal' => 0, 'bukti_key' => 'rc_ab12.jpg', 'verifikasi' => 'ditolak', 'diverifikasi_at' => now()]);

    expect($r->fresh()->status)->toBe('pending')
        ->and($f['a1']->fresh()->tata_letak['w'])->toBe(90)
        ->and(fn () => ReservasiDp::create(['reservasi_id' => $r->id, 'urutan' => 1, 'nominal' => 1]))->toThrow(QueryException::class);

    $r->delete();
    expect(ReservasiDp::where('reservasi_id', $r->id)->count())->toBe(0)
        ->and(ReservasiKedatangan::where('reservasi_id', $r->id)->count())->toBe(0);
});

it('refuses values outside the agreed lists', function (Closure $write) {
    expect(fn () => $write(rsvFixture()))->toThrow(QueryException::class);
})->with([
    'legacy status spelling' => [fn (array $f) => rsv($f, ['status' => 'Checked-in'])],
    'no guests' => [fn (array $f) => rsv($f, ['pax' => 0])],
    'verified without a time' => [fn (array $f) => ReservasiDp::create(['reservasi_id' => rsv($f)->id, 'urutan' => 1, 'verifikasi' => 'terverifikasi'])],
    'unknown verification' => [fn (array $f) => ReservasiDp::create(['reservasi_id' => rsv($f)->id, 'urutan' => 1, 'verifikasi' => 'pending', 'diverifikasi_at' => now()])],
    'same table code twice' => [fn (array $f) => Meja::create(['lokasi_id' => $f['outlet']->id, 'kode' => 'A1', 'kapasitas' => 2])],
    'table without seats' => [fn (array $f) => Meja::create(['lokasi_id' => $f['outlet']->id, 'kode' => 'B1', 'kapasitas' => 0])],
]);

it('seats a waiting party only as a reservation, once', function () {
    $f = rsvFixture();
    $w = DaftarTunggu::create(['lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-10-10', 'nama_tamu' => 'Antre', 'pax' => 3, 'masuk_at' => now()]);

    expect(fn () => $w->update(['status' => 'duduk']))->toThrow(QueryException::class);

    $r = rsv($f, ['status' => 'datang', 'pax' => 3]);
    $w->update(['status' => 'duduk', 'duduk_at' => now(), 'reservasi_id' => $r->id]);

    expect(fn () => DaftarTunggu::create(['lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-10-10', 'nama_tamu' => 'Lain', 'pax' => 2, 'masuk_at' => now(), 'status' => 'duduk', 'reservasi_id' => $r->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $r->delete())->toThrow(QueryException::class);
});
