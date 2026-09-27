<?php

require_once __DIR__.'/helpers.php';

use App\Modules\Dw\Services\DwService;
use App\Support\Modules;

/*
 * Legacy /dw-api-mysql/api.php, cluster (1): reads, Pekerja Harian,
 * permintaan, ajuan and role gates. Full old-vs-new parity lives in
 * tools/parity/cases/dw.json.
 *
 * Two harness rules shape every test below:
 * - ONE auth identity per test: the scoped Sesi guard caches its user for
 *   the whole test, so a second identity would silently run as the first.
 *   Cross-identity setup goes through DwService directly (with the real
 *   display names), never through a second HTTP login.
 * - Legacy simpanPekerja/simpanPermintaan with an explicit id take the
 *   blind-UPDATE path (no insert); creates therefore omit `id` and use the
 *   generated one. Only simpanAjuan is a true upsert.
 *
 * Identities from the restored prod dump:
 *   u-admin       = superadmin (HRD + admin)
 *   u-rizkiarfan  = Tim "Office, HRD" => admin of dw => HRD
 *   u-arif        = head of bar (module via headship, NOT HRD)
 *   u-mella       = head of floor (NOT HRD)
 *   u-yuzaalfarel = Bar crew, no dw module at all
 */

function dwPost(array $body): array
{
    return test()->legacyPost('/dw-api-mysql/api.php', $body)->assertOk()->json();
}

function dwSesi(string $uid): string
{
    return legacySesi(officeUser($uid));
}

function dwName(string $uid): string
{
    return officeUser($uid)['name'];
}

/** Live phone number of a restored worker (never hard-code: one digit off silently tests nothing). */
function dwHp(string $id): string
{
    return Modules::db('dw')->selectOne(dwSql('SELECT `no_hp` FROM `dw_pekerja` WHERE `id` = ?'), [$id])->no_hp;
}

function dwSvc(): DwService
{
    return app(DwService::class);
}

it('serves the open jadwalDW without a session and rejects bad dates', function () {
    $r = test()->get('/dw-api-mysql/api.php?action=jadwalDW&dari=2026-09-01&sampai=2026-09-30')->assertOk()->json();
    expect($r['ok'])->toBeTrue()->and($r['data'])->toHaveKeys(['rows', 'dari', 'sampai']);
    foreach ($r['data']['rows'] as $row) {
        expect($row)->toHaveKeys(['id', 'dwId', 'nama', 'divisi', 'posisi', 'tgl', 'm', 's', 'hadir'])
            ->and($row)->not->toHaveKeys(['hp', 'no_hp', 'pin', 'bayarNomor']);
    }

    // swapped range is normalised, like the legacy read
    $s = test()->get('/dw-api-mysql/api.php?action=jadwalDW&dari=2026-09-30&sampai=2026-09-01')->assertOk()->json();
    expect($s['data']['dari'])->toBe('2026-09-01')->and($s['data']['sampai'])->toBe('2026-09-30');

    $bad = test()->get('/dw-api-mysql/api.php?action=jadwalDW&dari=2026-13-01&sampai=2026-09-30')->assertOk()->json();
    expect($bad['ok'])->toBeFalse()->and($bad['error'])->toBe('jadwalDW butuh dari & sampai (YYYY-MM-DD)');
});

it('rejects getAll without a session', function () {
    expect(test()->get('/dw-api-mysql/api.php?action=getAll')->json('error'))->toStartWith('sesi_tidak_sah:');
});

it('rejects getAll with a bad token', function () {
    expect(test()->get('/dw-api-mysql/api.php?action=getAll&sesi=deadbeef')->json('error'))->toStartWith('sesi_tidak_sah:');
});

it('rejects module-less crew without the module checkbox message', function () {
    $r = dwPost(['action' => 'simpanPekerja', 'sesi' => dwSesi('u-yuzaalfarel'), 'row' => ['nama' => 'X', 'hp' => '080000000099']]);
    expect($r['ok'])->toBeFalse()->and($r['error'])->toStartWith('tanpa_modul:');
});

