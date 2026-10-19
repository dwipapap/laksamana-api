<?php

use App\Modules\Hris\Models\HrisAudit;
use App\Modules\Hris\Models\HrisWajah;
use App\Modules\Hris\Services\BuktiWajah;
use App\Modules\Hris\Services\FaceMatcher;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * Bukti wajah bertanda tangan (S3): VPS menandatangani descriptor 128-float
 * hasil inferensi on-device; endpoint punch/enrol/cek/tambah memakai
 * descriptor dari bukti yang valid dan mengabaikan field klien. Tanpa secret
 * (HRIS_FACE_SECRET kosong) setiap bukti ditolak dan flag wajib dianggap
 * false (perilaku lama). Satu identitas HTTP per test.
 */

function buktiVektorToken(): string
{
    // Token literal dari /tmp/vektor-bukti.json (secret rahasia-uji-vektor-32-byte-abcdef,
    // kid uji-2026-10, iat 1760918400, fotoSha256 = sha256('foto-uji-bukti-wajah')).
    return 'eyJ2IjoxLCJraWQiOiJ1amktMjAyNi0xMCIsIm1vZGVsIjoic2ZhY2UtMjAyMWRlYy1pbnQ4IiwiZGVzY3JpcHRvciI6Wy0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wLDAuMTI1LDAuMjUsLTAuMjUsLTAuMTI1LDAuMCwwLjEyNSwwLjI1LC0wLjI1LC0wLjEyNSwwLjAsMC4xMjUsMC4yNSwtMC4yNSwtMC4xMjUsMC4wXSwiZm90b1NoYTI1NiI6IjEzNGJmNThhZGJlNGJjODJiODEzNmQwMjljYmNhMjU5MGVkNzVmMDU3N2FjYzk1ZTI4MzE4NGRjM2Q0YWY0MDYiLCJpYXQiOjE3NjA5MTg0MDB9.Ts68jOidhhL3h2q0-p5QS3UC5dJZr1Oa6B1SMQ6irIU';
}

/** Descriptor 128-float dari payload vektor uji bersama. */
function buktiVektorDescriptor(): array
{
    return [-0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0, 0.125, 0.25, -0.25, -0.125, 0.0];
}

function buktiUrlAmankan(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Cetak token format S3 dengan kunci, kid, iat dan fotoSha256 pilihan. */
function buktiMint(array $descriptor, string $model, string $secret, string $kid, ?int $iat = null, ?string $fotoSha256 = null): string
{
    $payload = ['v' => 1, 'kid' => $kid, 'model' => $model, 'descriptor' => array_values($descriptor),
        'fotoSha256' => $fotoSha256 ?? str_repeat('0', 64), 'iat' => $iat ?? time()];
    $json = (string) json_encode($payload);

    return buktiUrlAmankan($json).'.'.buktiUrlAmankan(hash_hmac('sha256', $json, $secret, true));
}

/** Pasang kunci bukti lewat config (nol per test, tidak menyentuh .env). */
function buktiKonfig(string $secret, string $kid, bool $wajib = false, ?string $secretLama = null, ?string $kidLama = null): void
{
    config()->set('hris.face_secret', $secret);
    config()->set('hris.face_kid', $kid);
    config()->set('hris.face_bukti_wajib', $wajib);
    config()->set('hris.face_secret_lama', $secretLama ?? '');
    config()->set('hris.face_kid_lama', $kidLama ?? '');
}

/** Kode error HrisError dari pemanggilan yang pasti melempar. */
function buktiKode(callable $fn): string
{
    try {
        $fn();
    } catch (HrisError $e) {
        return $e->errorCode;
    }

    return 'tidak_melempar';
}

it('menerima vektor uji bersama', function () {
    buktiKonfig('rahasia-uji-vektor-32-byte-abcdef', 'uji-2026-10');

    expect(BuktiWajah::verifikasi(buktiVektorToken(), 'sface-2021dec-int8', null, 1760918400))
        ->toBe(buktiVektorDescriptor());

    // fotoSha256 vektor = sha256('foto-uji-bukti-wajah'): foto yang cocok lolos.
    expect(BuktiWajah::verifikasi(buktiVektorToken(), 'sface-2021dec-int8', base64_encode('foto-uji-bukti-wajah'), 1760918400))
        ->toBe(buktiVektorDescriptor());

    // Foto lain: ikatan foto gagal.
    expect(buktiKode(fn () => BuktiWajah::verifikasi(buktiVektorToken(), 'sface-2021dec-int8', base64_encode('foto-lain'), 1760918400)))
        ->toBe('bukti_wajah_tidak_valid');
});

it('memakai descriptor bukti walau descriptor klien beda', function () {
    hrisSettings();
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $bukti = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $t = hrisToken('u-dwipa');

    // Descriptor klien milik orang lain, tetapi bukti cocok dengan yang tersimpan.
    $r = test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $bukti],
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($r)->toBe(['terdaftar' => true, 'cocok' => true]);

    $audit = HrisAudit::query()->where('aksi', 'wajah_dicek')->where('ref_id', 'u-dwipa')->first();
    expect($audit->data)->toMatchArray(['cocok' => true, 'sumber_wajah' => 'server']);
});

