<?php

use App\Erp\Kas\Models\ArusKas;
use App\Erp\Kas\Models\Dompet;
use App\Erp\Kas\Models\Investor;
use App\Erp\Kas\Models\KasKecil;
use App\Erp\Kas\Models\KategoriKas;
use App\Erp\Kas\Models\MutasiDompet;
use App\Erp\Kas\Models\Pembayaran;
use App\Erp\Kas\Models\PengembalianModal;
use App\Erp\Kas\Models\RencanaBayar;
use App\Erp\Kas\Models\Setoran;
use App\Erp\Kas\Models\SetoranHari;
use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Kas (docs/erp/kas.md): the rules the database enforces on its own.
 * Balances are SUM(arus_kas), never stored.
 */

function kasFixture(): array
{
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $cash = Dompet::create(['kode' => 'cash', 'nama' => 'Cash / Brankas Fisik', 'jenis' => 'tunai', 'lokasi_id' => $outlet->id]);
    $bca = Dompet::create(['kode' => 'bca', 'nama' => 'BCA', 'jenis' => 'bank', 'lokasi_id' => $outlet->id, 'saldo_awal' => 5000000]);
    $pos = Dompet::create(['kode' => 'kk_1', 'nama' => 'Kas Kecil', 'jenis' => 'kas_kecil', 'lokasi_id' => $outlet->id]);

    return compact('outlet', 'cash', 'bca', 'pos');
}

function kasDok(array $f, array $over = []): array
{
    return $over + ['nomor' => 'K-'.Str::random(8), 'lokasi_id' => $f['outlet']->id, 'tanggal_bisnis' => '2026-10-05'];
}

function arus(array $f, array $over): ArusKas
{
    return ArusKas::create($over + ['dompet_id' => $f['bca']->id, 'arah' => 'keluar', 'nominal' => 1000, 'tanggal_bisnis' => '2026-10-05']);
}

it('splits one Kas Kecil transaction over pos and computes a balance from the book', function () {
    $f = kasFixture();
    $kat = KategoriKas::create(['nama' => 'COGS']);
    $kk = KasKecil::create(kasDok($f, ['keterangan' => 'Belanja pasar', 'kategori_kas_id' => $kat->id, 'ada_bon' => true]));
    arus($f, ['dompet_id' => $f['pos']->id, 'arah' => 'keluar', 'nominal' => 250000, 'sebab' => 'kas_kecil', 'kas_kecil_id' => $kk->id]);
    arus($f, ['dompet_id' => $f['pos']->id, 'arah' => 'masuk', 'nominal' => 1000000, 'sebab' => 'kas_kecil', 'kas_kecil_id' => $kk->id]);

    $saldo = (int) ArusKas::where('dompet_id', $f['pos']->id)
        ->selectRaw("SUM(CASE WHEN arah = 'masuk' THEN nominal ELSE -nominal END) s")->value('s');

    expect($saldo)->toBe(750000)
        ->and(fn () => arus($f, ['dompet_id' => $f['pos']->id, 'arah' => 'keluar', 'nominal' => 5, 'sebab' => 'kas_kecil', 'kas_kecil_id' => $kk->id]))
        ->toThrow(QueryException::class)
        // a used category cannot be hard-deleted
        ->and(fn () => $kat->forceDelete())->toThrow(QueryException::class);
});

it('writes a book row only with exactly one origin that matches its sebab', function () {
    $f = kasFixture();
    $kk = KasKecil::create(kasDok($f, ['keterangan' => 'x']));

    expect(fn () => arus($f, ['sebab' => 'kas_kecil']))->toThrow(QueryException::class)
        ->and(fn () => arus($f, ['sebab' => 'setoran', 'kas_kecil_id' => $kk->id]))->toThrow(QueryException::class)
        ->and(fn () => arus($f, ['sebab' => 'kas_kecil', 'kas_kecil_id' => $kk->id, 'nominal' => 0]))->toThrow(QueryException::class)
        ->and(fn () => arus($f, ['sebab' => 'kas_kecil', 'kas_kecil_id' => $kk->id, 'arah' => 'naik']))->toThrow(QueryException::class);
});

it('shapes a Mutasi Wallet by its kind', function (array $over, bool $ok) {
    $f = kasFixture();
    $base = kasDok($f, ['nominal' => 100000]);
    $over = array_map(fn ($v) => is_string($v) && isset($f[$v]) ? $f[$v]->id : $v, $over);
    $write = fn () => MutasiDompet::create($over + $base);

    $ok ? expect($write()->exists)->toBeTrue() : expect($write)->toThrow(QueryException::class);
})->with([
    'pindah between two' => [['jenis' => 'pindah', 'dari_dompet_id' => 'cash', 'ke_dompet_id' => 'bca'], true],
    'pindah to itself' => [['jenis' => 'pindah', 'dari_dompet_id' => 'bca', 'ke_dompet_id' => 'bca'], false],
    'pindah without target' => [['jenis' => 'pindah', 'dari_dompet_id' => 'bca'], false],
    'masuk to one' => [['jenis' => 'masuk', 'ke_dompet_id' => 'bca'], true],
    'masuk with a source' => [['jenis' => 'masuk', 'dari_dompet_id' => 'cash', 'ke_dompet_id' => 'bca'], false],
    'keluar from one' => [['jenis' => 'keluar', 'dari_dompet_id' => 'bca'], true],
    'zero' => [['jenis' => 'keluar', 'dari_dompet_id' => 'bca', 'nominal' => 0], false],
]);