it('serves getAll with roles and full phone fields for HRD', function () {
    $r = test()->get('/dw-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.dwSesi('u-rizkiarfan'))->assertOk()->json();
    expect($r['ok'])->toBeTrue();
    $d = $r['data'];
    expect($d)->toHaveKeys(['setting', 'pekerja', 'ajuan', 'permintaan', 'peran'])
        ->and($d['peran'])->toMatchArray(['hrd' => 1, 'lihat' => 1, 'admin' => 1])
        ->and($d['pekerja'])->not->toBe([]);
    // history is computed over the whole table, not the visible range
    $terakhir = Modules::db('dw')->selectOne("SELECT MAX(`tgl`) m FROM `dw_ajuan` WHERE `dw_id`='DWmtxzw6fd369' AND `status`='DISETUJUI'")->m;
    $abi = collect($d['pekerja'])->firstWhere('id', 'DWmtxzw6fd369');
    expect($abi)->toMatchArray(['nama' => 'Abi', 'hp' => dwHp('DWmtxzw6fd369'), 'terakhirKerja' => $terakhir])
        ->and($abi)->toHaveKeys(['bank', 'bayarJenis', 'bayarBank', 'bayarNomor', 'bayarNama', 'catatan', 'kerjaBulanIni']);
});

it('lets heads see the full pool', function () {
    $r = test()->get('/dw-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.dwSesi('u-arif'))->assertOk()->json();
    expect($r['data']['peran'])->toMatchArray(['hrd' => 0, 'head' => 1, 'lihat' => 1, 'divisi' => ['bar']]);
    expect(collect($r['data']['pekerja'])->firstWhere('id', 'DWmtxzw6fd369'))->toHaveKey('hp');
});

it('never lets a head decide ajuan', function () {
    $putus = dwPost(['action' => 'putusAjuan', 'sesi' => dwSesi('u-arif'), 'id' => 'AJmty25ynb821', 'status' => 'DITOLAK']);
    expect($putus['ok'])->toBeFalse()->and($putus['error'])->toStartWith('tidak_berhak:');
});

it('sweeps past MENUNGGU rows to KEDALUWARSA on read', function () {
    $sesi = dwSesi('u-rizkiarfan');
    Modules::db('dw')->insert(dwSql("INSERT INTO dw_ajuan (id,dw_id,tgl,jam_mulai,jam_selesai,divisi,status) VALUES ('AJsweep1','DWmtxzw6fd369','2020-01-06','18:00','23:00','kitchen','MENUNGGU')"));
    Modules::db('dw')->insert(dwSql("INSERT INTO dw_permintaan (id,divisi,tgl,jam_mulai,jam_selesai,jumlah,status) VALUES ('PMsweep1','kitchen','2020-01-06','18:00','23:00',2,'MENUNGGU')"));

    test()->get('/dw-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.$sesi)->assertOk();

    $a = Modules::db('dw')->selectOne(dwSql("SELECT status, putus_oleh, putus_nota FROM dw_ajuan WHERE id='AJsweep1'"));
    $p = Modules::db('dw')->selectOne(dwSql("SELECT status, putus_oleh FROM dw_permintaan WHERE id='PMsweep1'"));
    expect($a->status)->toBe('KEDALUWARSA')->and($a->putus_oleh)->toBe('(sistem)')
        ->and($a->putus_nota)->toBe('Tanggalnya lewat tanpa diputuskan')
        ->and($p->status)->toBe('KEDALUWARSA')->and($p->putus_oleh)->toBe('(sistem)');
});

it('validates pekerja input in legacy order with readable errors', function () {
    $sesi = dwSesi('u-rizkiarfan');
    expect(dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => ['nama' => '', 'hp' => '080000000011']])['error'])->toBe('Nama DW wajib diisi');
    expect(dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => ['nama' => 'No HP', 'hp' => '']])['error'])
        ->toBe('No. HP wajib diisi — nomor inilah identitas DW, dan lewat itu HR mengabarinya');
});

it('answers a duplicate no_hp with the readable conflict', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $hp = dwHp('DWmtxzw6fd369');
    $dashed = substr($hp, 0, 4).'-'.substr($hp, 4, 4).'-'.substr($hp, 8);
    $dup = dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => ['nama' => 'Kembar', 'hp' => $dashed]]);
    expect($dup['ok'])->toBeFalse()
        ->and($dup['error'])->toBe('No. HP '.$hp.' sudah terdaftar atas nama Abi. Sunting data itu, jangan buat baru.');
});

it('creates and updates pekerja with normalisation and status mapping', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $r = dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => [
        'nama' => 'Uji Coba', 'hp' => '+62 800-0000-011', 'status' => 'PANTAU',
        'divisi' => ['floor', 'bar', 'floor'], 'posisi' => 'Waiter/Waitress',
        'bayarJenis' => 'gopay', 'bayarBank' => 'BCA', 'bayarNomor' => '123', 'bayarNama' => 'Uji',
    ]]);
    expect($r['data'])->toMatchArray(['saved' => true, 'baru' => true]);
    $id = $r['data']['id'];

    $row = Modules::db('dw')->selectOne(dwSql('SELECT * FROM dw_pekerja WHERE id=?'), [$id]);
    expect($row->no_hp)->toBe('08000000011')->and($row->status)->toBe('AKTIF')
        ->and($row->divisi)->toBe('floor,bar')->and($row->dibuat_oleh)->toBe(dwName('u-rizkiarfan'))
        ->and($row->bayar_jenis)->toBe('GOPAY')->and($row->bayar_bank)->toBe('');

    // updating the same id keeps dibuat_* and flips baru off; another's number stays taken
    $u = dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => ['id' => $id, 'nama' => 'Uji Coba', 'hp' => '08000000011', 'status' => 'BLOKIR']]);
    expect($u['data'])->toMatchArray(['baru' => false, 'id' => $id]);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT status FROM dw_pekerja WHERE id=?'), [$id])->status)->toBe('NONAKTIF');
    $dup = dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => ['id' => $id, 'nama' => 'Uji Coba', 'hp' => dwHp('DWmtxzw6fd369')]]);
    expect($dup['error'])->toContain('sudah terdaftar atas nama Abi');
});

