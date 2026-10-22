<?php

use App\Modules\Hris\Http\V1\HrisBalanceController as B;
use App\Modules\Hris\Http\V1\HrisCalendarController as K;
use App\Modules\Hris\Http\V1\HrisCoordinationController as T;
use App\Modules\Hris\Http\V1\HrisDesignRequestController as G;
use App\Modules\Hris\Http\V1\HrisDisiplinController as D;
use App\Modules\Hris\Http\V1\HrisFaceController as F;
use App\Modules\Hris\Http\V1\HrisKelolaController as W;
use App\Modules\Hris\Http\V1\HrisKpiController as P;
use App\Modules\Hris\Http\V1\HrisMealController as E;
use App\Modules\Hris\Http\V1\HrisMeController as M;
use App\Modules\Hris\Http\V1\HrisOfficeController as O;
use App\Modules\Hris\Http\V1\HrisOnboardingController as J;
use App\Modules\Hris\Http\V1\HrisPelamarController as A;
use App\Modules\Hris\Http\V1\HrisPengumumanController as U;
use App\Modules\Hris\Http\V1\HrisPermintaanController as N;
use App\Modules\Hris\Http\V1\HrisProfilController as V;
use App\Modules\Hris\Http\V1\HrisQueueController as Q;
use App\Modules\Hris\Http\V1\HrisRequestController as R;
use App\Modules\Hris\Http\V1\HrisRosterController as Y;
use App\Modules\Hris\Http\V1\HrisScoreController as S;
use App\Modules\Hris\Http\V1\HrisTimController as C;
use Illuminate\Support\Facades\Route;

/*
 * HRIS (Talenta replacement, greenfield on `core`). Sanctum + module:hris;
 * Lingkup (sendiri/divisi/semua) is enforced per endpoint in the services.
 */
