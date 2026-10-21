<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisJabatan;
use App\Modules\Hris\Models\HrisJabatanLevel;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Services\HrisDivisiService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisJabatanService;
use App\Modules\Hris\Services\HrisKaryawanService;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;

require_once __DIR__.'/helpers.php';

/*
 * E2 (ADR-0012): /hris/kelola — Karyawan, org chart, Divisi and Jabatan,
 * with the Jadwal and Office side effects. One HTTP identity per test;
 * other actors go through the services.
 */

function kelolaSiap(): void
{
    app(HrisImporKaryawanService::class)->impor('test');
}

function divisiKode(string $kode): HrisDivisi
{
    return HrisDivisi::query()->where('kode', $kode)->firstOrFail();
}

function jadwalOverride(string $uid): ?string
{
    $s = JsonDoc::toArray(app(JadwalService::class)->setting());

    return $s['divOverride'][$uid] ?? null;
}

function kelolaJabatan(string $nama = 'Uji Jabatan'): HrisJabatan
{
    return app(HrisJabatanService::class)->create(
        ['nama' => $nama, 'level_id' => HrisJabatanLevel::query()->where('urutan', 2)->value('id')], 'test');
}

it('adds an employee as one Office User plus Karyawan record', function () {
    kelolaSiap();
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];
    $jab = kelolaJabatan('Cook Uji');

    $d = test()->postJson('/api/v1/hris/kelola/karyawan', [
        'nama' => 'Karyawan Uji Satu', 'pin' => '4321', 'tim' => 'Kitchen', 'no_hp' => '0811',
        'divisi_id' => divisiKode('kitchen')->id, 'jabatan_id' => $jab->id,
        'status_kerja' => 'PROBATION', 'tanggal_gabung' => '2026-10-01', 'nik' => '3201234567890123',
    ], $h)->assertCreated()->json('data');

    expect($d['divisi']['kode'])->toBe('kitchen')->and($d['jabatan']['nama'])->toBe('Cook Uji')
        ->and($d['nik'])->toBe('3201234567890123')->and($d['peringatan'])->toBe([]);

    $u = app(AccountRepository::class)->userById($d['user_id']);
    expect($u['name'])->toBe('Karyawan Uji Satu')
        ->and($u['job_position'])->toBe('Cook Uji')
        ->and($u['employment_status'])->toBe('Probation')
        ->and($u['join_date'])->toBe('2026-10-01')
        ->and($u['organization'])->toBe('Kitchen')
        ->and(jadwalOverride($d['user_id']))->toBe('kitchen');
});

it('refuses an invalid NIK before creating the Office User', function () {
    kelolaSiap();
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];

    test()->postJson('/api/v1/hris/kelola/karyawan', ['nama' => 'Karyawan Uji Dua', 'nik' => '123'], $h)
        ->assertStatus(422);
    expect(collect(app(AccountRepository::class)->allUsers())->firstWhere('name', 'Karyawan Uji Dua'))->toBeNull();
});

it('records history, moves the Jadwal placement and refuses an atasan cycle', function () {
    kelolaSiap();
    $svc = app(HrisKaryawanService::class);
    $kantor = app(HrisDivisiService::class)->create(['nama' => 'Divisi Uji', 'jenis' => 'kantor'], 'test');

    $d = $svc->update('u-yuzaalfarel', ['divisi_id' => $kantor->id, 'berlaku_dari' => '2026-10-10'], 'HR Uji');
    expect($d['divisi']['nama'])->toBe('Divisi Uji')
        ->and(jadwalOverride('u-yuzaalfarel'))->toBe('nonshift')
        ->and($d['riwayat'][0])->toMatchArray(['kolom' => 'divisi', 'dari' => 'Bar', 'ke' => 'Divisi Uji',
            'berlaku_dari' => '2026-10-10', 'oleh' => 'HR Uji']);

    $svc->update('u-yuzaalfarel', ['atasan_user_id' => 'u-arif'], 'test');
    expect(fn () => $svc->update('u-arif', ['atasan_user_id' => 'u-yuzaalfarel'], 'test'))
        ->toThrow(HrisError::class, 'siklus');
});

it('refuses a stale write with 409', function () {
    kelolaSiap();
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];
    $v = HrisKaryawan::query()->where('user_id', 'u-adit')->value('version');

    test()->patchJson('/api/v1/hris/kelola/karyawan/u-adit', ['status_kerja' => 'TETAP', 'berubah' => $v + 5], $h)
        ->assertStatus(409);
});

it('needs subordinates handed over before an exit, then deactivates the User', function () {
    kelolaSiap();
    $svc = app(HrisKaryawanService::class);
    $svc->update('u-yuzaalfarel', ['atasan_user_id' => 'u-aryaputras'], 'test');

    expect(fn () => $svc->keluar('u-aryaputras', ['tanggal' => '2026-10-01', 'alasan' => 'RESIGN'], 'test'))
        ->toThrow(HrisError::class, 'bawahan');
    // A Kepala Divisi must hand the headship over too.
    expect(fn () => $svc->keluar('u-adit', ['tanggal' => '2026-10-01', 'alasan' => 'RESIGN'], 'test'))
        ->toThrow(HrisError::class, 'Kepala Divisi');

    $svc->update('u-yuzaalfarel', ['atasan_user_id' => ''], 'test');
    $d = $svc->keluar('u-aryaputras', ['tanggal' => '2026-10-01', 'alasan' => 'RESIGN'], 'test');
    expect($d['alasan_keluar'])->toBe('RESIGN')
        ->and((int) app(AccountRepository::class)->userById('u-aryaputras')['active'])->toBe(0);
});