it('deletes pekerja but keeps their shifts as orphans', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $wid = dwPost(['action' => 'simpanPekerja', 'sesi' => $sesi, 'row' => ['nama' => 'Yatim', 'hp' => '080000000021']])['data']['id'];
    dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['id' => 'AJorph1', 'dwId' => $wid, 'tgl' => '2030-04-01', 'm' => '18:00', 's' => '23:00', 'divisi' => 'bar']]);
    dwPost(['action' => 'putusAjuan', 'sesi' => $sesi, 'id' => 'AJorph1', 'status' => 'DISETUJUI']);

    expect(dwPost(['action' => 'hapusPekerja', 'sesi' => $sesi, 'id' => $wid])['data'])->toMatchArray(['deleted' => true, 'id' => $wid]);

    $cal = test()->get('/dw-api-mysql/api.php?action=jadwalDW&dari=2030-04-01&sampai=2030-04-01')->assertOk()->json();
    expect(collect($cal['data']['rows'])->firstWhere('id', 'AJorph1')['nama'])->toBe('(DW dihapus)');
});

it('validates ajuan input in legacy order', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $nonaktif = Modules::db('dw')->selectOne(dwSql("SELECT `id`,`nama` FROM `dw_pekerja` WHERE `status`='NONAKTIF' LIMIT 1"));
    expect(dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['tgl' => '2030-05-01', 'm' => '18:00', 's' => '23:00']])['error'])->toBe('Ajuan butuh DW dan tanggal');
    expect(dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmtxzw6fd369', 'tgl' => '2030-05-01']])['error'])->toBe('Jam mulai dan jam selesai wajib diisi');
    expect(dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWtidakada', 'tgl' => '2030-05-01', 'm' => '18:00', 's' => '23:00']])['error'])->toBe('DW tidak ditemukan: DWtidakada');
    expect(dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => $nonaktif->id, 'tgl' => '2030-05-01', 'm' => '18:00', 's' => '23:00']])['error'])
        ->toBe($nonaktif->nama.' berstatus tidak aktif dan tidak bisa dijadwalkan.');
});

it('forces new ajuan to MENUNGGU and inherits the primary divisi', function () {
    $sesi = dwSesi('u-rizkiarfan');
    // Arkan holds floor,bar — the row inherits the FIRST only
    $r = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['id' => 'AJinh1', 'dwId' => 'DWmszpwipk196', 'tgl' => '2030-05-02', 'm' => '18:00', 's' => '23:00', 'status' => 'DISETUJUI']]);
    expect($r['data']['saved'])->toBeTrue()
        ->and($r['data']['row'])->toMatchArray(['status' => 'MENUNGGU', 'divisi' => 'floor', 'posisi' => 'Waiter/Waitress']);
});

it('reports overlaps instead of saving, across midnight too', function () {
    $sesi = dwSesi('u-rizkiarfan');
    // Abi works kitchen 2026-09-12 18:00-00:00 (DISETUJUI AJmty25ynb821)
    $b = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmtxzw6fd369', 'tgl' => '2026-09-12', 'm' => '19:00', 's' => '20:00']]);
    expect($b['data']['saved'])->toBeFalse()
        ->and($b['data']['bentrok'])->toMatchArray(['nama' => 'Abi', 'tgl' => '2026-09-12', 'divisi' => 'kitchen', 'm' => '18:00', 's' => '00:00', 'status' => 'DISETUJUI', 'divisiBaru' => 'kitchen', 'mBaru' => '19:00', 'sBaru' => '20:00'])
        ->and($b['data']['bentrok'])->not->toHaveKey('lintasHari');

    // 18:30-00:30 on 09-12 still runs at 00:15 on 09-13: lintasHari
    $c = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmty03raq618', 'tgl' => '2026-09-13', 'm' => '00:00', 's' => '01:00']]);
    expect($c['data']['saved'])->toBeFalse()
        ->and($c['data']['bentrok']['lintasHari'])->toBeTrue()
        ->and($c['data']['bentrok']['tglBaru'])->toBe('2026-09-13');

    // adjacent shifts are fine
    $ok = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmtxzw6fd369', 'tgl' => '2026-09-12', 'm' => '10:00', 's' => '12:00']]);
    expect($ok['data']['saved'])->toBeTrue();
});

it('timpa overwrites the conflicting row by id', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $r = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmtxzw6fd369', 'tgl' => '2026-09-12', 'm' => '19:00', 's' => '21:00', 'timpa' => true]]);
    expect($r['data']['saved'])->toBeTrue()->and($r['data']['row']['id'])->toBe('AJmty25ynb821');
    expect(Modules::db('dw')->selectOne(dwSql("SELECT jam_mulai, status FROM dw_ajuan WHERE id='AJmty25ynb821'")))
        ->toMatchArray(['jam_mulai' => '19:00', 'status' => 'MENUNGGU']);
});

