<?php

use App\Modules\Absensi\Services\AbsensiService;
use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/*
 * Legacy /absensi/api/api.php (and /api/api.php on the subdomain) — the whole
 * PWA backend: masuk/konteks, absen, faces, antrean/putusAbsen, rekap,
 * locations and settings. Full old-vs-new parity lives in
 * tools/parity/cases/absensi.json.
 *
 * Harness rules (same as the Dw tests):
 * - ONE auth identity per test: the scoped Sesi guard caches its user for
 *   the whole test. Cross-identity setup goes through AbsensiService
 *   directly (never a second HTTP login).
 * - Session-gated calls go by POST with `sesi` in the body (exactly what the
 *   PWA sends for writes): the scoped Sesi guard captures its Request at
 *   construction, and a service resolved in test setup may predate the HTTP
 *   calls — body `sesi` is read off the current request, `?sesi=` is not.
 *   GET is used only for open actions and first-call reads. Every action
 *   answers POST as well as GET (legacy api.php reads POST bodies), and both
 *   URL paths plus GET query reads are covered by parity + the path tests.
 * - Tests may write: every connection is transaction-wrapped.
 *
 * Identities from the restored dump:
 *   u-admin       = superadmin (HR + admin of absensi)
 *   u-wandi       = superadmin with a registered face (USER:u-wandi)
 *   u-dwipa       = holds the absensi module, NOT admin (ordinary crew)
 *   u-yuzaalfarel = no absensi module at all
 */

function absPost(array $body, string $path = '/absensi/api/api.php'): array
{
    return test()->legacyPost($path, $body)->assertOk()->json();
}

function absGet(string $qs, string $path = '/absensi/api/api.php'): array
{
    return test()->get($path.$qs)->assertOk()->json();
}

function absSesi(string $uid): string
{
    return legacySesi(officeUser($uid));
}

function absSvc(): AbsensiService
{
    return app(AbsensiService::class);
}

/** Punch-friendly settings: no shift needed, face optional — tests set what they need. */
function absSetelan(array $over = []): array
{
    return absSvc()->saveSetting($over + [
        'toleransiTelat' => 5, 'awalMenit' => 60, 'akhirMenit' => 180,
        'lemburMinMenit' => 15, 'wajahAmbang' => 0.45,
        'wajahWajib' => false, 'tanpaShiftBoleh' => true, 'hr' => [],
    ], 'uji');
}

function absDescriptor(float $v = 0.5): array
{
    return array_fill(0, 128, $v);
}

/** WIB today / yesterday from the server clock (never the phone's). */
function absToday(): string
{
    return AbsensiService::tglWib((int) (microtime(true) * 1000));
}

function absYesterday(): string
{
    return gmdate('Y-m-d', time() + 7 * 3600 - 86400);
}

it('serves ping and stats openly on both paths', function () {
    foreach (['/absensi/api/api.php', '/api/api.php'] as $path) {
        $p = absGet('?action=ping', $path);
        expect($p['ok'])->toBeTrue()->and($p['data'])->toMatchArray(['pong' => true, 'backend' => 'laravel']);
        $s = absGet('?action=stats', $path);
        expect($s['ok'])->toBeTrue();
        expect($s['data'])->toHaveKeys(['lokasi', 'wajah', 'punch', 'antre']);
        expect($s['data']['punch'])->toBeGreaterThanOrEqual(5);
    }
});

it('rejects unknown actions with the legacy message', function () {
    $r = absPost(['action' => 'geledah']);
    expect($r['ok'])->toBeFalse()->and($r['error'])->toBe('action tidak dikenal: geledah');
});

it('defaults a bare GET to konteks on both paths, openly', function () {
    foreach (['/absensi/api/api.php', '/api/api.php'] as $path) {
        $r = absGet('', $path);
        expect($r['ok'])->toBeTrue();
        expect($r['data'])->toHaveKeys(['lokasi', 'setting', 'waktuServer'])
            ->and($r['data'])->not->toHaveKey('siapa');
        expect($r['data']['setting'])->toMatchArray(['toleransiTelat' => 5, 'wajahAmbang' => 0.45]);
    }
});

it('serves konteks for crew with identity, shift, today and face flag', function () {
    $r = absGet('?action=konteks&sesi='.absSesi('u-dwipa'));
    expect($r['ok'])->toBeTrue();
    expect($r['data']['siapa'])->toMatchArray(['id' => 'u-dwipa', 'boleh' => 1, 'admin' => 0, 'hr' => 0]);
    expect($r['data']['tgl'])->toBe(absToday());
    expect($r['data'])->toHaveKeys(['shift', 'hariIni', 'wajahTerdaftar']);
});

