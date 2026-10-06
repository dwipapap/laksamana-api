<?php

use App\Erp\Master\Models\PekerjaHarian;
use App\Erp\Master\Models\Pihak;
use App\Erp\Sdm\Models\JadwalKru;
use App\Erp\Sdm\Models\KetukanAbsen;
use App\Erp\Sdm\Models\LokasiAbsen;
use App\Erp\Sdm\Models\PembayaranDw;
use App\Erp\Sdm\Models\PengajuanJadwal;
use App\Erp\Sdm\Models\PengaturanDw;
use App\Erp\Sdm\Models\PenugasanDw;
use App\Erp\Sdm\Models\PermintaanDw;
use App\Erp\Sdm\Models\Shift;
use App\Erp\Sdm\Models\WajahTerdaftar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * SDM (docs/erp/sdm.md): the rules the database enforces on its own.
 */

function sdmUser(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => 'Kru '.$id, 'pin' => '0000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function sdmDivisi(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('divisi')->insert(['id' => $id, 'kode' => 'uji_'.substr($id, -6), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function sdmDw(string $hp = '0812999000'): PekerjaHarian
{
    return PekerjaHarian::create(['pihak_id' => Pihak::create(['nama' => 'DW '.$hp])->id, 'no_hp' => $hp]);
}

it('keeps one shift per crew per date, including shifts past midnight', function () {
    $malam = Shift::create(['kode' => 'M', 'nama' => 'Malam', 'jam_mulai' => '18:00:00', 'jam_selesai' => '03:00:00']);
    $kru = sdmUser();
    JadwalKru::create(['user_id' => $kru, 'tanggal' => '2026-10-10', 'shift_id' => $malam->id]);

    expect(fn () => JadwalKru::create(['user_id' => $kru, 'tanggal' => '2026-10-10', 'shift_impor' => 'X']))->toThrow(QueryException::class)
        ->and(fn () => $malam->forceDelete())->toThrow(QueryException::class);
});

it('runs a schedule request through its agreed states only', function () {
    $req = PengajuanJadwal::create(['user_id' => sdmUser(), 'jenis' => 'cuti', 'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-22']);
    $req->update(['status' => 'menunggu_hrd', 'head_disetujui_at' => now()]);

    expect(fn () => $req->update(['status' => 'disetujui']))->toThrow(QueryException::class)
        ->and(fn () => PengajuanJadwal::create(['user_id' => sdmUser(), 'jenis' => 'SAKIT', 'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-20']))->toThrow(QueryException::class)
        ->and(fn () => PengajuanJadwal::create(['user_id' => sdmUser(), 'jenis' => 'off', 'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-19']))->toThrow(QueryException::class);

    $req->update(['status' => 'disetujui', 'diputuskan_at' => now()]);
    expect($req->fresh()->status)->toBe('disetujui');
});

it('punches exactly one subject once per direction per day', function () {
    $kru = sdmUser();
    $dw = sdmDw();
    $titik = LokasiAbsen::create(['nama' => 'Outlet', 'lat' => 0.5071, 'lng' => 101.4478, 'radius_m' => 120]);
    $punch = ['tanggal_bisnis' => '2026-10-10', 'arah' => 'masuk', 'waktu' => now(), 'lokasi_absen_id' => $titik->id, 'dalam_area' => true, 'wajah_skor' => 0.62];

    KetukanAbsen::create($punch + ['user_id' => $kru]);
    KetukanAbsen::create($punch + ['pekerja_harian_id' => $dw->pihak_id, 'shift_sumber' => 'dw']);
    KetukanAbsen::create(['arah' => 'pulang'] + $punch + ['user_id' => $kru]);

    expect(fn () => KetukanAbsen::create($punch + ['user_id' => $kru]))->toThrow(QueryException::class)
        ->and(fn () => KetukanAbsen::create($punch + ['user_id' => sdmUser(), 'pekerja_harian_id' => $dw->pihak_id]))->toThrow(QueryException::class)
        ->and(fn () => KetukanAbsen::create($punch))->toThrow(QueryException::class)
        ->and(fn () => KetukanAbsen::create(['wajah_skor' => 1.5] + $punch + ['user_id' => sdmUser()]))->toThrow(QueryException::class)
        ->and(fn () => WajahTerdaftar::create(['descriptor' => [0.1, 0.2], 'didaftar_at' => now()]))->toThrow(QueryException::class)
        ->and(fn () => LokasiAbsen::create(['nama' => 'X', 'lat' => 91, 'lng' => 0]))->toThrow(QueryException::class);
});

it('books daily workers with the wage copied, attendance and a weekly transfer', function () {
    $dw = sdmDw();
    PengaturanDw::create(['berlaku_dari' => '2000-01-01']);
    $minta = PermintaanDw::create(['divisi_id' => sdmDivisi(), 'tanggal_bisnis' => '2026-10-10', 'jumlah' => 2]);
    $bayar = PembayaranDw::create(['minggu_mulai' => '2026-10-05', 'nomor_tujuan' => '12345678', 'nominal' => 170000]);
    $tugas = PenugasanDw::create([
        'pekerja_harian_id' => $dw->pihak_id, 'permintaan_dw_id' => $minta->id, 'tanggal_bisnis' => '2026-10-10',
        'jam_mulai' => '16:00:00', 'jam_selesai' => '02:00:00', 'upah_dasar' => 150000, 'tambahan' => 20000,
        'status' => 'disetujui', 'kehadiran' => 'telat', 'pembayaran_dw_id' => $bayar->id,
    ]);

    expect(fn () => $tugas->update(['kehadiran' => 'izin']))->toThrow(QueryException::class)
        ->and(fn () => PembayaranDw::create(['minggu_mulai' => '2026-10-05', 'nomor_tujuan' => '12345678', 'nominal' => 1]))->toThrow(QueryException::class)
        ->and(fn () => PembayaranDw::create(['minggu_mulai' => '2026-10-07', 'nomor_tujuan' => '999', 'nominal' => 1]))->toThrow(QueryException::class)
        ->and(fn () => PengaturanDw::create(['berlaku_dari' => '2026-10-01', 'jam_dasar' => 8, 'jam_batas' => 6]))->toThrow(QueryException::class)
        ->and(fn () => PermintaanDw::create(['divisi_id' => sdmDivisi(), 'tanggal_bisnis' => '2026-10-10', 'jumlah' => 0]))->toThrow(QueryException::class);

    // deleting a request only releases its assignments
    $minta->delete();
    expect($tugas->fresh()->permintaan_dw_id)->toBeNull();
});
