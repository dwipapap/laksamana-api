<?php

use App\Core\Models\LogAktivitas;
use App\Core\Models\Notifikasi;
use App\Erp\Proyek\Models\PermintaanKoordinasi;
use App\Erp\Proyek\Models\Proyek;
use App\Erp\Proyek\Models\TugasTim;
use App\Erp\Proyek\Models\TugasTimPic;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Kerja Tim, log, notifications (docs/erp/kerja-tim.md): the rules the
 * database enforces on its own.
 */

function ktUser(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => 'Kru '.$id, 'pin' => '0000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function ktRow(string $table, array $row): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table($table)->insert(['id' => $id, 'created_at' => now(), 'updated_at' => now()] + $row);

    return $id;
}

it('gives a task several PICs once each, inside a project', function () {
    $proyek = Proyek::create(['nama' => 'Renovasi Bar']);
    $tugas = TugasTim::create(['judul' => 'Survey vendor', 'proyek_id' => $proyek->id, 'status' => 'doing', 'prioritas' => 'high', 'penting' => true, 'progres' => 40]);
    $a = ktUser();
    TugasTimPic::create(['tugas_tim_id' => $tugas->id, 'user_id' => $a]);
    TugasTimPic::create(['tugas_tim_id' => $tugas->id, 'user_id' => ktUser(), 'urutan' => 1]);

    expect(fn () => TugasTimPic::create(['tugas_tim_id' => $tugas->id, 'user_id' => $a]))->toThrow(QueryException::class)
        ->and(fn () => TugasTim::create(['judul' => 'X', 'status' => 'To Do']))->toThrow(QueryException::class)
        ->and(fn () => TugasTim::create(['judul' => 'X', 'progres' => 101]))->toThrow(QueryException::class)
        ->and(fn () => $proyek->forceDelete())->toThrow(QueryException::class);
});

it('refuses a coordination request to the same Divisi', function () {
    $bar = ktRow('divisi', ['kode' => 'uji_bar']);
    $kitchen = ktRow('divisi', ['kode' => 'uji_kitchen']);

    expect(PermintaanKoordinasi::create(['judul' => 'Es batu', 'dari_divisi_id' => $bar, 'ke_divisi_id' => $kitchen])->status)->toBe('diminta')
        ->and(fn () => PermintaanKoordinasi::create(['judul' => 'X', 'dari_divisi_id' => $bar, 'ke_divisi_id' => $bar]))->toThrow(QueryException::class);
});

it('keeps a log entry after its object is gone, and notifications with their recipient', function () {
    $modul = ktRow('modul', ['kunci' => 'uji_bd']);
    $tugas = TugasTim::create(['judul' => 'Sementara']);
    $log = LogAktivitas::create(['modul_id' => $modul, 'aksi' => 'tugas.dibuat', 'objek_jenis' => 'tugas_tim', 'objek_kunci' => $tugas->id, 'detail' => ['judul' => 'Sementara'], 'terjadi_at' => now()]);
    $penerima = ktUser();
    Notifikasi::create(['user_id' => $penerima, 'modul_id' => $modul, 'judul' => 'Tugas baru']);

    $tugas->forceDelete();
    DB::connection('core')->table('user')->where('id', $penerima)->delete();

    expect($log->fresh()->detail['judul'])->toBe('Sementara')
        ->and(Notifikasi::where('user_id', $penerima)->count())->toBe(0);
});