it('serves konteks for HR with hr=1 and the enrolled face flagged', function () {
    $r = absGet('?action=konteks&sesi='.absSesi('u-wandi'));
    expect($r['data']['siapa'])->toMatchArray(['id' => 'u-wandi', 'boleh' => 1, 'admin' => 1, 'hr' => 1]);
    expect($r['data']['wajahTerdaftar'])->toBe(1);
});

it('logs masuk in through the account backend', function () {
    $u = officeUser('u-dwipa');
    $r = absPost(['action' => 'masuk', 'nama' => $u['name'], 'pin' => $u['pin']]);
    expect($r['ok'])->toBeTrue();
    expect($r['data']['user']['id'])->toBe('u-dwipa');
    expect($r['data']['user']['token'])->not->toBe('');
    expect($r['data']['user']['modules'])->toContain('absensi');
});

it('refuses masuk with the legacy login messages', function () {
    $u = officeUser('u-dwipa');
    expect(absPost(['action' => 'masuk', 'nama' => $u['name'], 'pin' => 'salah'])['error'])->toBe('Nama atau PIN salah.');
    expect(absPost(['action' => 'masuk', 'nama' => '', 'pin' => ''])['error'])->toBe('Nama dan PIN wajib diisi.');
    $luar = officeUser('u-yuzaalfarel');
    expect(absPost(['action' => 'masuk', 'nama' => $luar['name'], 'pin' => $luar['pin']])['error'])
        ->toBe('Akun ini belum diberi akses modul Absensi. Minta admin membukanya di Office.');
});

it('rejects a punch attempt without a session', function () {
    expect(absPost(['action' => 'absen', 'arah' => 'MASUK'])['error'])->toBe('Sesi tidak dikenali. Masuk lagi ya.');
});

it('rejects a punch attempt without the module', function () {
    $r = absPost(['action' => 'absen', 'arah' => 'MASUK', 'sesi' => absSesi('u-yuzaalfarel')]);
    expect($r['error'])->toBe('Akun ini tidak punya akses modul Absensi.');
});

it('rejects a bad punch direction', function () {
    $r = absPost(['action' => 'absen', 'arah' => 'DATANG', 'sesi' => absSesi('u-dwipa')]);
    expect($r['error'])->toBe('Arah absen harus MASUK atau PULANG.');
});

it('records a valid MASUK, keeps the first, then closes the day with PULANG', function () {
    absSetelan();
    absSvc()->saveFace('USER', 'u-dwipa', 'Dwipa', absDescriptor(), '', 'uji');

    $masuk = absPost(['action' => 'absen', 'arah' => 'MASUK', 'descriptor' => absDescriptor(), 'sesi' => absSesi('u-dwipa')]);
    expect($masuk['ok'])->toBeTrue();
    expect($masuk['data']['duplikat'])->toBeFalse();
    expect($masuk['data']['punch'])->toMatchArray([
        'uid' => 'u-dwipa', 'arah' => 'MASUK', 'tgl' => absToday(),
        'status' => 'VALID', 'wajahOk' => 1,
    ]);
    expect($masuk['data']['wajahTerdaftar'])->toBeTrue();

    // tapping twice on a slow signal: kept, reported, not an error
    $lagi = absPost(['action' => 'absen', 'arah' => 'MASUK', 'descriptor' => absDescriptor(), 'sesi' => absSesi('u-dwipa')]);
    expect($lagi['data']['duplikat'])->toBeTrue();
    expect($lagi['data']['punch']['id'])->toBe($masuk['data']['punch']['id']);

    $pulang = absPost(['action' => 'absen', 'arah' => 'PULANG', 'descriptor' => absDescriptor(), 'sesi' => absSesi('u-dwipa')]);
    expect($pulang['data']['punch'])->toMatchArray(['arah' => 'PULANG', 'tgl' => absToday(), 'status' => 'VALID']);

    $rekap = absGet('?action=rekap&dari='.absToday().'&sampai='.absToday().'&sesi='.absSesi('u-dwipa'));
    $hari = collect($rekap['data']['hari'])->firstWhere('tgl', absToday());
    expect($hari['hitung']['lengkap'])->toBe(1)->and($hari['menunggu'])->toBe(0);
});

