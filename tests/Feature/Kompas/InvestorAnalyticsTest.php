<?php

use App\Support\Modules;
use Illuminate\Testing\TestResponse;

/* u-dwipa holds investor (not admin); u-wandi is superadmin; u-novi has no investor. */

beforeEach(function () {
    config(['laksamana.modules.kompas.data_dir' => storage_path('framework/testing/kompas-db')]);
});

function iaPost(array $body): TestResponse
{
    return test()->call('POST', '/kompas-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

function iaPdf(): string
{
    return base64_encode("%PDF-1.4\n% test\n%%EOF\n");
}

it('investorRingkas sums from the daily map and reads dividends from Brankas in-process', function () {
    $fin = Modules::db('finance');
    $blob = json_decode($fin->selectOne('SELECT data FROM bk_state WHERE id=1')->data, true);
    $blob['investor'] = [['name' => 'Inv A', 'capital' => '100.000.000', 'returns' => [['date' => '2026-09-01', 'amount' => 5000000], ['date' => 'x', 'amount' => 1]]]];
    $fin->update('UPDATE bk_state SET data=? WHERE id=1', [json_encode($blob)]);

    $res = iaPost(['action' => 'investorRingkas', 'sesi' => legacySesi(officeUser('u-dwipa'))])->assertOk()->assertJsonPath('ok', true);
    expect($res->json('data.dividen'))->toMatchArray(['gagal' => false, 'total' => 5000000, 'modal' => 100000000, 'investor' => 1])
        ->and($res->json('data.adaData'))->toBeTrue()
        ->and($res->json('user.bolehUnggah'))->toBeFalse()
        ->and($res->json('data.labaRugi.0'))->toHaveKeys(['kunci', 'totalSales', 'netSales']);
});

it('investorAgenda reads marketing, event and bd in-process', function () {
    $res = iaPost(['action' => 'investorAgenda', 'sesi' => legacySesi(officeUser('u-dwipa'))])->assertOk();
    expect($res->json('data.gagal'))->toBe([])->and($res->json('data'))->toHaveKeys(['event', 'promo', 'hariIni']);
    foreach ($res->json('data.event') as $e) {
        expect($e['tgl'] >= $res->json('data.hariIni'))->toBeTrue();
    }
});

it('only an investor admin uploads; a partial upload keeps what succeeded', function () {
    iaPost(['action' => 'investorLaporUpload', 'sesi' => legacySesi(officeUser('u-dwipa')), 'bulan' => '2026-08', 'berkas' => ['balance' => ['dataBase64' => iaPdf()]]])
        ->assertJsonPath('error', 'Hanya admin modul Investor yang boleh mengunggah laporan.');
});

it('an admin uploads, replaces and deletes a report; the file streams back', function () {
    $sesi = legacySesi(officeUser('u-wandi'));
    iaPost(['action' => 'investorLaporUpload', 'sesi' => $sesi, 'bulan' => '2026-08', 'berkas' => ['balance' => ['dataBase64' => iaPdf(), 'fileName' => 'B.pdf'], 'ledger' => ['dataBase64' => base64_encode('nope')]]])
        ->assertJsonPath('ok', true)->assertJsonPath('data.tersimpan', ['balance'])->assertJsonPath('data.galat', ['ledger: berkasnya bukan PDF']);
    $first = Modules::db('kompas')->selectOne("SELECT kunci FROM inv_lapor WHERE bulan='2026-08' AND jenis='balance'")->kunci;
    iaPost(['action' => 'investorLaporUpload', 'sesi' => $sesi, 'bulan' => '2026-08', 'berkas' => ['balance' => ['dataBase64' => iaPdf()]]])->assertJsonPath('ok', true);
    expect(is_file(storage_path('framework/testing/kompas-db/lapor/'.$first)))->toBeFalse(); // old file removed after the new one

    $file = iaPost(['action' => 'investorLaporFile', 'sesi' => $sesi, 'bulan' => '2026-08', 'jenis' => 'balance']);
    expect($file->headers->get('Content-Type'))->toBe('application/pdf')->and(file_get_contents($file->baseResponse->getFile()->getPathname()))->toStartWith('%PDF');

    iaPost(['action' => 'investorLaporHapus', 'sesi' => $sesi, 'bulan' => '2026-08', 'jenis' => 'balance'])->assertJsonPath('ok', true);
    iaPost(['action' => 'investorLaporFile', 'sesi' => $sesi, 'bulan' => '2026-08', 'jenis' => 'balance'])->assertStatus(404)->assertExactJson(['ok' => false, 'error' => 'laporan tidak ada']);
});

it('analytics: open legacy save/access/roles', function () {
    iaPost(['action' => 'analyticsSave', 'oleh' => 'X', 'data' => ['laporan' => [], 'setting' => ['a' => 1]]])->assertJsonPath('data.saved', true);
    iaPost(['action' => 'analyticsAkses', 'akses' => ['staf' => ['harian' => 7]]])->assertJsonPath('ok', true);
    $this->get('/kompas-api-mysql/api.php?action=analyticsGet')->assertJsonPath('data.akses.staf.harian', 2)->assertJsonPath('data.data.setting.a', 1);
});

it('v1: investor summary needs the module; report upload needs the admin', function () {
    $token = loginAs(officeUser('u-dwipa'));
    $this->withToken($token)->getJson('/api/v1/kompas/investor/summary')->assertOk()->assertJsonPath('meta.canUpload', false);
    $this->withToken($token)->putJson('/api/v1/kompas/investor/reports/2026-08/balance', ['dataBase64' => iaPdf()])->assertStatus(403);
});

it('v1: analytics data is versioned', function () {
    $token = loginAs(officeUser('u-wandi'));
    $v = $this->withToken($token)->getJson('/api/v1/kompas/analytics')->assertOk()->json('meta.version');
    $this->withToken($token)->putJson('/api/v1/kompas/analytics', ['data' => ['laporan' => []]])->assertStatus(428);
    $this->withToken($token)->putJson('/api/v1/kompas/analytics?version='.($v + 1), ['data' => ['laporan' => []]])->assertStatus(409);
    $v2 = $this->withToken($token)->putJson("/api/v1/kompas/analytics?version=$v", ['data' => ['laporan' => [], 'setting' => ['x' => 1]]])->assertOk()->json('meta.version');
    expect($v2)->toBeGreaterThan($v);
    $this->withToken($token)->putJson('/api/v1/kompas/investor/reports/2026-07/ledger', ['dataBase64' => iaPdf(), 'fileName' => 'L.pdf'])->assertOk()->assertJsonPath('data.jenis', 'ledger');
});
