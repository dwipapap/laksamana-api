<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisPerubahanProfil;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisProfilService;
use App\Support\Modules;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/helpers.php';

/*
 * F (issue #344): crew profile + contact change requests with HR approval.
 * The HTTP guard caches its user, so each test logs in at most once: the
 * other side always goes through the service directly.
 */

function profilSiap(): void
{
    app(HrisImporKaryawanService::class)->impor('test');
}

function profilCrew(string $uid = 'u-yuzaalfarel'): array
{
    return ['Authorization' => 'Bearer '.hrisToken($uid)];
}

function profilHr(): array
{
    hrisMakeHr('u-admin');

    return ['Authorization' => 'Bearer '.hrisToken('u-admin')];
}

function profilGet(array $h): array
{
    return test()->getJson('/api/v1/hris/me/profile', $h)->assertOk()->json('data');
}

function profilAjukan(array $h, array $perubahan, string $revisi, string $kunci = 'k-uji-1'): TestResponse
{
    return test()->postJson('/api/v1/hris/me/profile-change-requests',
        ['perubahan' => $perubahan, 'revisi_profil' => $revisi, 'idempotency_key' => $kunci], $h);
}

/** Crew submit through the service (for HR-HTTP tests): returns the shaped row. */
function svcAjukan(string $uid, array $perubahan, string $kunci): array
{
    $svc = app(HrisProfilService::class);
    $revisi = $svc->profil(hrisMe($uid))['revisi_profil'];

    return $svc->ajukan(hrisMe($uid), ['perubahan' => $perubahan,
        'revisi_profil' => $revisi, 'idempotency_key' => $kunci]);
}

/** HR decision through the service (for crew-HTTP tests). */
function svcPutuskan(string $id, string $status, string $catatan = ''): array
{
    hrisMakeHr('u-admin');
    $row = HrisPerubahanProfil::query()->findOrFail($id);

    return app(HrisProfilService::class)->putuskan(hrisMe('u-admin'), $id,
        ['status' => $status, 'catatan' => $catatan, 'berubah' => $row->version]);
}

function akunKontak(string $uid): array
{
    $u = app(AccountRepository::class)->userById($uid);

    return ['email' => mb_strtolower(trim((string) ($u['email'] ?? '')), 'UTF-8'),
        'no_hp' => trim((string) ($u['no_hp'] ?? ''))];
}

function hrisKontak(string $uid): array
{
    $k = HrisKaryawan::query()->where('user_id', $uid)->first();

    return ['alamat' => $k?->alamat, 'kontak_darurat_nama' => $k?->kontak_darurat_nama,
        'kontak_darurat_hp' => $k?->kontak_darurat_hp];
}