it('queues an out-of-area punch and demands its reason', function () {
    absSetelan();
    absSvc()->saveLocation(['nama' => 'Kantor', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => 1], 'uji');

    $tanpa = absPost(['action' => 'absen', 'arah' => 'MASUK', 'lat' => 0, 'lng' => 0, 'sesi' => absSesi('u-admin')]);
    expect($tanpa['error'])->toBe('Absen ini di luar ketentuan, jadi harus disertai alasan untuk diajukan.');

    $r = absPost(['action' => 'absen', 'arah' => 'MASUK', 'lat' => 0, 'lng' => 0,
        'alasan' => 'dinas luar', 'sesi' => absSesi('u-admin')]);
    expect($r['data']['punch'])->toMatchArray(['status' => 'MENUNGGU', 'sebab' => 'LUAR_AREA', 'alasan' => 'dinas luar']);
    expect($r['data']['punch']['dalamArea'])->toBe(0);

    $antre = absPost(['action' => 'antrean', 'sesi' => absSesi('u-admin')]);
    $baris = collect($antre['data'])->firstWhere('id', $r['data']['punch']['id']);
    expect($baris['sebab'])->toBe('LUAR_AREA')->and($baris)->toHaveKey('foto');

    $putus = absPost(['action' => 'putusAbsen', 'id' => $r['data']['punch']['id'], 'status' => 'VALID', 'sesi' => absSesi('u-admin')]);
    expect($putus['data'])->toBe(['berubah' => 1]);

    // already decided: the second HR is told, not silently believed
    $lagi = absPost(['action' => 'putusAbsen', 'id' => $r['data']['punch']['id'], 'status' => 'DITOLAK', 'sesi' => absSesi('u-admin')]);
    expect($lagi['data'])->toBe(['berubah' => 0]);
});

it('rejects unknown decisions', function () {
    $r = absPost(['action' => 'putusAbsen', 'id' => 'x', 'status' => 'MUNGKIN', 'sesi' => absSesi('u-admin')]);
    expect($r['error'])->toBe('Keputusan hanya boleh VALID atau DITOLAK.');
});

it('lets crew enrol their own face but nobody else’s', function () {
    $r = absPost(['action' => 'daftarWajah', 'tipe' => 'USER', 'id' => 'u-dwipa',
        'nama' => 'Dwipa', 'descriptor' => absDescriptor(), 'sesi' => absSesi('u-dwipa')]);
    expect($r['data'])->toBe(['tersimpan' => true]);

    $r = absPost(['action' => 'daftarWajah', 'tipe' => 'USER', 'id' => 'u-admin',
        'nama' => 'Admin', 'descriptor' => absDescriptor(), 'sesi' => absSesi('u-dwipa')]);
    expect($r['error'])->toBe('Hanya HR yang boleh mendaftarkan wajah orang lain.');
});

it('rejects broken face prints', function () {
    $r = absPost(['action' => 'daftarWajah', 'tipe' => 'USER', 'id' => 'u-dwipa',
        'descriptor' => [1.0, 2.0, 3.0], 'sesi' => absSesi('u-dwipa')]);
    expect($r['error'])->toBe('Sidik wajah tidak lengkap (harus 128 angka). Coba daftarkan ulang.');
});

it('lists faces without descriptors and deletes them, HR only', function () {
    absSvc()->saveFace('USER', 'u-andi', 'Andi', absDescriptor(0.7), '', 'uji');
    $sesi = absSesi('u-admin');

    $daftar = absPost(['action' => 'wajahDaftar', 'sesi' => $sesi]);
    $baris = collect($daftar['data'])->firstWhere('id', 'u-andi');
    expect($baris)->toMatchArray(['tipe' => 'USER', 'nama' => 'Andi', 'ada' => 1]);
    expect($baris)->not->toHaveKey('descriptor');

    expect(absPost(['action' => 'hapusWajah', 'tipe' => 'USER', 'id' => 'u-andi', 'sesi' => $sesi])['data'])
        ->toBe(['hapus' => 1]);
    expect(absPost(['action' => 'hapusWajah', 'tipe' => 'USER', 'id' => 'u-andi', 'sesi' => $sesi])['data'])
        ->toBe(['hapus' => 0]);
});

it('refuses crew face listing', function () {
    expect(absPost(['action' => 'wajahDaftar', 'sesi' => absSesi('u-dwipa')])['error'])
        ->toBe('Hanya HR/admin modul yang boleh melakukan ini.');
});

it('forces ordinary crew to their own recap', function () {
    absSetelan();
    absSvc()->recordPunch(['tipe' => 'USER', 'id' => 'u-dwipa', 'nama' => 'Dwipa',
        'arah' => 'MASUK', 'lat' => 0, 'lng' => 0, 'akurasi' => 0,
        'descriptor' => null, 'foto' => '', 'alasan' => 'uji paksa']);

    // asking for someone else's rows still answers only one's own
    $sesiDwipa = absSesi('u-dwipa');
    $r = absPost(['action' => 'rekap', 'dari' => absToday(), 'sampai' => absToday(),
        'user' => 'u-admin', 'tipe' => 'USER', 'sesi' => $sesiDwipa]);
    expect($r['ok'])->toBeTrue();
    foreach ($r['data']['hari'] as $h) {
        expect($h['uid'])->toBe('u-dwipa');
    }
    expect($r['data']['hari'])->not->toBe([]);
});