it('menolak bukti bertanda salah', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $bukti = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    [$payload, $sig] = explode('.', $bukti);
    $rusak = $payload.'.'.($sig[0] === 'A' ? 'B' : 'A').substr($sig, 1);
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $rusak],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');
});

it('menolak kid tak dikenal', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $asing = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'kid-asing');
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $asing],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');
});

it('menolak bukti kedaluwarsa dan dari masa depan yang jauh', function () {
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $desc = hrisWajahSeed('u-dwipa');

    expect(buktiKode(fn () => BuktiWajah::verifikasi(
        buktiMint($desc, 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http', time() - 300),
        'sface-2021dec-int8')))->toBe('bukti_wajah_tidak_valid');

    expect(buktiKode(fn () => BuktiWajah::verifikasi(
        buktiMint($desc, 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http', time() + 60),
        'sface-2021dec-int8')))->toBe('bukti_wajah_tidak_valid');

    // Toleransi jam: 10 detik dari masa depan masih lolos.
    expect(BuktiWajah::verifikasi(
        buktiMint($desc, 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http', time() + 10),
        'sface-2021dec-int8'))->toBe($desc);
});

it('menolak fotoSha256 yang beda dengan foto terkirim', function () {
    hrisSettings();
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $bukti = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8',
        'rahasia-uji-http-32-byte-xxxxxx', 'uji-http', null, str_repeat('f', 64));
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8',
            'consent' => true, 'foto' => hrisFoto(), 'buktiWajah' => [$bukti]],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');
    expect(app(HrisAttendanceService::class)->faceEnrolled('u-dwipa'))->toBeFalse();
});

it('menolak bukti yang modelnya beda dengan field model', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $bukti = buktiMint(hrisWajahSeed('u-dwipa'), 'model-lain', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $bukti],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');
});

it('menerima bukti berkunci lama', function () {
    hrisSettings();
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    buktiKonfig('rahasia-baru-32-byte-xxxxxxxxxx', 'baru-2026-10', false, 'rahasia-lama-32-byte-xxxxxxxxxx', 'lama-2026-09');
    $bukti = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8', 'rahasia-lama-32-byte-xxxxxxxxxx', 'lama-2026-09');
    $t = hrisToken('u-dwipa');

    $r = test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $bukti],
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($r)->toBe(['terdaftar' => true, 'cocok' => true]);
});

it('tanpa bukti dan flag mati berperilaku lama', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    buktiKonfig('', '', false);
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');

    $r = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK'] + hrisWajahPunch('u-dwipa'),
        ['Authorization' => 'Bearer '.$t])->assertCreated()->json();
    expect($r['data']['punch'])->toMatchArray(['arah' => 'MASUK', 'status' => 'VALID']);
});

it('flag wajib tanpa bukti: punch MENUNGGU, enrol cek tambah 422', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http', true);
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');

    test()->postJson('/api/v1/hris/faces/me/tambah',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');

    $r = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK', 'descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'alasan' => 'uji wajib'], $h)
        ->assertCreated()->json();
    expect($r['data']['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH_KOSONG']]);
});

