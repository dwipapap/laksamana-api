<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisLowongan;
use App\Modules\Hris\Models\HrisPelamar;
use App\Modules\Hris\Services\EkstraksiCv;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisRekrutmenService;
use App\Modules\Hris\Services\RedaksiPii;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
 * Recruitment (R1–R5): requests head→HR→owner, vacancies, applicants with
 * encrypted contacts, pure-PHP CV extraction, PII redaction, AI screening
 * as advice, hiring into draft Users, 12-month retention. One HTTP
 * identity per test.
 */

function hrisPdf(array $texts, bool $flate = false): string
{
    $content = "BT /F1 12 Tf 50 750 Td\n";
    foreach ($texts as $t) {
        $content .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t).") Tj\n";
    }
    $content .= 'ET';
    $stream = $flate ? gzdeflate($content) : $content;
    $filter = $flate ? "/Filter /FlateDecode\n" : '';
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Length ".strlen($stream)." {$filter}>>\nstream\n".$stream."endstream\nendobj\ntrailer\n<<>>\n";

    return $pdf;
}

function hrisDocx(array $texts): string
{
    $runs = '';
    foreach ($texts as $t) {
        $runs .= '<w:p><w:r><w:t>'.htmlspecialchars($t, ENT_XML1).'</w:t></w:r></w:p>';
    }
    $xml = '<?xml version="1.0"?><w:document xmlns:w="http://x"><w:body>'.$runs.'</w:body></w:document>';
    $tmp = tempnam(sys_get_temp_dir(), 'cvtest');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

function hrisCvBase64(): string
{
    return base64_encode("Nama: Budi\nNIK 3201010101900001\nHP 0812-3456-7890\nbudi@example.com\nJl. Mawar No. 10 Bandung\nPengalaman: kasir 2 tahun.");
}

function hrisSuperadmin(string $uid): void
{
    app(AccountRepository::class)->grantAdmin($uid, '*');
    app(OfficeAccess::class)->forgetUser($uid);
}

it('extracts text from PDF, flate PDF and DOCX in pure PHP', function () {
    $r = EkstraksiCv::dariBerkas('cv.pdf', hrisPdf(['Halo Budi', 'Kasir']));
    expect($r['teks'])->toContain('Halo Budi')->toContain('Kasir');

    $r = EkstraksiCv::dariBerkas('cv.pdf', hrisPdf(['Teks Flate'], true));
    expect($r['teks'])->toContain('Teks Flate');

    $r = EkstraksiCv::dariBerkas('cv.docx', hrisDocx(['Baris satu', 'Baris dua']));
    expect($r['teks'])->toContain('Baris satu')->toContain('Baris dua');

    try {
        EkstraksiCv::dariBerkas('cv.png', 'bytes');
        expect(false)->toBeTrue('expected 422');
    } catch (HrisError $e) {
        expect($e->status)->toBe(422);
    }
    $scan = EkstraksiCv::dariBerkas('cv.pdf', "%PDF-1.4\n1 0 obj\n<< /Length 5 >>\nstream\nabcde\nendstream\nendobj\ntrailer\n<<>>\n");
    expect($scan['teks'])->toBe('')->and($scan['catatan'])->toContain('pindaian');
});

it('redacts NIK, phones, emails and addresses server-side', function () {
    $r = RedaksiPii::redact('NIK 3201010101900001, HP 0812-3456-7890 atau +62 812 3456 7890, budi@example.com, Jl. Mawar No. 10 Bandung.');
    expect($r['teks'])->toContain('[NIK]')->toContain('[TELEPON]')->toContain('[EMAIL]')->toContain('[ALAMAT]');
    expect($r['teks'])->not->toContain('3201010101900001')->not->toContain('budi@example.com');
    expect($r['temuan'])->toMatchArray(['nik' => 1, 'email' => 1, 'alamat' => 1]);
    expect($r['temuan']['telepon'])->toBeGreaterThanOrEqual(1);
});

it('files manpower requests as head, never as crew', function () {
    $t = hrisToken('u-arif');
    $r = test()->postJson('/api/v1/hris/permintaan',
        ['divisi' => 'bar', 'posisi' => 'Bartender', 'jumlah' => 2, 'jenis' => 'TAMBAHAN',
            'alasan' => 'Outlet baru', 'kualifikasi' => 'Pengalaman 1 tahun', 'dibutuhkan_tgl' => '2026-11-01'],
        ['Authorization' => 'Bearer '.$t])
        ->assertCreated()->json('data');
    expect($r)->toMatchArray(['divisi' => 'bar', 'status' => 'MENUNGGU_HR', 'pemohon_id' => 'u-arif',
        'dibutuhkan_tgl' => '2026-11-01']);
});

it('refuses crew requests', function () {
    $t = hrisToken('u-dwipa');
    test()->postJson('/api/v1/hris/permintaan', ['divisi' => 'bar', 'posisi' => 'X'],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403);
});

it('walks HR then Owner approval into a vacancy', function () {
    hrisMakeHr('u-admin');
    hrisSuperadmin('u-admin');
    $req = app(HrisRekrutmenService::class)->buatPermintaan(hrisMe('u-arif'),
        ['divisi' => 'bar', 'posisi' => 'Bartender', 'jumlah' => 1, 'alasan' => 'Butuh']);

    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson("/api/v1/hris/permintaan/{$req->id}/decision",
        ['status' => 'DISETUJUI', 'nota' => 'ok'], $h)->assertOk()->json('data');
    expect($r['status'])->toBe('MENUNGGU_OWNER');
    $r = test()->postJson("/api/v1/hris/permintaan/{$req->id}/decision",
        ['status' => 'DISETUJUI'], $h)->assertOk()->json('data');
    expect($r['status'])->toBe('DISETUJUI');

    $low = test()->getJson('/api/v1/hris/lowongan', $h)->assertOk()->json('data');
    expect(array_column($low, 'posisi'))->toContain('Bartender');
});

it('keeps the Owner step for superadmins', function () {
    hrisSettings(['hr' => ['u-mella']]);
    $req = app(HrisRekrutmenService::class)->buatPermintaan(hrisMe('u-arif'),
        ['divisi' => 'bar', 'posisi' => 'Kasir', 'alasan' => 'Butuh']);
    hrisMakeHr('u-admin');
    app(HrisRekrutmenService::class)->putusPermintaan(hrisMe('u-admin'), $req->fresh(), 'DISETUJUI', 'ok');

    $t = hrisToken('u-mella');
    test()->postJson("/api/v1/hris/permintaan/{$req->id}/decision", ['status' => 'DISETUJUI'],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403);
});

it('uploads, screens, stages and hires an applicant', function () {
    Storage::fake('local');
    config(['hris.ai_url' => 'https://ai.test/screen', 'hris.ai_key' => 'kunci-uji']);
    Http::fake(['https://ai.test/*' => Http::response(
        ['skor' => 82, 'alasan' => ['Pengalaman kasir 2 tahun'], 'ringkasan' => 'Cocok.'], 200)]);
    hrisMakeHr('u-admin');
    $low = HrisLowongan::query()->create(['posisi' => 'Kasir', 'kriteria' => 'Jujur, pengalaman kasir', 'status' => 'BUKA']);

    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $a = test()->postJson('/api/v1/hris/pelamar',
        ['lowongan_id' => $low->id, 'nama' => 'Budi Uji', 'cv' => hrisCvBase64(), 'cv_nama' => 'cv.txt',
            'kontak' => ['telepon' => '0812', 'email' => 'budi@example.com']], $h)
        ->assertCreated()->json('data');
    expect($a)->toMatchArray(['tahap' => 'BARU']);

    $row = HrisPelamar::query()->find($a['id']);
    expect($row->teks_redaksi)->toContain('[NIK]')->not->toContain('3201010101900001');
    expect($row->kontak)->not->toContain('budi@example.com');

    $show = test()->getJson("/api/v1/hris/pelamar/{$a['id']}", $h)->assertOk()->json('data');
    expect($show['kontak'])->toMatchArray(['email' => 'budi@example.com']);

    // AI is refused until configured — here configured, so advice lands.
    $s = test()->postJson("/api/v1/hris/pelamar/{$a['id']}/screen", [], $h)->assertOk()->json('data');
    expect($s['skor'])->toBe(82);
    $riw = test()->getJson("/api/v1/hris/pelamar/{$a['id']}/screening", $h)->assertOk()->json('data');
    expect($riw[0]['hasil']['skor'])->toBe(82);
    expect(json_encode($riw))->not->toContain('3201010101900001');

    // Forward-only stages; skipping straight to hired is refused.
    test()->postJson("/api/v1/hris/pelamar/{$a['id']}/tahap", ['tahap' => 'DITERIMA'], $h)->assertStatus(422);
    foreach (['INTERVIEW', 'DITAWARI'] as $tahap) {
        test()->postJson("/api/v1/hris/pelamar/{$a['id']}/tahap", ['tahap' => $tahap], $h)->assertOk();
    }
    $hired = test()->postJson("/api/v1/hris/pelamar/{$a['id']}/tahap", ['tahap' => 'DITERIMA'], $h)
        ->assertOk()->json('data');
    expect($hired)->toMatchArray(['tahap' => 'DITERIMA']);
    expect($hired['draft_user_id'])->not->toBe('');
    $draft = app(AccountRepository::class)->userById($hired['draft_user_id']);
    expect((int) $draft['active'])->toBe(0);

    test()->deleteJson("/api/v1/hris/pelamar/{$a['id']}", [], $h)->assertOk();
    expect(HrisPelamar::query()->find($a['id']))->toBeNull();
});

it('refuses AI screening until the provider is configured', function () {
    Storage::fake('local');
    config(['hris.ai_url' => '', 'hris.ai_key' => '']);
    hrisMakeHr('u-admin');
    $low = HrisLowongan::query()->create(['posisi' => 'Kasir', 'status' => 'BUKA']);
    $row = app(HrisRekrutmenService::class)->unggahPelamar(hrisMe('u-admin'),
        ['lowongan_id' => $low->id, 'nama' => 'Sari Uji', 'cv' => hrisCvBase64(), 'cv_nama' => 'cv.txt']);

    $t = hrisToken('u-admin');
    test()->postJson("/api/v1/hris/pelamar/{$row->id}/screen", [],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(503)->assertJsonPath('error.code', 'ai_belum_dikonfigurasi');
});

it('deletes expired applicants with their files', function () {
    Storage::fake('local');
    hrisMakeHr('u-admin');
    $low = HrisLowongan::query()->create(['posisi' => 'Kasir', 'status' => 'BUKA']);
    $row = app(HrisRekrutmenService::class)->unggahPelamar(hrisMe('u-admin'),
        ['lowongan_id' => $low->id, 'nama' => 'Tua Uji', 'cv' => hrisCvBase64(), 'cv_nama' => 'cv.txt']);
    $row->fill(['hapus_setelah' => '2020-01-01'])->save();

    $n = app(HrisRekrutmenService::class)->hapusKedaluwarsa('2026-10-15');
    expect($n)->toBe(1);
    expect(HrisPelamar::query()->find($row->id))->toBeNull();
    expect(Storage::disk('local')->exists('hris-cv/'.$row->cv_path))->toBeFalse();
});