it('shows HR the crew member’s rows', function () {
    absSetelan();
    absSvc()->recordPunch(['tipe' => 'USER', 'id' => 'u-dwipa', 'nama' => 'Dwipa',
        'arah' => 'MASUK', 'lat' => 0, 'lng' => 0, 'akurasi' => 0,
        'descriptor' => null, 'foto' => '', 'alasan' => 'uji paksa']);

    $hr = absPost(['action' => 'rekap', 'dari' => absToday(), 'sampai' => absToday(),
        'user' => 'u-dwipa', 'tipe' => 'USER', 'sesi' => absSesi('u-admin')]);
    expect(collect($hr['data']['hari'])->pluck('uid')->all())->toContain('u-dwipa');
});

it('validates recap dates and normalises swapped ranges', function () {
    $sesi = absSesi('u-admin');
    expect(absGet('?action=rekap&sesi='.$sesi)['error'])->toBe('rekap butuh dari & sampai (YYYY-MM-DD)');
    $r = absGet('?action=rekap&dari='.absToday().'&sampai=2026-01-01&sesi='.$sesi);
    expect($r['data']['dari'])->toBe('2026-01-01')->and($r['data']['sampai'])->toBe(absToday());
});

it('validates locations and clamps the radius', function () {
    $sesi = absSesi('u-admin');
    $bad = absPost(['action' => 'simpanLokasi', 'row' => ['nama' => ''], 'sesi' => $sesi]);
    expect($bad['error'])->toBe('Nama lokasi wajib diisi.');

    $r = absPost(['action' => 'simpanLokasi',
        'row' => ['nama' => 'Pos', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 5, 'aktif' => 1], 'sesi' => $sesi]);
    $id = $r['data']['id'];
    expect($id)->not->toBe('');
    $row = Modules::db('absensi')->selectOne(absSql('SELECT * FROM `abs_lokasi` WHERE `id` = ?'), [$id]);
    expect((int) $row->radius_m)->toBe(30);

    $r = absPost(['action' => 'simpanLokasi',
        'row' => ['id' => $id, 'nama' => 'Pos', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 5000, 'aktif' => 1], 'sesi' => $sesi]);
    expect($r['data']['id'])->toBe($id);
    $row = Modules::db('absensi')->selectOne(absSql('SELECT * FROM `abs_lokasi` WHERE `id` = ?'), [$id]);
    expect((int) $row->radius_m)->toBe(2000);

    expect(absPost(['action' => 'hapusLokasi', 'id' => $id, 'sesi' => $sesi])['data'])->toBe(['hapus' => 1]);
});

it('refuses crew location writes', function () {
    expect(absPost(['action' => 'simpanLokasi', 'row' => ['nama' => 'X'], 'sesi' => absSesi('u-dwipa')])['error'])
        ->toBe('Hanya HR/admin modul yang boleh melakukan ini.');
});

it('round-trips the single settings blob', function () {
    $sesi = absSesi('u-admin');
    $r = absPost(['action' => 'simpanSetting', 'data' => ['toleransiTelat' => 9, 'hr' => ['u-rizkiarfan']], 'sesi' => $sesi]);
    expect($r['data'])->toMatchArray(['toleransiTelat' => 9, 'wajahAmbang' => 0.45, 'hr' => ['u-rizkiarfan']]);

    $k = absGet('?action=konteks&sesi='.$sesi);
    expect($k['data']['setting']['toleransiTelat'])->toBe(9);
});

it('dates a night-shift PULANG on the MASUK day', function () {
    absSetelan();
    absSvc()->saveFace('USER', 'u-dwipa', 'Dwipa', absDescriptor(), '', 'uji');
    $kemarin = absYesterday();
    $now = (int) (microtime(true) * 1000);
    Modules::db('absensi')->statement(
        absSql('INSERT INTO `abs_punch` (`id`,`subjek_tipe`,`subjek_id`,`nama`,`tgl`,`arah`,`waktu`,`jam`,'.
        '`status`,`sebab`,`dibuat_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?)'),
        ['abuji0001', 'USER', 'u-dwipa', 'Dwipa', $kemarin, 'MASUK', $now - 2 * 3600 * 1000, '22:05',
            'VALID', '', $now]
    );

    $r = absPost(['action' => 'absen', 'arah' => 'PULANG', 'descriptor' => absDescriptor(), 'sesi' => absSesi('u-dwipa')]);
    expect($r['data']['punch'])->toMatchArray(['arah' => 'PULANG', 'tgl' => $kemarin]);
});