it('decides, bulk-decides and deletes ajuan as HRD', function () {
    $sesi = dwSesi('u-rizkiarfan');
    dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['id' => 'AJdec1', 'dwId' => 'DWmszpwipk196', 'tgl' => '2030-05-03', 'm' => '18:00', 's' => '23:00']]);
    expect(dwPost(['action' => 'putusAjuan', 'sesi' => $sesi, 'id' => 'AJdec1', 'status' => 'YES'])['error'])->toBe('Status putusan tidak dikenal: YES');
    expect(dwPost(['action' => 'putusAjuan', 'sesi' => $sesi, 'id' => 'AJtidakada', 'status' => 'DITOLAK'])['error'])->toBe('Ajuan tidak ditemukan: AJtidakada');

    expect(dwPost(['action' => 'putusAjuan', 'sesi' => $sesi, 'id' => 'AJdec1', 'status' => 'DISETUJUI', 'nota' => 'ok'])['data'])
        ->toMatchArray(['saved' => true, 'status' => 'DISETUJUI']);
    expect(Modules::db('dw')->selectOne(dwSql("SELECT putus_oleh FROM dw_ajuan WHERE id='AJdec1'"))->putus_oleh)->toBe(dwName('u-rizkiarfan'));

    dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['id' => 'AJbulk1', 'dwId' => 'DWmtxzw6fd369', 'tgl' => '2030-06-01', 'm' => '10:00', 's' => '12:00']]);
    dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['id' => 'AJbulk2', 'dwId' => 'DWmtxzw6fd369', 'tgl' => '2030-06-01', 'm' => '13:00', 's' => '15:00']]);
    expect(dwPost(['action' => 'putusBanyak', 'sesi' => $sesi, 'ids' => [], 'status' => 'DISETUJUI'])['data'])->toMatchArray(['saved' => true, 'jumlah' => 0]);
    expect(dwPost(['action' => 'putusBanyak', 'sesi' => $sesi, 'ids' => ['AJbulk1', 'AJbulk2'], 'status' => 'DISETUJUI'])['data'])
        ->toMatchArray(['saved' => true, 'jumlah' => 2, 'status' => 'DISETUJUI']);

    expect(dwPost(['action' => 'hapusAjuan', 'sesi' => $sesi, 'id' => 'AJbulk1'])['data'])->toMatchArray(['deleted' => true]);
    expect(Modules::db('dw')->selectOne(dwSql("SELECT COUNT(*) n FROM dw_ajuan WHERE id='AJbulk1'"))->n)->toBe(0);
});

it('lets heads request ajuan for their own division only', function () {
    $sesi = dwSesi('u-arif');
    $r = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmtxzw6fd369', 'tgl' => '2030-07-01', 'm' => '18:00', 's' => '23:00', 'divisi' => 'bar']]);
    expect($r['data']['saved'])->toBeTrue()->and($r['data']['row']['divisi'])->toBe('bar');

    $other = dwPost(['action' => 'simpanAjuan', 'sesi' => $sesi, 'row' => ['dwId' => 'DWmtxzw6fd369', 'tgl' => '2030-07-01', 'm' => '18:00', 's' => '23:00', 'divisi' => 'kitchen']]);
    expect($other['ok'])->toBeFalse()->and($other['error'])->toStartWith('tidak_berhak:');
});

it('validates permintaan input in legacy order', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $base = ['divisi' => 'bar', 'tgl' => '2030-08-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 2];
    expect(dwPost(['action' => 'simpanPermintaan', 'sesi' => $sesi, 'row' => array_merge($base, ['tgl' => ''])])['error'])->toBe('Tanggal permintaan wajib diisi');
    expect(dwPost(['action' => 'simpanPermintaan', 'sesi' => $sesi, 'row' => array_merge($base, ['m' => ''])])['error'])->toBe('Jam mulai dan jam selesai wajib diisi');
    expect(dwPost(['action' => 'simpanPermintaan', 'sesi' => $sesi, 'row' => array_merge($base, ['divisi' => ''])])['error'])->toBe('Divisi wajib diisi');
    expect(dwPost(['action' => 'simpanPermintaan', 'sesi' => $sesi, 'row' => array_merge($base, ['jumlah' => 31])])['error'])->toBe('Jumlah orang harus antara 1 dan 30');
});

it('filters usulan to active workers within jumlah and reports conflicts', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $nonaktif = Modules::db('dw')->selectOne(dwSql("SELECT `id` FROM `dw_pekerja` WHERE `status`='NONAKTIF' LIMIT 1"))->id;
    // Abi is booked kitchen 2026-09-12 18:00-00:00; Akbar is free that night
    $r = dwPost(['action' => 'simpanPermintaan', 'sesi' => $sesi, 'row' => [
        'divisi' => 'floor', 'tgl' => '2026-09-12', 'm' => '18:00', 's' => '23:00',
        'jumlah' => 2, 'usulan' => ['DWmtxzw6fd369', $nonaktif, 'DWtidakada', 'DWmty9fok2781', 'DWmtxzw6fd369'],
    ]]);
    expect($r['data']['saved'])->toBeTrue();
    // NONAKTIF + unknown dropped, Abi conflicted out, order kept, trimmed to jumlah
    expect($r['data']['row']['usulan'])->toBe(['DWmty9fok2781']);
    expect($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['dwId' => 'DWmtxzw6fd369', 'nama' => 'Abi']);
});