it('records a setoran per day, each day once', function () {
    $f = kasFixture();
    $st = Setoran::create(kasDok($f, ['dari_dompet_id' => $f['cash']->id, 'ke_dompet_id' => $f['bca']->id, 'nominal' => 3000000]));
    SetoranHari::create(['setoran_id' => $st->id, 'tanggal_bisnis' => '2026-10-03', 'nominal' => 1000000]);
    SetoranHari::create(['setoran_id' => $st->id, 'tanggal_bisnis' => '2026-10-04', 'nominal' => 2000000]);
    arus($f, ['dompet_id' => $f['cash']->id, 'arah' => 'keluar', 'nominal' => 3000000, 'sebab' => 'setoran', 'setoran_id' => $st->id]);
    arus($f, ['dompet_id' => $f['bca']->id, 'arah' => 'masuk', 'nominal' => 3000000, 'sebab' => 'setoran', 'setoran_id' => $st->id]);

    expect(fn () => SetoranHari::create(['setoran_id' => $st->id, 'tanggal_bisnis' => '2026-10-03', 'nominal' => 5]))->toThrow(QueryException::class)
        ->and(fn () => Setoran::create(kasDok($f, ['dari_dompet_id' => $f['cash']->id, 'ke_dompet_id' => $f['cash']->id, 'nominal' => 1])))
        ->toThrow(QueryException::class);
});

it('keeps Planning Pembayaran honest: one sheet per date, paid means a time, paid money only goes out', function () {
    $f = kasFixture();
    $sheet = RencanaBayar::create(['lokasi_id' => $f['outlet']->id, 'tanggal_bayar' => '2026-10-07']);
    $vendor = Pihak::create(['nama' => 'Vendor Uji']);
    $p = Pembayaran::create(['rencana_bayar_id' => $sheet->id, 'keterangan' => 'Ayam minggu 40', 'pihak_id' => $vendor->id, 'dompet_id' => $f['bca']->id, 'nominal' => 4500000]);

    expect($p->fresh()->status)->toBe('dijadwalkan')
        ->and(fn () => RencanaBayar::create(['lokasi_id' => $f['outlet']->id, 'tanggal_bayar' => '2026-10-07']))->toThrow(QueryException::class)
        ->and(fn () => $p->update(['status' => 'dibayar']))->toThrow(QueryException::class);

    $p->update(['status' => 'dibayar', 'dibayar_at' => now()]);
    arus($f, ['sebab' => 'pembayaran', 'pembayaran_id' => $p->id, 'nominal' => 4500000]);

    expect(fn () => arus($f, ['sebab' => 'pembayaran', 'pembayaran_id' => $p->id]))->toThrow(QueryException::class)
        ->and(fn () => arus($f, ['sebab' => 'pembayaran', 'pembayaran_id' => Pembayaran::create([
            'rencana_bayar_id' => $sheet->id, 'keterangan' => 'Refund?', 'dompet_id' => $f['bca']->id, 'nominal' => 1,
        ])->id, 'arah' => 'masuk']))->toThrow(QueryException::class)
        // a document that moved money cannot be hard-deleted
        ->and(fn () => $sheet->delete())->toThrow(QueryException::class);
});

it('pays capital back to an investor from a Dompet, or reports it without one', function () {
    $f = kasFixture();
    $inv = Investor::create(['pihak_id' => Pihak::create(['nama' => 'Investor Uji'])->id, 'modal' => 500000000, 'kepemilikan' => 12.5]);
    $tanpa = PengembalianModal::create(kasDok($f, ['investor_id' => $inv->pihak_id, 'nominal' => 1000000]));
    $dari = PengembalianModal::create(kasDok($f, ['investor_id' => $inv->pihak_id, 'dompet_id' => $f['bca']->id, 'nominal' => 2000000]));
    arus($f, ['sebab' => 'pengembalian_modal', 'pengembalian_modal_id' => $dari->id, 'nominal' => 2000000]);

    expect($tanpa->dompet_id)->toBeNull()
        ->and(fn () => Investor::create(['pihak_id' => Pihak::create(['nama' => 'Lebih'])->id, 'kepemilikan' => 101]))->toThrow(QueryException::class);
});