function emailTersedia(): bool
{
    $db = Modules::db('account');

    return (bool) $db->selectOne(
        'SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        ['users', 'email']);
}

it('shows the current profile from the server with the five editable fields', function () {
    profilSiap();
    $d = profilGet(profilCrew());

    expect($d['user_id'])->toBe('u-yuzaalfarel')
        ->and($d['editable_fields'])->toBe(['email', 'no_hp', 'alamat', 'kontak_darurat_nama', 'kontak_darurat_hp'])
        ->and($d['revisi_profil'])->toBeString()
        ->and($d['kontak']['no_hp'])->toBe(akunKontak('u-yuzaalfarel')['no_hp'])
        ->and($d['kontak']['email'])->toBe(akunKontak('u-yuzaalfarel')['email'])
        ->and($d['pengajuan_aktif'])->toBeNull();
});

it('creates a MENUNGGU request without touching the official contact', function () {
    profilSiap();
    $h = profilCrew();
    $sebelumAkun = akunKontak('u-yuzaalfarel');
    $sebelumHris = hrisKontak('u-yuzaalfarel');
    $d = profilGet($h);

    $r = profilAjukan($h, ['no_hp' => '081234567890', 'alamat' => 'Jl. Melati 1'], $d['revisi_profil'])
        ->assertCreated()->json('data');

    expect($r['status'])->toBe('MENUNGGU')
        ->and($r['field'])->toBe(['no_hp', 'alamat'])
        ->and(collect($r['beda'])->pluck('field')->all())->toBe(['no_hp', 'alamat'])
        ->and(akunKontak('u-yuzaalfarel'))->toBe($sebelumAkun)
        ->and(hrisKontak('u-yuzaalfarel'))->toBe($sebelumHris);
});

it('refuses injected fields outside the five-contact allowlist', function () {
    profilSiap();
    $h = profilCrew();
    $d = profilGet($h);

    foreach ([['nama' => 'Palsu'], ['nik' => '3201234567890123'], ['pin' => '9999'],
        ['user_id' => 'u-arif'], ['status' => 'DISETUJUI'], ['divisi_id' => 'x']] as $jahat) {
        profilAjukan($h, $jahat, $d['revisi_profil'], 'k-'.md5((string) json_encode($jahat)))->assertStatus(422);
    }
    expect(HrisPerubahanProfil::query()->where('user_id', 'u-yuzaalfarel')->count())->toBe(0);
});

it('refuses no-op, invalid and stale submits', function () {
    profilSiap();
    $h = profilCrew();
    $d = profilGet($h);

    profilAjukan($h, ['no_hp' => $d['kontak']['no_hp']], $d['revisi_profil'], 'k-noop')->assertStatus(422);
    profilAjukan($h, ['email' => 'bukan-email'], $d['revisi_profil'], 'k-badmail')->assertStatus(422);
    profilAjukan($h, ['no_hp' => str_repeat('1', 33)], $d['revisi_profil'], 'k-badhp')->assertStatus(422);
    profilAjukan($h, ['no_hp' => '0811'], 'revisi-basi', 'k-basi')->assertStatus(409);
    expect(HrisPerubahanProfil::query()->where('user_id', 'u-yuzaalfarel')->count())->toBe(0);
});

it('refuses a duplicate email without leaking its owner to the crew', function () {
    profilSiap();
    $h = profilCrew();
    $d = profilGet($h);
    $milik = 'arif.uji.'.time().'@example.com';

    if (! emailTersedia()) {
        // Pre-ALTER the legacy users table has no email column: uniqueness
        // cannot be checked and the submit is accepted; approval then fails
        // visibly instead of pretending success.
        profilAjukan($h, ['email' => $milik], $d['revisi_profil'], 'k-duplikat')->assertCreated();

        return;
    }
    app(AccountRepository::class)->updateUser('u-arif', ['email' => $milik]);
    try {
        $r = profilAjukan($h, ['email' => $milik], $d['revisi_profil'], 'k-duplikat');
        $r->assertStatus(422)->assertJsonPath('error.code', 'email_taken');
        expect($r->json('error.details'))->not()->toHaveKey('takenBy');
    } finally {
        app(AccountRepository::class)->updateUser('u-arif', ['email' => '']);
    }
});

it('keeps one active request per crew and replays idempotency keys', function () {
    profilSiap();
    $h = profilCrew();
    $d = profilGet($h);
    $pertama = profilAjukan($h, ['no_hp' => '0811000001'], $d['revisi_profil'], 'k-sama')
        ->assertCreated()->json('data');

    profilAjukan($h, ['no_hp' => '0811000002'], $d['revisi_profil'], 'k-lain')->assertStatus(409);

    $ulang = profilAjukan($h, ['no_hp' => '0811000001'], $d['revisi_profil'], 'k-sama');
    expect($ulang->status())->toBeIn([200, 201]);
    expect($ulang->json('data.id'))->toBe($pertama['id']);

    profilAjukan($h, ['no_hp' => '0811000003'], $d['revisi_profil'], 'k-sama')->assertStatus(409);
    expect(HrisPerubahanProfil::query()->where('user_id', 'u-yuzaalfarel')->count())->toBe(1);
});

it('scopes history and detail to the owner: another id is a 404', function () {
    profilSiap();
    $milik = svcAjukan('u-yuzaalfarel', ['no_hp' => '0811000001'], 'k-milik');
    $asing = profilCrew('u-arif');

    test()->getJson("/api/v1/hris/me/profile-change-requests/{$milik['id']}", $asing)->assertNotFound();
    test()->postJson("/api/v1/hris/me/profile-change-requests/{$milik['id']}/cancel", ['berubah' => 1], $asing)
        ->assertNotFound();
    test()->getJson('/api/v1/hris/me/profile-change-requests', $asing)->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('lets the owner cancel while waiting, then file again', function () {
    profilSiap();
    $h = profilCrew();
    $d = profilGet($h);
    $id = profilAjukan($h, ['no_hp' => '0811000001'], $d['revisi_profil'])->assertCreated()->json('data.id');

    $batal = test()->postJson("/api/v1/hris/me/profile-change-requests/{$id}/cancel",
        ['berubah' => HrisPerubahanProfil::query()->find($id)->version], $h)
        ->assertOk()->json('data');
    expect($batal['status'])->toBe('DIBATALKAN')
        ->and(akunKontak('u-yuzaalfarel')['no_hp'])->toBe($d['kontak']['no_hp']);

    test()->postJson("/api/v1/hris/me/profile-change-requests/{$id}/cancel", ['berubah' => 99], $h)->assertStatus(409);

    expect(profilGet($h)['pengajuan_aktif'])->toBeNull();
    profilAjukan($h, ['no_hp' => '0811000002'], profilGet($h)['revisi_profil'], 'k-sesudah-batal')->assertCreated();
});

it('keeps PUT /me/contact direct: an outside change is an approval conflict, not a silent overwrite', function () {
    profilSiap();
    $h = profilCrew();
    $d = profilGet($h);
    $id = profilAjukan($h, ['no_hp' => '0811000001'], $d['revisi_profil'])->assertCreated()->json('data.id');

    test()->putJson('/api/v1/me/contact', ['noHp' => '089999999999'], $h)->assertOk();
    expect(akunKontak('u-yuzaalfarel')['no_hp'])->toBe('089999999999');

    try {
        svcPutuskan($id, 'DISETUJUI');
        expect(false)->toBeTrue('expected kontak_berubah');
    } catch (HrisError $e) {
        expect($e->errorCode)->toBe('kontak_berubah');
    }
    expect(HrisPerubahanProfil::query()->find($id)->status)->toBe('MENUNGGU')
        ->and(akunKontak('u-yuzaalfarel')['no_hp'])->toBe('089999999999');

    // HR can still reject the conflicted row; the owner cancels and refiles.
    svcPutuskan($id, 'DITOLAK', 'Nomor sudah benar, pengajuan kedaluwarsa.');
    expect(HrisPerubahanProfil::query()->find($id)->status)->toBe('DITOLAK');
});

it('approves only the requested patch and keeps PIN, active, name and the rest', function () {
    profilSiap();
    $hr = profilHr();
    $sebelum = app(AccountRepository::class)->userById('u-yuzaalfarel');
    $id = svcAjukan('u-yuzaalfarel', ['no_hp' => '+628110000001', 'alamat' => 'Jl. Melati 2',
        'kontak_darurat_nama' => 'Ibu Ani', 'kontak_darurat_hp' => '0812222222'], 'k-patch')['id'];

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');

    $sesudah = app(AccountRepository::class)->userById('u-yuzaalfarel');
    expect($sesudah['no_hp'])->toBe('+628110000001')
        ->and($sesudah['email'] ?? '')->toBe($sebelum['email'] ?? '')
        ->and($sesudah['pin'])->toBe($sebelum['pin'])
        ->and((int) $sesudah['active'])->toBe((int) $sebelum['active'])
        ->and($sesudah['name'])->toBe($sebelum['name'])
        ->and($sesudah['username'])->toBe($sebelum['username']);
    expect(hrisKontak('u-yuzaalfarel'))->toBe(['alamat' => 'Jl. Melati 2',
        'kontak_darurat_nama' => 'Ibu Ani', 'kontak_darurat_hp' => '0812222222']);
});

it('applies an email change on Account when the legacy column exists', function () {
    profilSiap();
    $hr = profilHr();
    if (! emailTersedia()) {
        // Pre-ALTER: approval must fail visibly on DIPROSES, never as success.
        $id = svcAjukan('u-yuzaalfarel', ['email' => 'kru.uji@example.com'], 'k-email-prealter')['id'];
        test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
            ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
            ->assertStatus(422)->assertJsonPath('error.code', 'email_tak_tersimpan');
        expect(HrisPerubahanProfil::query()->find($id)->status)->toBe('DIPROSES');

        return;
    }
    $target = 'kru.uji.'.time().'@example.com';
    $id = svcAjukan('u-yuzaalfarel', ['email' => $target], 'k-email-'.$target)['id'];

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
    expect(akunKontak('u-yuzaalfarel')['email'])->toBe($target);
});

it('requires a readable note to reject and keeps the profile on reject', function () {
    profilSiap();
    $hr = profilHr();
    $sebelumAkun = akunKontak('u-yuzaalfarel');
    $id = svcAjukan('u-yuzaalfarel', ['alamat' => 'Jl. Tolak 1'], 'k-tolak')['id'];

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DITOLAK', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)->assertStatus(422);

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DITOLAK', 'catatan' => 'Alamat kurang lengkap, tulis patokannya.',
            'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DITOLAK');
    expect(akunKontak('u-yuzaalfarel'))->toBe($sebelumAkun)
        ->and(hrisKontak('u-yuzaalfarel')['alamat'])->not()->toBe('Jl. Tolak 1');
});

it('refuses HR endpoints to heads, hr-list members and other non-admins', function () {
    profilSiap();
    hrisSettings(['hr' => ['u-arif']]);

    // u-adit heads a division but is not an HRIS module admin.
    expect(app(OfficeAccess::class)->isModuleAdmin('u-adit', 'hris'))->toBeFalse();
    $kepala = ['Authorization' => 'Bearer '.hrisToken('u-adit')];
    test()->getJson('/api/v1/hris/kelola/perubahan-profil', $kepala)->assertForbidden();
    test()->getJson('/api/v1/hris/kelola/perubahan-profil/x', $kepala)->assertForbidden();
    test()->postJson('/api/v1/hris/kelola/perubahan-profil/x/decision',
        ['status' => 'DISETUJUI', 'berubah' => 1], $kepala)->assertForbidden();
});

it('refuses the queue to hr-list members without module admin rights', function () {
    profilSiap();
    hrisSettings(['hr' => ['u-arif']]);
    $daftar = ['Authorization' => 'Bearer '.hrisToken('u-arif')];
    test()->getJson('/api/v1/hris/kelola/perubahan-profil', $daftar)->assertForbidden();
});

it('lets HR and superadmin decide, with stale versions refused', function () {
    profilSiap();
    $hr = profilHr();
    $id = svcAjukan('u-yuzaalfarel', ['no_hp' => '0811000007'], 'k-stale')['id'];

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => 99999], $hr)->assertStatus(409);

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');

    // Same decision replayed returns the final state; a different one is refused.
    $v = HrisPerubahanProfil::query()->find($id)->version;
    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => $v], $hr)->assertOk();
    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DITOLAK', 'catatan' => 'Berubah pikiran.', 'berubah' => $v], $hr)->assertStatus(409);
});

