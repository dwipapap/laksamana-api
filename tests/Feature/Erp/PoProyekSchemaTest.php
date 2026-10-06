<?php

use App\Erp\Master\Models\Lokasi;
use App\Erp\Master\Models\Pihak;
use App\Erp\Proyek\Models\PengajuanPembelian;
use App\Erp\Proyek\Models\PengajuanPembelianPenyetuju;
use App\Erp\Proyek\Models\PoProyek;
use App\Erp\Proyek\Models\Proyek;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Proyek & PO Proyek (docs/erp/po-proyek.md): the rules the database
 * enforces on its own.
 */

function poUser(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => 'Kru '.$id, 'pin' => '0000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function poFixture(): array
{
    $outlet = Lokasi::create(['kode' => 'outlet', 'nama' => 'Outlet', 'jenis' => 'outlet']);
    $proyek = Proyek::create(['nama' => 'Halloween Night', 'tahap' => 'planning', 'mulai' => '2026-10-01', 'selesai' => '2026-10-31', 'anggaran' => 25000000]);
    $pr = PengajuanPembelian::create(['nomor' => 'PR-41', 'tanggal' => '2026-10-05', 'minggu_mulai' => '2026-10-05']);

    return compact('outlet', 'proyek', 'pr');
}

function po(array $f, array $over = []): PoProyek
{
    return PoProyek::create($over + [
        'nomor' => 'PO-'.Str::random(6), 'lokasi_id' => $f['outlet']->id, 'proyek_id' => $f['proyek']->id,
        'pengajuan_pembelian_id' => $f['pr']->id, 'nama_barang' => 'Kain hitam 10 m', 'nominal' => 450000,
    ]);
}

it('keeps a PO line for a project inside a weekly PR, bought for less than asked', function () {
    $f = poFixture();
    $vendor = Pihak::create(['nama' => 'Toko Kain']);
    $po = po($f, ['vendor_id' => $vendor->id]);
    $po->update(['realisasi' => 420000, 'status' => 'diterima', 'diproses_at' => now()]);

    expect($po->fresh()->realisasi)->toBe('420000')
        // zero is a price, NULL is "not bought"
        ->and(po($f, ['realisasi' => 0])->realisasi)->toBe(0)
        ->and(fn () => $f['pr']->delete())->toThrow(QueryException::class)
        ->and(fn () => $f['proyek']->forceDelete())->toThrow(QueryException::class);
});

it('refuses values outside the agreed lifecycles', function (Closure $write) {
    expect(fn () => $write(poFixture()))->toThrow(QueryException::class);
})->with([
    'unknown PO status' => [fn (array $f) => po($f, ['status' => 'approved'])],
    'unknown source' => [fn (array $f) => po($f, ['sumber' => 'event'])],
    'negative spend' => [fn (array $f) => po($f, ['realisasi' => -1])],
    'zero quantity' => [fn (array $f) => po($f, ['qty' => 0])],
    'unknown stage' => [fn (array $f) => Proyek::create(['nama' => 'X', 'tahap' => 'done'])],
    'ends before it starts' => [fn (array $f) => Proyek::create(['nama' => 'X', 'mulai' => '2026-10-10', 'selesai' => '2026-10-01'])],
    'week not on Monday' => [fn (array $f) => PengajuanPembelian::create(['nomor' => 'PR-42', 'tanggal' => '2026-10-07', 'minggu_mulai' => '2026-10-07'])],
    'submitted without a time' => [fn (array $f) => $f['pr']->update(['status' => 'diajukan'])],
]);

it('lists each approver of a PR once, in order, and drops them with the PR', function () {
    ['pr' => $pr] = poFixture();
    $cfo = poUser();
    $ceo = poUser();
    PengajuanPembelianPenyetuju::create(['pengajuan_pembelian_id' => $pr->id, 'user_id' => $cfo, 'urutan' => 1]);
    PengajuanPembelianPenyetuju::create(['pengajuan_pembelian_id' => $pr->id, 'user_id' => $ceo, 'urutan' => 2, 'disetujui_at' => now()]);

    expect(fn () => PengajuanPembelianPenyetuju::create(['pengajuan_pembelian_id' => $pr->id, 'user_id' => $cfo, 'urutan' => 3]))
        ->toThrow(QueryException::class)
        ->and(fn () => PengajuanPembelianPenyetuju::create(['pengajuan_pembelian_id' => $pr->id, 'user_id' => poUser(), 'urutan' => 2]))
        ->toThrow(QueryException::class);

    $pr->delete();
    expect(PengajuanPembelianPenyetuju::where('pengajuan_pembelian_id', $pr->id)->count())->toBe(0);
});