it('records a head-created permintaan under the head name', function () {
    $r = dwPost(['action' => 'simpanPermintaan', 'sesi' => dwSesi('u-arif'), 'row' => ['divisi' => 'bar', 'tgl' => '2030-09-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 2]]);
    expect($r['data']['saved'])->toBeTrue()
        ->and($r['data']['row'])->toMatchArray(['status' => 'MENUNGGU', 'dibuatOleh' => dwName('u-arif')]);
});

it('resets edited permintaan to MENUNGGU but keeps the original requester', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2030-09-02', 'm' => '18:00', 's' => '23:00', 'jumlah' => 2], dwName('u-arif'));
    dwSvc()->decidePermintaan($pm['row']['id'], 'DISETUJUI', '', dwName('u-rizkiarfan'));

    // HRD fixes the head's request: decision trail cleared, requester kept, editor recorded
    $r = dwPost(['action' => 'simpanPermintaan', 'sesi' => $sesi, 'row' => ['id' => $pm['row']['id'], 'divisi' => 'bar', 'tgl' => '2030-09-02', 'm' => '18:00', 's' => '23:00', 'jumlah' => 3]]);
    expect($r['data']['row'])->toMatchArray(['status' => 'MENUNGGU', 'jumlah' => 3, 'dibuatOleh' => dwName('u-arif'), 'diubahOleh' => dwName('u-rizkiarfan')]);
});

it('refuses a head approving their own permintaan', function () {
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2030-10-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-arif'));
    $r = dwPost(['action' => 'putusPermintaan', 'sesi' => dwSesi('u-arif'), 'id' => $pm['row']['id'], 'status' => 'DISETUJUI']);
    expect($r['ok'])->toBeFalse()
        ->and($r['error'])->toBe('tidak_berhak: Menyetujui atau menolak permintaan hanya bisa dilakukan HRD.');
});

it('refuses a head cancelling another division permintaan', function () {
    $pm = dwSvc()->savePermintaan(['divisi' => 'kitchen', 'tgl' => '2030-10-02', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-rizkiarfan'));
    $r = dwPost(['action' => 'putusPermintaan', 'sesi' => dwSesi('u-arif'), 'id' => $pm['row']['id'], 'status' => 'BATAL']);
    expect($r['ok'])->toBeFalse()
        ->and($r['error'])->toBe('tidak_berhak: Menyetujui atau menolak permintaan hanya bisa dilakukan HRD.');
});

it('lets a head cancel their own permintaan', function () {
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2030-10-03', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-arif'));
    $r = dwPost(['action' => 'putusPermintaan', 'sesi' => dwSesi('u-arif'), 'id' => $pm['row']['id'], 'status' => 'BATAL', 'nota' => 'tutup']);
    expect($r['data'])->toMatchArray(['saved' => true, 'status' => 'BATAL']);
});

it('lets HRD refuse a permintaan', function () {
    $pm = dwSvc()->savePermintaan(['divisi' => 'kitchen', 'tgl' => '2030-10-04', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-rizkiarfan'));
    $r = dwPost(['action' => 'putusPermintaan', 'sesi' => dwSesi('u-rizkiarfan'), 'id' => $pm['row']['id'], 'status' => 'DITOLAK']);
    expect($r['data'])->toMatchArray(['saved' => true, 'status' => 'DITOLAK']);
});

it('assigns workers HRD-only, approving at once and reporting the held-back', function () {
    $sesi = dwSesi('u-rizkiarfan');
    expect(dwPost(['action' => 'tugaskanDW', 'sesi' => $sesi, 'permintaanId' => 'PMtidakada', 'dwIds' => ['DWmtxzw6fd369']])['error'])
        ->toBe('Permintaan tidak ditemukan: PMtidakada');
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2030-11-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 3], dwName('u-arif'));
    expect(dwPost(['action' => 'tugaskanDW', 'sesi' => $sesi, 'permintaanId' => $pm['row']['id'], 'dwIds' => []])['error'])->toBe('Pilih dulu siapa yang ditugaskan');

    // Abi is free that night, Akbar is free too
    $r = dwPost(['action' => 'tugaskanDW', 'sesi' => $sesi, 'permintaanId' => $pm['row']['id'], 'dwIds' => ['DWmtxzw6fd369', 'DWmty9fok2781']]);
    expect($r['data'])->toMatchArray(['saved' => true, 'masuk' => 2, 'tertahan' => [], 'terpenuhi' => 2, 'jumlah' => 3])
        ->and($r['data']['ditugaskan'][0])->toHaveKeys(['id', 'dwId']);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT status FROM dw_permintaan WHERE id=?'), [$pm['row']['id']])->status)->toBe('DISETUJUI');
    $aj = Modules::db('dw')->selectOne(dwSql('SELECT status, permintaan_id, putus_nota FROM dw_ajuan WHERE id=?'), [$r['data']['ditugaskan'][0]['id']]);
    expect($aj->status)->toBe('DISETUJUI')->and($aj->permintaan_id)->toBe($pm['row']['id'])->and($aj->putus_nota)->toBe('Ditugaskan dari permintaan head');

    // assigning Abi again that night holds him back instead of double-booking
    $r2 = dwPost(['action' => 'tugaskanDW', 'sesi' => $sesi, 'permintaanId' => $pm['row']['id'], 'dwIds' => ['DWmtxzw6fd369']]);
    expect($r2['data'])->toMatchArray(['saved' => true, 'masuk' => 0])->and($r2['data']['tertahan'])->toHaveCount(1);
});

it('refuses tugaskanDW to non-HRD', function () {
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2030-11-02', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-arif'));
    $r = dwPost(['action' => 'tugaskanDW', 'sesi' => dwSesi('u-arif'), 'permintaanId' => $pm['row']['id'], 'dwIds' => ['DWmtxzw6fd369']]);
    expect($r['ok'])->toBeFalse()->and($r['error'])->toStartWith('tidak_berhak:');
});

it('refuses hapusPermintaan of another division', function () {
    $pm = dwSvc()->savePermintaan(['divisi' => 'kitchen', 'tgl' => '2030-12-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-rizkiarfan'));
    $r = dwPost(['action' => 'hapusPermintaan', 'sesi' => dwSesi('u-arif'), 'id' => $pm['row']['id']]);
    expect($r['ok'])->toBeFalse()->and($r['error'])->toBe('tidak_berhak: Permintaan ini bukan milik divisi Anda.');
});