it('lists the queue oldest-first with filters and an accurate total', function () {
    profilSiap();
    $hr = profilHr();
    svcAjukan('u-yuzaalfarel', ['no_hp' => '0811000011'], 'k-antre-a');
    svcAjukan('u-arif', ['alamat' => 'Jl. Antre 2'], 'k-antre-b');

    $semua = test()->getJson('/api/v1/hris/kelola/perubahan-profil', $hr)->assertOk()->json();
    expect($semua['meta']['total'])->toBe(2);
    expect($semua['data'][0]['diajukan_at'])->toBeLessThanOrEqual($semua['data'][1]['diajukan_at']);

    test()->getJson('/api/v1/hris/kelola/perubahan-profil?user=u-arif', $hr)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user_id', 'u-arif');
    test()->getJson('/api/v1/hris/kelola/perubahan-profil?per_page=1&page=2', $hr)->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    test()->getJson('/api/v1/hris/kelola/perubahan-profil?status=DISETUJUI', $hr)->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('retries a half-applied approval: verified targets are skipped, the rest is finished', function () {
    profilSiap();
    $hr = profilHr();
    $id = svcAjukan('u-yuzaalfarel', ['no_hp' => '0811000021', 'alamat' => 'Jl. Lanjut 3'], 'k-sebagian')['id'];

    // Simulate a crash after the Account write: the value is already the
    // target and only the core flag is missing.
    app(AccountRepository::class)->updateUser('u-yuzaalfarel', ['no_hp' => '0811000021']);
    $row = HrisPerubahanProfil::query()->find($id);
    $row->fill(['status' => HrisPerubahanProfil::DIPROSES, 'diputus_oleh_id' => 'u-admin',
        'diputus_oleh' => 'Admin', 'diputus_at' => hrisMs(2026, 10, 23, 10, 0),
        'apply_account_ok' => true, 'apply_core_ok' => false])->save();

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => $row->fresh()->version], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
    expect(akunKontak('u-yuzaalfarel')['no_hp'])->toBe('0811000021')
        ->and(hrisKontak('u-yuzaalfarel')['alamat'])->toBe('Jl. Lanjut 3');
});

it('fails an approval visibly when the Account write breaks, then recovers on retry', function () {
    profilSiap();
    $hr = profilHr();
    $id = svcAjukan('u-yuzaalfarel', ['no_hp' => '0811000031', 'alamat' => 'Jl. Pulih 4'], 'k-gagal-akun')['id'];

    HrisProfilService::$ujiGagalTulis = function (string $target): void {
        if ($target === 'akun') {
            throw new HrisError('terap_gagal', 'Akun tidak bisa ditulis sekarang.', 422);
        }
    };
    try {
        test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
            ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
            ->assertStatus(422)->assertJsonPath('error.code', 'terap_gagal');
    } finally {
        HrisProfilService::$ujiGagalTulis = null;
    }
    expect(HrisPerubahanProfil::query()->find($id)->status)->toBe('DIPROSES')
        ->and(akunKontak('u-yuzaalfarel')['no_hp'])->not()->toBe('0811000031');

    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
    expect(akunKontak('u-yuzaalfarel')['no_hp'])->toBe('0811000031')
        ->and(hrisKontak('u-yuzaalfarel')['alamat'])->toBe('Jl. Pulih 4');
});

it('blocks approval when the requester leaves before the decision', function () {
    profilSiap();
    $hr = profilHr();
    $id = svcAjukan('u-yuzaalfarel', ['no_hp' => '0811000041'], 'k-keluar')['id'];

    setUserActive('u-yuzaalfarel', false);
    test()->postJson("/api/v1/hris/kelola/perubahan-profil/{$id}/decision",
        ['status' => 'DISETUJUI', 'berubah' => HrisPerubahanProfil::query()->find($id)->version], $hr)
        ->assertStatus(422)->assertJsonPath('error.code', 'karyawan_nonaktif');
    expect(HrisPerubahanProfil::query()->find($id)->status)->toBe('MENUNGGU');
});