it('lets hris:harian deactivate a future exit once its day comes', function () {
    kelolaSiap();
    $svc = app(HrisKaryawanService::class);
    $svc->keluar('u-aldialfayat', ['tanggal' => '2099-01-31', 'alasan' => 'KONTRAK_HABIS'], 'test');
    expect((int) app(AccountRepository::class)->userById('u-aldialfayat')['active'])->toBe(1)
        ->and($svc->prosesKeluar('2099-01-31', 'test'))->toBe(1)
        ->and((int) app(AccountRepository::class)->userById('u-aldialfayat')['active'])->toBe(0);
});

it('shows a Kepala Divisi only their Divisi, without personal data', function () {
    kelolaSiap();
    $h = ['Authorization' => 'Bearer '.hrisToken('u-arif')]; // head of bar

    $rows = test()->getJson('/api/v1/hris/kelola/karyawan', $h)->assertOk()->json('data');
    expect($rows)->not->toBe([])
        ->and(collect($rows)->pluck('divisi.kode')->unique()->values()->all())->toBe(['bar'])
        ->and($rows[0])->not->toHaveKey('nik');
    test()->getJson('/api/v1/hris/kelola/karyawan/u-adit', $h)->assertStatus(403);
});

it('keeps crew out of kelola', function () {
    kelolaSiap();
    $h = ['Authorization' => 'Bearer '.hrisToken('u-yuzaalfarel')];

    test()->getJson('/api/v1/hris/kelola/struktur', $h)->assertStatus(403);
});

it('builds the org chart from atasan, falling back to the Kepala Divisi', function () {
    kelolaSiap();
    $svc = app(HrisKaryawanService::class);
    $nodes = collect($svc->struktur())->keyBy('user_id');

    expect($nodes['u-yuzaalfarel']['atasan_user_id'])->toBe('u-arif')
        ->and($nodes['u-yuzaalfarel']['atasan_otomatis'])->toBeTrue()
        ->and($nodes['u-arif']['atasan_user_id'])->toBeNull();

    $sub = collect($svc->struktur('u-arif'))->pluck('user_id');
    expect($sub)->toContain('u-arif', 'u-yuzaalfarel')->not->toContain('u-adit');
});

it('manages Divisi: kantor heads, move members, delete only when empty', function () {
    kelolaSiap();
    $svc = app(HrisDivisiService::class);
    $a = $svc->create(['nama' => 'Divisi Asal', 'jenis' => 'kantor'], 'test');
    $b = $svc->create(['nama' => 'Divisi Tujuan', 'jenis' => 'kantor'], 'test');
    app(HrisKaryawanService::class)->update('u-mputraariza', ['divisi_id' => $a->id], 'test');

    expect($svc->setKepala($a->id, ['u-mputraariza'], 'test')['kepala'][0]['user_id'])->toBe('u-mputraariza')
        ->and(app(HrisKaryawanService::class)->isKepala('u-mputraariza'))->toBeTrue();
    expect(fn () => $svc->delete($a->id, 'test'))->toThrow(HrisError::class);

    $svc->setKepala($a->id, [], 'test');
    expect(app(HrisKaryawanService::class)->pindahkanDivisi($a->id, $b->id, '2026-10-20', 'test'))->toBe(1);
    $svc->delete($a->id, 'test');
    expect(HrisDivisi::query()->find($a->id))->toBeNull()
        ->and(HrisDivisi::withTrashed()->find($a->id)->kode)->toBe('divisi-asal');
});

it('writes shift Divisi heads to Jadwal', function () {
    kelolaSiap();
    app(HrisDivisiService::class)->setKepala(divisiKode('cleaning')->id, ['u-ayunita'], 'test');

    expect(JsonDoc::toArray(app(JadwalService::class)->setting())['heads']['cleaning'])->toBe(['u-ayunita']);
});

it('refuses to delete a level still used by a Jabatan', function () {
    kelolaSiap();
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];
    $lv = HrisJabatanLevel::query()->where('urutan', 5)->value('id');

    test()->deleteJson("/api/v1/hris/kelola/jabatan-level/{$lv}", [], $h)->assertStatus(409);
});

it('narrows HRIS Akses Bawaan to Karyawan once HRIS_HANYA_KARYAWAN is on', function () {
    kelolaSiap();
    $access = app(OfficeAccess::class);
    expect($access->builtinModules('u-admin'))->toContain('hris');

    config(['hris.hanya_karyawan' => true]);
    $access->forgetUser('u-admin');
    $access->forgetUser('u-adit');
    expect(HrisKaryawan::query()->where('user_id', 'u-admin')->exists())->toBeFalse()
        ->and(app(OfficeAccess::class)->builtinModules('u-admin'))->not->toContain('hris')
        ->and(app(OfficeAccess::class)->builtinModules('u-adit'))->toContain('hris');
});

it('exposes the legacy shiftKru as read-only shift_lama on karyawan detail', function () {
    kelolaSiap();
    hrisMakeHr('u-admin');
    $h = ['Authorization' => 'Bearer '.hrisToken('u-admin')];

    $jadwal = app(JadwalService::class);
    $data = JsonDoc::toArray($jadwal->setting());
    $shifts = is_array($data['shifts'] ?? null) ? $data['shifts'] : [];
    $shifts['LEGACYX'] = ['m' => '08:00', 's' => '17:00'];
    $data['shifts'] = $shifts;
    $kru = is_array($data['shiftKru'] ?? null) ? $data['shiftKru'] : [];
    $kru['u-adit'] = 'LEGACYX';
    $data['shiftKru'] = $kru;
    $jadwal->saveSetting($data, 'test');

    $d = test()->getJson('/api/v1/hris/kelola/karyawan/u-adit', $h)->assertOk()->json('data');
    expect($d['shift_lama'])->toBe('LEGACYX');
});