it('deletes permintaan of the own division, keeping the promised shifts unlinked', function () {
    $sesi = dwSesi('u-arif');
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2030-12-02', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-arif'));
    $as = dwSvc()->assignDw($pm['row']['id'], ['DWmtxzw6fd369'], dwName('u-rizkiarfan'));
    expect($as['masuk'])->toBe(1);
    $ajId = $as['ditugaskan'][0]['id'];

    expect(dwPost(['action' => 'hapusPermintaan', 'sesi' => $sesi, 'id' => $pm['row']['id']])['data'])->toMatchArray(['deleted' => true, 'id' => $pm['row']['id']]);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT COUNT(*) n FROM dw_permintaan WHERE id=?'), [$pm['row']['id']])->n)->toBe(0);
    // the shift survives, only the link is released
    $aj = Modules::db('dw')->selectOne(dwSql('SELECT status, permintaan_id FROM dw_ajuan WHERE id=?'), [$ajId]);
    expect($aj->status)->toBe('DISETUJUI')->and($aj->permintaan_id)->toBe('');
});

/*
 * Cluster (2): simpanHadir, gantiOrang, tandaiBayar, simpanSetting,
 * kosongkanSemua. Same harness rules as above (one identity per test,
 * cross-identity setup through DwService).
 */

function dwSetuju(string $dwId, string $tgl, string $divisi = 'bar'): string
{
    $r = dwSvc()->saveAjuan(['dwId' => $dwId, 'tgl' => $tgl, 'm' => '18:00', 's' => '23:00', 'divisi' => $divisi], dwName('u-rizkiarfan'));
    dwSvc()->decideAjuan($r['row']['id'], 'DISETUJUI', '', dwName('u-rizkiarfan'));

    return $r['row']['id'];
}

it('validates hadir input in legacy order', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-01');
    expect(dwPost(['action' => 'simpanHadir', 'sesi' => $sesi, 'id' => $aj, 'hadir' => 'HILANG'])['error'])->toBe('Kehadiran tidak dikenal: HILANG');
    expect(dwPost(['action' => 'simpanHadir', 'sesi' => $sesi, 'id' => 'AJtidakada', 'hadir' => 'HADIR'])['error'])->toBe('Ajuan tidak ditemukan: AJtidakada');

    $tunggu = dwSvc()->saveAjuan(['dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-03-02', 'm' => '18:00', 's' => '23:00'], dwName('u-rizkiarfan'))['row']['id'];
    expect(dwPost(['action' => 'simpanHadir', 'sesi' => $sesi, 'id' => $tunggu, 'hadir' => 'HADIR'])['error'])
        ->toBe('Kehadiran hanya bisa dicatat untuk shift yang sudah disetujui.');
});

it('marks attendance as HRD and resets it back to unconfirmed', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-03');

    expect(dwPost(['action' => 'simpanHadir', 'sesi' => $sesi, 'id' => $aj, 'hadir' => 'TELAT', 'nota' => 'macet'])['data'])
        ->toMatchArray(['saved' => true, 'id' => $aj]);
    $row = Modules::db('dw')->selectOne(dwSql('SELECT hadir, hadir_nota, hadir_oleh, hadir_at FROM dw_ajuan WHERE id=?'), [$aj]);
    expect($row->hadir)->toBe('TELAT')->and($row->hadir_nota)->toBe('macet')
        ->and($row->hadir_oleh)->toBe(dwName('u-rizkiarfan'))->and($row->hadir_at)->toBeGreaterThan(0);

    // '' is not "present" — it returns the row to "not confirmed yet"
    expect(dwPost(['action' => 'simpanHadir', 'sesi' => $sesi, 'id' => $aj, 'hadir' => ''])['data'])->toMatchArray(['saved' => true]);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT hadir FROM dw_ajuan WHERE id=?'), [$aj])->hadir)->toBe('');
});

it('lets the head of the row division confirm attendance', function () {
    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-04', 'bar');
    expect(dwPost(['action' => 'simpanHadir', 'sesi' => dwSesi('u-arif'), 'id' => $aj, 'hadir' => 'HADIR'])['data'])
        ->toMatchArray(['saved' => true, 'id' => $aj]);
});

it('refuses attendance confirmation for another division', function () {
    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-04', 'bar');
    $r = dwPost(['action' => 'simpanHadir', 'sesi' => dwSesi('u-mella'), 'id' => $aj, 'hadir' => 'HADIR']);
    expect($r['ok'])->toBeFalse()->and($r['error'])->toStartWith('tidak_berhak:');
});