it('mendaftar dengan tiga bukti', function () {
    hrisSettings();
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $foto = hrisFoto();
    $fotoSha = hash('sha256', (string) base64_decode($foto, true));
    $d0 = hrisWajahSeed('u-dwipa');
    $d1 = array_map(fn ($v) => $v + 0.001, $d0);
    $d2 = array_map(fn ($v) => $v - 0.001, $d0);
    $bukti = [
        buktiMint($d0, 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http', null, $fotoSha),
        buktiMint($d1, 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http'),
        buktiMint($d2, 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http'),
    ];
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptors' => [$d0, $d1, $d2], 'model' => 'sface-2021dec-int8',
            'consent' => true, 'foto' => $foto, 'buktiWajah' => $bukti],
        ['Authorization' => 'Bearer '.$t])->assertCreated();

    $row = HrisWajah::query()->where('user_id', 'u-dwipa')->where('status', 'AKTIF')->first();
    expect(FaceMatcher::storedSet(array_values($row->descriptor)))->toBe([$d0, $d1, $d2]);

    $audit = HrisAudit::query()->where('aksi', 'wajah_didaftarkan')->where('ref_id', 'u-dwipa')->first();
    expect($audit->data)->toMatchArray(['model' => 'sface-2021dec-int8', 'sumber_wajah' => 'server']);

    // Jumlah bukti harus sama dengan jumlah descriptor.
    expect(buktiKode(fn () => app(HrisAttendanceService::class)->enrol(hrisMe('u-andry'),
        ['descriptors' => [hrisDescriptor(0.1), hrisDescriptor(0.2)], 'model' => 'sface-2021dec-int8',
            'consent' => true, 'foto' => hrisFoto(),
            'buktiWajah' => [buktiMint(hrisDescriptor(0.1), 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http')]])))
        ->toBe('bukti_wajah_tidak_valid');
});

it('secret kosong menolak bukti dan mengabaikan flag wajib', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    buktiKonfig('', '', true);
    $sembarang = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8', 'rahasia-asing', 'asing');

    expect(buktiKode(fn () => BuktiWajah::verifikasi($sembarang, 'sface-2021dec-int8')))->toBe('bukti_wajah_tidak_valid');
    expect(BuktiWajah::wajib())->toBeFalse();

    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $r = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK'] + hrisWajahPunch('u-dwipa'),
        ['Authorization' => 'Bearer '.$t])->assertCreated()->json();
    expect($r['data']['punch'])->toMatchArray(['status' => 'VALID']);
});

it('mencatat sumber wajah klien dan tanpa di audit', function () {
    hrisSettings();
    buktiKonfig('', '', false);
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()], $h)
        ->assertCreated();

    $daftar = HrisAudit::query()->where('aksi', 'wajah_didaftarkan')->where('ref_id', 'u-dwipa')->first();
    expect($daftar->data)->toMatchArray(['sumber_wajah' => 'klien'])
        ->and($daftar->data)->not->toHaveKey('descriptor');

    // Belum terdaftar: tidak ada yang dicocokkan.
    $r = app(HrisAttendanceService::class)->cekWajah(hrisMe('u-andry'),
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8']);
    expect($r)->toBe(['terdaftar' => false, 'cocok' => false]);
    $cek = HrisAudit::query()->where('aksi', 'wajah_dicek')->where('ref_id', 'u-andry')->first();
    expect($cek->data)->toMatchArray(['sumber_wajah' => 'tanpa']);
});

it('punch memakai bukti dan menolak bukti rusak', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    buktiKonfig('rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $bukti = buktiMint(hrisWajahSeed('u-dwipa'), 'sface-2021dec-int8', 'rahasia-uji-http-32-byte-xxxxxx', 'uji-http');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    // Descriptor klien salah, tetapi bukti cocok: VALID.
    $r = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK', 'descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $bukti], $h)
        ->assertCreated()->json();
    expect($r['data']['punch'])->toMatchArray(['status' => 'VALID', 'wajah_ok' => true]);

    // Bukti rusak pada punch yang sama: 422 sebelum jawaban duplikat.
    [$payload, $sig] = explode('.', $bukti);
    $rusak = $payload.'.'.($sig[0] === 'A' ? 'B' : 'A').substr($sig, 1);
    test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK', 'descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8', 'buktiWajah' => $rusak], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'bukti_wajah_tidak_valid');
});
