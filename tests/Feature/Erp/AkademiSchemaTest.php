<?php

use App\Erp\Akademi\Models\Materi;
use App\Erp\Akademi\Models\ProgramBelajar;
use App\Erp\Akademi\Models\ProgramMateri;
use App\Erp\Akademi\Models\ProgresMateri;
use App\Erp\Akademi\Models\ProgresProgram;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Akademi (docs/erp/akademi.md): the rules the database enforces on its own.
 */

function akdUser(): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('user')->insert([
        'id' => $id, 'legacy_id' => 'uji-'.$id, 'nama' => 'Kru '.$id, 'pin' => '0000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function akdFixture(): array
{
    $sop = Materi::create(['judul' => 'SOP Bar', 'wajib' => true, 'terbit' => true, 'nilai_lulus' => 80,
        'langkah' => [['type' => 'video', 'url' => 'https://contoh.id/v'], ['type' => 'quiz', 'soal' => [['q' => '1+1?', 'a' => '2']]]]]);
    $okt = ProgramBelajar::create(['judul' => 'Oktober', 'bulan' => '2026-10-01', 'tenggat' => '2026-10-31']);
    $pm = ProgramMateri::create(['program_belajar_id' => $okt->id, 'materi_id' => $sop->id, 'urutan' => 1]);

    return compact('sop', 'okt', 'pm');
}

it('keeps a learner\'s progress even when the material is retired', function () {
    $f = akdFixture();
    $kru = akdUser();
    ProgresMateri::create(['user_id' => $kru, 'materi_id' => $f['sop']->id, 'percobaan' => 2, 'nilai' => 85, 'lulus' => true, 'selesai_at' => now()]);
    ProgresProgram::create(['user_id' => $kru, 'program_materi_id' => $f['pm']->id, 'selesai' => true, 'nilai' => 85]);

    $f['sop']->delete(); // soft delete: retired, history kept

    expect(ProgresMateri::where('materi_id', $f['sop']->id)->count())->toBe(1)
        ->and($f['sop']->fresh()->langkah[1]['soal'][0]['a'])->toBe('2')
        ->and(fn () => $f['sop']->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => ProgresMateri::create(['user_id' => $kru, 'materi_id' => $f['sop']->id]))->toThrow(QueryException::class);
});

it('refuses learning values that cannot be right', function (Closure $write) {
    expect(fn () => $write(akdFixture()))->toThrow(QueryException::class);
})->with([
    'passing above 100' => [fn (array $f) => Materi::create(['judul' => 'X', 'nilai_lulus' => 120])],
    'passed without a finish time' => [fn (array $f) => ProgresMateri::create(['user_id' => akdUser(), 'materi_id' => $f['sop']->id, 'lulus' => true])],
    'month not on day 1' => [fn (array $f) => ProgramBelajar::create(['judul' => 'X', 'bulan' => '2026-11-15'])],
    'deadline before month' => [fn (array $f) => ProgramBelajar::create(['judul' => 'X', 'bulan' => '2026-11-01', 'tenggat' => '2026-10-20'])],
    'same material twice in a programme' => [fn (array $f) => ProgramMateri::create(['program_belajar_id' => $f['okt']->id, 'materi_id' => $f['sop']->id, 'urutan' => 2])],
]);