it('validates gantiOrang input in legacy order', function () {
    $sesi = dwSesi('u-rizkiarfan');
    expect(dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => '', 'dwBaru' => ''])['error'])->toBe('Butuh shift dan penggantinya');
    expect(dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => 'AJtidakada', 'dwBaru' => 'DWmtxzw6fd369'])['error'])->toBe('Shift tidak ditemukan: AJtidakada');

    $tunggu = dwSvc()->saveAjuan(['dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-03-05', 'm' => '18:00', 's' => '23:00'], dwName('u-rizkiarfan'))['row']['id'];
    expect(dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => $tunggu, 'dwBaru' => 'DWmty9fok2781'])['error'])
        ->toBe('Hanya shift yang sudah disetujui yang bisa diganti orangnya.');

    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-06');
    expect(dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => $aj, 'dwBaru' => 'DWmtxzw6fd369'])['error'])->toBe('Penggantinya orang yang sama.');
    expect(dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => $aj, 'dwBaru' => 'DWtidakada'])['error'])->toBe('Pengganti tidak ditemukan: DWtidakada');

    $nonaktif = Modules::db('dw')->selectOne(dwSql("SELECT `id`,`nama` FROM `dw_pekerja` WHERE `status`='NONAKTIF' LIMIT 1"));
    expect(dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => $aj, 'dwBaru' => $nonaktif->id])['error'])
        ->toBe($nonaktif->nama.' berstatus tidak aktif dan tidak bisa dijadwalkan.');
});

it('refuses a replacement whose own hours overlap', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-07');
    // the replacement is already booked that night elsewhere
    dwSetuju('DWmty9fok2781', '2031-03-07', 'kitchen');

    $r = dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => $aj, 'dwBaru' => 'DWmty9fok2781']);
    expect($r['ok'])->toBeFalse()->and($r['error'])
        ->toBe(dwSvc()->namaPekerja('DWmty9fok2781').' sudah punya shift yang jamnya bertindih di tanggal itu (2031-03-07 18:00-23:00).');
});

it('replaces the worker in one transaction, keeping history and the request link', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2031-03-08', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-arif'));
    $as = dwSvc()->assignDw($pm['row']['id'], ['DWmtxzw6fd369'], dwName('u-rizkiarfan'));
    $aj = $as['ditugaskan'][0]['id'];

    $r = dwPost(['action' => 'gantiOrang', 'sesi' => $sesi, 'id' => $aj, 'dwBaru' => 'DWmty9fok2781', 'nota' => 'sakit']);
    expect($r['data']['saved'])->toBeTrue();
    expect($r['data']['lama'])->toMatchArray(['id' => $aj, 'dwId' => 'DWmtxzw6fd369', 'hadir' => 'ALFA', 'status' => 'DISETUJUI']);
    expect($r['data']['lama']['hadirNota'])->toContain('Digantikan '.dwSvc()->namaPekerja('DWmty9fok2781'));
    expect($r['data']['baru'])->toMatchArray(['dwId' => 'DWmty9fok2781', 'tgl' => '2031-03-08', 'hadir' => 'HADIR', 'status' => 'DISETUJUI', 'permintaanId' => $pm['row']['id']]);
    expect($r['data']['baru']['hadirOleh'])->toBe(dwName('u-rizkiarfan'));

    // the request still counts its staff — the link was copied, not dropped
    expect(dwSvc()->permintaanTerpenuhi($pm['row']['id']))->toBe(2);
});

it('lets the head of the row division replace, never another division', function () {
    $aj = dwSetuju('DWmtxzw6fd369', '2031-03-09', 'bar');
    $r = dwPost(['action' => 'gantiOrang', 'sesi' => dwSesi('u-arif'), 'id' => $aj, 'dwBaru' => 'DWmty9fok2781']);
    expect($r['data']['saved'])->toBeTrue();

    $aj2 = dwSetuju('DWmtxzw6fd369', '2031-03-10', 'kitchen');
    $r2 = dwPost(['action' => 'gantiOrang', 'sesi' => dwSesi('u-arif'), 'id' => $aj2, 'dwBaru' => 'DWmty9fok2781']);
    expect($r2['ok'])->toBeFalse()->and($r2['error'])->toStartWith('tidak_berhak:');
});

it('validates tandaiBayar input in legacy order', function () {
    $sesi = dwSesi('u-rizkiarfan');
    expect(dwPost(['action' => 'tandaiBayar', 'sesi' => $sesi, 'senin' => '', 'kunci' => ''])['error'])->toBe('Penanda pembayaran butuh minggu & tujuan');
    expect(dwPost(['action' => 'tandaiBayar', 'sesi' => $sesi, 'senin' => '2031-13-01', 'kunci' => 'BANK::1', 'nyala' => true])['error'])->toBe('Penanda pembayaran butuh minggu & tujuan');
});

it('refuses payment ticks to non-HRD', function () {
    expect(dwPost(['action' => 'tandaiBayar', 'sesi' => dwSesi('u-arif'), 'senin' => '2031-03-09', 'kunci' => 'BANK::1', 'nyala' => true])['error'])->toStartWith('tidak_berhak:');
});

it('ticks payment marks and writes only that key', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $sebelum = (array) dwSvc()->setting();
    expect(dwPost(['action' => 'tandaiBayar', 'sesi' => $sesi, 'senin' => '2031-03-09', 'kunci' => 'BANK::1', 'nyala' => true])['data'])
        ->toMatchArray(['saved' => true, 'kunci' => '2031-03-09|BANK::1', 'nyala' => 1]);
    $st = dwSvc()->setting();
    $tandai = (array) $st->bayarLunas;
    expect((array) $tandai['2031-03-09|BANK::1'])->toMatchArray(['oleh' => dwName('u-rizkiarfan')]);
    // every other setting key survived the read-modify-write untouched
    foreach ($sebelum as $k => $v) {
        if ($k === 'bayarLunas') {
            continue;
        }
        expect(json_encode($st->$k))->toBe(json_encode($v));
    }

    expect(dwPost(['action' => 'tandaiBayar', 'sesi' => $sesi, 'senin' => '2031-03-09', 'kunci' => 'BANK::1'])['data'])
        ->toMatchArray(['saved' => true, 'nyala' => 0]);
    $tandaiAkhir = (array) (dwSvc()->setting()->bayarLunas ?? []);
    expect(isset($tandaiAkhir['2031-03-09|BANK::1']))->toBeFalse();
});