Route::middleware(['auth:sanctum', 'module:hris'])->prefix('hris')->group(function () {
    // Crew surface.
    Route::get('me/today', [M::class, 'today']);
    Route::get('pengumuman', [U::class, 'index']);
    Route::get('pengumuman/options', [U::class, 'options']);
    Route::post('pengumuman', [U::class, 'store']);
    Route::get('pengumuman/{id}', [U::class, 'show'])->where('id', '[A-Za-z0-9-]+');
    Route::patch('pengumuman/{id}', [U::class, 'update'])->where('id', '[A-Za-z0-9-]+');
    Route::post('pengumuman/{id}/publish', [U::class, 'publish'])->where('id', '[A-Za-z0-9-]+');
    Route::post('pengumuman/{id}/archive', [U::class, 'archive'])->where('id', '[A-Za-z0-9-]+');
    Route::put('pengumuman/grant', [U::class, 'grant']);
    Route::delete('pengumuman/grant/{userId}', [U::class, 'revoke'])->where('userId', '[A-Za-z0-9._-]+');
    Route::post('punches', [M::class, 'punch']);
    Route::get('punches', [M::class, 'punches']);
    Route::get('punches/{id}/photo', [M::class, 'punchPhoto'])->where('id', '[A-Za-z0-9-]+');
    Route::get('riwayat', [M::class, 'riwayat']);

    // Crew profile + contact change requests with HR approval (issue #344).
    Route::get('me/profile', [V::class, 'profile']);
    Route::get('me/profile-change-requests', [V::class, 'indexMine']);
    Route::post('me/profile-change-requests', [V::class, 'storeMine']);
    Route::get('me/profile-change-requests/{id}', [V::class, 'showMine'])->where('id', '[A-Za-z0-9]+');
    Route::post('me/profile-change-requests/{id}/cancel', [V::class, 'cancelMine'])->where('id', '[A-Za-z0-9]+');

    // Decision queue (HR + Kepala Divisi).
    Route::get('queue', [Q::class, 'queue']);
    Route::post('punches/{id}/decision', [Q::class, 'decide'])->where('id', '[A-Za-z0-9-]+');

    // Faces (A3, W2–W6).
    Route::post('faces/me', [F::class, 'enrolMe']);
    Route::post('faces/me/cek', [F::class, 'cekMe']);
    Route::post('faces/me/tambah', [F::class, 'tambahMe']);
    Route::get('faces', [F::class, 'index']);
    Route::get('faces/kalibrasi', [F::class, 'kalibrasi']);
    Route::get('faces/{userId}/cek', [F::class, 'cekLog'])->where('userId', '[A-Za-z0-9._-]+');
    Route::get('faces/{userId}/photo', [F::class, 'photo'])->where('userId', '[A-Za-z0-9._-]+');
    Route::delete('faces/{userId}', [F::class, 'reset'])->where('userId', '[A-Za-z0-9._-]+');

    // HR office: locations, settings, one-time import.
    Route::get('locations', [O::class, 'locations']);
    Route::get('pengguna', [O::class, 'pengguna']);
    Route::post('locations', [O::class, 'saveLocation']);
    Route::patch('locations/{id}', [O::class, 'patchLocation'])->where('id', '[A-Za-z0-9-]+');
    Route::delete('locations/{id}', [O::class, 'deleteLocation'])->where('id', '[A-Za-z0-9-]+');
    Route::get('settings', [O::class, 'settings']);
    Route::put('settings', [O::class, 'saveSettings']);
    Route::post('admin/import-locations', [O::class, 'importLocations']);

    // Leave, PH and requests (B2: L1–L9).
    Route::get('calendar', [K::class, 'index']);
    Route::post('calendar', [K::class, 'store']);
    Route::delete('calendar/{id}', [K::class, 'destroy'])->where('id', '[A-Za-z0-9-]+');
    Route::post('calendar/import', [K::class, 'import']);

    Route::get('balances/me', [B::class, 'mine']);
    Route::get('balances', [B::class, 'index']);
    Route::get('balances/mutations', [B::class, 'mutations']);
    Route::post('balances/adjust', [B::class, 'adjust']);

    Route::get('requests', [R::class, 'index']);
    Route::post('requests', [R::class, 'store']);
    Route::get('requests/{id}', [R::class, 'show'])->where('id', '[A-Za-z0-9-]+');
    Route::get('requests/{id}/lampiran', [R::class, 'lampiran'])->where('id', '[A-Za-z0-9-]+');
    Route::delete('requests/{id}', [R::class, 'destroy'])->where('id', '[A-Za-z0-9-]+');
    Route::post('requests/{id}/decision', [R::class, 'decide'])->where('id', '[A-Za-z0-9-]+');

    // Meal allowance, discipline, team KPI, People Score (B3: P1–P2, S1–S5, K1).
    Route::get('meal/tarifs', [E::class, 'tarifs']);
    Route::post('meal/tarifs', [E::class, 'saveTarif']);
    Route::delete('meal/tarifs/{id}', [E::class, 'deleteTarif'])->where('id', '[A-Za-z0-9-]+');
    Route::get('meal/preview', [E::class, 'preview']);
    Route::post('meal/lock', [E::class, 'lock']);
    Route::get('meal', [E::class, 'rekap']);
    Route::get('meal/export', [E::class, 'ekspor']);

    Route::get('pelanggaran', [D::class, 'index']);
    Route::post('pelanggaran', [D::class, 'store']);
    Route::post('pelanggaran/{id}/batal', [D::class, 'batal'])->where('id', '[A-Za-z0-9-]+');

    Route::get('kpi', [P::class, 'show']);
    Route::post('kpi/templates', [P::class, 'saveTemplate']);
    Route::post('kpi/realisasi', [P::class, 'saveRealisasi']);

    Route::get('skor', [S::class, 'index']);
    Route::post('skor/hitung', [S::class, 'hitung']);
    Route::post('skor/kunci', [S::class, 'kunci']);
    Route::get('skor/formula', [S::class, 'formula']);
    Route::post('skor/formula', [S::class, 'saveFormula']);
    Route::post('penilaian', [S::class, 'nilai']);

    // Recruitment (B5: R1–R5).
    Route::get('permintaan', [N::class, 'index']);
    Route::post('permintaan', [N::class, 'store']);
    Route::post('permintaan/{id}/decision', [N::class, 'decide'])->where('id', '[A-Za-z0-9-]+');
    Route::delete('permintaan/{id}', [N::class, 'destroy'])->where('id', '[A-Za-z0-9-]+');

    Route::get('lowongan', [A::class, 'lowongan']);
    Route::post('lowongan/{id}/tutup', [A::class, 'tutupLowongan'])->where('id', '[A-Za-z0-9-]+');

    Route::get('pelamar', [A::class, 'index']);
    Route::post('pelamar', [A::class, 'store']);
    Route::get('pelamar/{id}', [A::class, 'show'])->where('id', '[A-Za-z0-9-]+');
    Route::post('pelamar/{id}/tahap', [A::class, 'tahap'])->where('id', '[A-Za-z0-9-]+');
    Route::post('pelamar/{id}/screen', [A::class, 'screen'])->where('id', '[A-Za-z0-9-]+');
    Route::get('pelamar/{id}/screening', [A::class, 'riwayat'])->where('id', '[A-Za-z0-9-]+');
    Route::delete('pelamar/{id}', [A::class, 'destroy'])->where('id', '[A-Za-z0-9-]+');

    // Coordination tickets via BD + read-only design requests (B6: R6),
    // onboarding checklist (B6: O1).
    Route::get('coordination', [T::class, 'index']);
    Route::post('coordination', [T::class, 'store']);
    Route::get('coordination/{id}', [T::class, 'show'])->where('id', '[A-Za-z0-9._-]+');
    Route::patch('coordination/{id}', [T::class, 'update'])->where('id', '[A-Za-z0-9._-]+');

    Route::get('design-requests', [G::class, 'index']);
    Route::get('design-requests/{id}', [G::class, 'show'])->where('id', '[A-Za-z0-9._-]+');

    Route::get('onboarding/templates', [J::class, 'templates']);
    Route::post('onboarding/templates', [J::class, 'saveTemplate']);
    Route::get('onboarding', [J::class, 'index']);
    Route::post('onboarding/start', [J::class, 'start']);
    Route::post('onboarding/{id}/langkah/{kunci}', [J::class, 'langkah'])
        ->where('id', '[A-Za-z0-9-]+')->where('kunci', '[A-Za-z0-9._-]+');
    // Karyawan, org chart, Divisi and Jabatan (E1–E9, ADR-0012).
    $uid = '[A-Za-z0-9._-]+';
    $ulid = '[A-Za-z0-9]+';
    Route::get('kelola/karyawan', [W::class, 'index']);
    Route::post('kelola/karyawan', [W::class, 'store']);
    Route::get('kelola/karyawan-calon', [W::class, 'calon']);
    Route::get('kelola/karyawan/{userId}', [W::class, 'show'])->where('userId', $uid);
    Route::patch('kelola/karyawan/{userId}', [W::class, 'update'])->where('userId', $uid);
    Route::post('kelola/karyawan/{userId}/jadikan', [W::class, 'jadikan'])->where('userId', $uid);
    Route::post('kelola/karyawan/{userId}/keluar', [W::class, 'keluar'])->where('userId', $uid);
    Route::post('kelola/karyawan/{userId}/batal-keluar', [W::class, 'batalKeluar'])->where('userId', $uid);
    Route::get('kelola/struktur', [W::class, 'struktur']);

    // Profile change approvals (issue #344): Admin Modul HRIS only.
    Route::get('kelola/perubahan-profil', [V::class, 'indexKelola']);
    Route::get('kelola/perubahan-profil/{id}', [V::class, 'showKelola'])->where('id', '[A-Za-z0-9]+');
    Route::post('kelola/perubahan-profil/{id}/decision', [V::class, 'decideKelola'])->where('id', '[A-Za-z0-9]+');

    Route::get('kelola/divisi', [W::class, 'divisiIndex']);
    Route::post('kelola/divisi', [W::class, 'divisiStore']);
    Route::patch('kelola/divisi/{id}', [W::class, 'divisiUpdate'])->where('id', $ulid);
    Route::delete('kelola/divisi/{id}', [W::class, 'divisiDestroy'])->where('id', $ulid);
    Route::put('kelola/divisi/{id}/kepala', [W::class, 'divisiKepala'])->where('id', $ulid);
    Route::post('kelola/divisi/{id}/pindahkan', [W::class, 'divisiPindahkan'])->where('id', $ulid);

    Route::get('kelola/jabatan', [W::class, 'jabatanIndex']);
    Route::post('kelola/jabatan', [W::class, 'jabatanStore']);
    Route::patch('kelola/jabatan/{id}', [W::class, 'jabatanUpdate'])->where('id', $ulid);
    Route::delete('kelola/jabatan/{id}', [W::class, 'jabatanDestroy'])->where('id', $ulid);
    Route::post('kelola/jabatan-level', [W::class, 'levelStore']);
    Route::patch('kelola/jabatan-level/{id}', [W::class, 'levelUpdate'])->where('id', $ulid);
    Route::delete('kelola/jabatan-level/{id}', [W::class, 'levelDestroy'])->where('id', $ulid);

    // Master shift (J1–J4), stored in the Jadwal setting.
    Route::get('kelola/shift', [W::class, 'shiftIndex']);
    Route::post('kelola/shift', [W::class, 'shiftStore']);
    Route::patch('kelola/shift/{kode}', [W::class, 'shiftUpdate'])->where('kode', '[A-Za-z0-9_-]+');
    Route::delete('kelola/shift/{kode}', [W::class, 'shiftDestroy'])->where('kode', '[A-Za-z0-9_-]+');

    // Roster history for HR (issue #346): append-only cell events plus the
    // shift changes waiting for the HR fallback decider.
    Route::get('kelola/roster/riwayat', [Y::class, 'riwayat']);
    Route::get('kelola/ubah-shift/{id}', [C::class, 'showUbahKelola'])->where('id', '[A-Za-z0-9]+');

    // Roster (J6–J7): planned in HRIS, stored by Jadwal.
    Route::get('roster', [Y::class, 'index']);
    Route::put('roster', [Y::class, 'simpan']);
    Route::get('roster/rekap', [Y::class, 'rekap']);
    Route::get('roster/aturan', [Y::class, 'aturan']);
    Route::put('roster/aturan', [Y::class, 'simpanAturan']);
    Route::get('me/jadwal', [Y::class, 'saya']);

    // Crew team roster, shift changes and head approvals (issue #346).
    Route::get('tim/divisi', [C::class, 'divisi']);
    Route::get('tim/roster', [C::class, 'roster']);
    Route::get('tim/ubah-shift', [C::class, 'indexUbah']);
    Route::post('tim/ubah-shift', [C::class, 'storeUbah']);
    Route::get('tim/ubah-shift/{id}', [C::class, 'showUbah'])->where('id', '[A-Za-z0-9]+');
    Route::get('tim/persetujuan/ubah-shift/{id}', [C::class, 'showUbahPersetujuan'])->where('id', '[A-Za-z0-9]+');
    Route::post('tim/ubah-shift/{id}/batal', [C::class, 'batalUbah'])->where('id', '[A-Za-z0-9]+');
    Route::get('tim/persetujuan', [C::class, 'persetujuan']);
    Route::post('tim/ubah-shift/{id}/putuskan', [C::class, 'putuskanUbah'])->where('id', '[A-Za-z0-9]+');
});