it('rejects an invalid setting payload', function () {
    expect(dwPost(['action' => 'simpanSetting', 'sesi' => dwSesi('u-rizkiarfan'), 'data' => null])['error'])->toBe('Payload setting kosong/invalid');
});

it('refuses setting writes to non-HRD', function () {
    expect(dwPost(['action' => 'simpanSetting', 'sesi' => dwSesi('u-arif'), 'data' => ['tarif' => []]])['error'])->toStartWith('tidak_berhak:');
});

it('keeps hr and akses from the stored value for non-admin HRD', function () {
    // u-arif (head of bar, not a module admin) becomes HRD through the list
    dwSvc()->saveSetting(['tarif' => ['Bar Helper' => 1], 'hr' => ['u-arif'], 'akses' => ['menu' => 'x']], dwName('u-admin'), true);

    $r = dwPost(['action' => 'simpanSetting', 'sesi' => dwSesi('u-arif'), 'data' => [
        'tarif' => ['Bar Helper' => 2], 'hr' => ['u-nobody'], 'akses' => ['menu' => 'y'],
    ]]);
    expect($r['data'])->toMatchArray(['saved' => true]);

    $st = dwSvc()->setting();
    expect((array) $st->hr)->toBe(['u-arif'])
        ->and((array) $st->akses)->toBe(['menu' => 'x'])
        ->and((array) $st->tarif)->toBe(['Bar Helper' => 2]);
});

it('lets a module admin change hr and akses', function () {
    $r = dwPost(['action' => 'simpanSetting', 'sesi' => dwSesi('u-rizkiarfan'), 'data' => [
        'tarif' => ['Bar Helper' => 3], 'hr' => ['u-rizkiarfan'], 'akses' => ['menu' => 'z'],
    ]]);
    expect($r['data'])->toMatchArray(['saved' => true]);
    $st = dwSvc()->setting();
    expect((array) $st->hr)->toBe(['u-rizkiarfan'])->and((array) $st->akses)->toBe(['menu' => 'z']);
});

it('refuses the wipe to non-admins', function () {
    expect(dwPost(['action' => 'kosongkanSemua', 'sesi' => dwSesi('u-arif'), 'konfirmasi' => 'HAPUS SEMUA'])['error'])
        ->toBe('tidak_berhak: Mengosongkan seluruh data modul hanya bisa dilakukan admin modul Daily Worker.');
});

it('refuses the wipe without the keyword', function () {
    expect(dwPost(['action' => 'kosongkanSemua', 'sesi' => dwSesi('u-rizkiarfan'), 'konfirmasi' => 'hapus'])['error'])
        ->toBe('Konfirmasi tidak cocok — pengosongan dibatalkan.');
});

it('empties requests and assignments, keeping the talent pool unless asked', function () {
    $sesi = dwSesi('u-rizkiarfan');
    $wid = dwSvc()->savePekerja(['nama' => 'Bersih', 'hp' => '080000000051'], dwName('u-rizkiarfan'))['id'];
    $pm = dwSvc()->savePermintaan(['divisi' => 'bar', 'tgl' => '2031-04-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], dwName('u-arif'))['row']['id'];
    $aj = dwSetuju($wid, '2031-04-01');

    $r = dwPost(['action' => 'kosongkanSemua', 'sesi' => $sesi, 'konfirmasi' => 'HAPUS SEMUA']);
    expect($r['data'])->toMatchArray(['cleared' => true, 'ikutPekerja' => false, 'pekerja' => 0, 'oleh' => dwName('u-rizkiarfan')]);
    expect($r['data']['ajuan'])->toBeGreaterThanOrEqual(1)->and($r['data']['permintaan'])->toBeGreaterThanOrEqual(1);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT COUNT(*) n FROM dw_ajuan WHERE id=?'), [$aj])->n)->toBe(0);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT COUNT(*) n FROM dw_permintaan WHERE id=?'), [$pm])->n)->toBe(0);
    // talent pool survives a plain wipe
    expect(Modules::db('dw')->selectOne(dwSql('SELECT COUNT(*) n FROM dw_pekerja WHERE id=?'), [$wid])->n)->toBe(1);

    $wid2 = dwSvc()->savePekerja(['nama' => 'Bersih Dua', 'hp' => '080000000052'], dwName('u-rizkiarfan'))['id'];
    $r2 = dwPost(['action' => 'kosongkanSemua', 'sesi' => $sesi, 'konfirmasi' => 'HAPUS SEMUA', 'pekerja' => 1]);
    expect($r2['data'])->toMatchArray(['cleared' => true, 'ikutPekerja' => true]);
    expect(Modules::db('dw')->selectOne(dwSql('SELECT COUNT(*) n FROM dw_pekerja WHERE id=?'), [$wid2])->n)->toBe(0);
});
