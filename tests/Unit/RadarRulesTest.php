<?php

use App\Modules\Radar\Services\RadarRules as R;

/*
 * Radar's rules (laksamana-office deploy/radar) as pure functions: the WIB
 * shift of zoned instants, "only what will happen" per source, the joined
 * agenda order, the narrow reservation shape and the server-side money
 * filter, and promo status by date. No DB, no clock — runs in CI.
 */

it('shifts a zoned instant to WIB and keeps a plain local value as is (pecahWaktu)', function () {
    expect(R::splitTime('2026-07-23T12:00:00.000Z'))->toBe(['tgl' => '2026-07-23', 'jam' => '19:00'])
        // 00:30 WIB is stored as 17:30Z the day before: it must land on the right day
        ->and(R::splitTime('2026-07-22T17:30:00Z'))->toBe(['tgl' => '2026-07-23', 'jam' => '00:30'])
        ->and(R::splitTime('2026-07-23T19:00:00+07:00'))->toBe(['tgl' => '2026-07-23', 'jam' => '19:00'])
        ->and(R::splitTime('2026-08-09'))->toBe(['tgl' => '2026-08-09', 'jam' => ''])
        ->and(R::splitTime('2026-08-09 18:30'))->toBe(['tgl' => '2026-08-09', 'jam' => '18:30'])
        ->and(R::splitTime(''))->toBeNull()
        ->and(R::splitTime('bukan tanggal'))->toBeNull();
});

it('keeps only what will happen, with each module its own vocabulary (agPasti)', function () {
    expect(R::willHappen(['sumber' => 'mkt', 'status' => 'Deal']))->toBeTrue()
        ->and(R::willHappen(['sumber' => 'mkt', 'status' => ' event done ']))->toBeTrue()
        ->and(R::willHappen(['sumber' => 'mkt', 'status' => 'Negotiation']))->toBeFalse()
        ->and(R::willHappen(['sumber' => 'mkt', 'status' => 'Upcoming']))->toBeFalse()
        ->and(R::willHappen(['sumber' => 'evt', 'status' => 'Upcoming']))->toBeTrue()
        ->and(R::willHappen(['sumber' => 'evt', 'status' => 'Draft']))->toBeFalse()
        ->and(R::willHappen(['sumber' => 'evt', 'status' => 'Deal']))->toBeFalse()
        // VIP: no whitelist (bookings, not a pipeline) — only cancelled / no-show are out
        ->and(R::willHappen(['sumber' => 'vip', 'status' => 'Status baru apa pun']))->toBeTrue()
        ->and(R::willHappen(['sumber' => 'vip', 'status' => 'No-show']))->toBeFalse();
});

it('joins the three shapes, drops cancelled VIP and out-of-range rows, and sorts no-time items last', function () {
    $mkt = [
        ['id' => 'm1', 'tanggal' => '2026-10-02', 'nama' => 'Gathering', 'status' => 'Deal', 'pax' => '120', 'jenis' => 'Gathering',
            'detail' => ['tamuDatang' => '', 'area' => 'Lt 2', 'fbFormat' => 'Prasmanan']],
        ['id' => 'm2', 'tanggal' => '2026-10-02', 'nama' => 'Lead', 'status' => 'Lead'],
        ['id' => 'm3', 'tanggal' => '2026-12-01', 'nama' => 'Jauh', 'status' => 'Deal'],
    ];
    $vip = [
        ['id' => 'v1', 'tanggal' => '2026-10-02', 'nama' => 'VIP Rina', 'jamMulai' => '18:00', 'meja' => ['R1', 'R2'], 'paxMin' => 8, 'paxMax' => 12],
        ['id' => 'v2', 'tanggal' => '2026-10-02', 'nama' => 'Batal', 'batalAt' => 123],
    ];
    $evt = [
        ['id' => 'e1', 'title' => 'Live Music', 'start_datetime' => '2026-10-02T12:00:00.000Z', 'end_datetime' => '2026-10-02T15:00:00Z',
            'status' => 'Upcoming', 'capacity' => 80, 'venue' => 'Main', 'category' => 'Music'],
        ['id' => 'e2', 'title' => 'Draft', 'start_datetime' => '2026-10-02T10:00:00Z', 'status' => 'Draft'],
    ];

    $a = R::agenda($mkt, $vip, $evt, '2026-10-01', '2026-10-31');

    expect(array_column($a, 'id'))->toBe(['v1', 'e1', 'm1'])
        ->and($a[0])->toMatchArray(['sumber' => 'vip', 'tempat' => 'R1, R2', 'pax' => 12, 'paxMin' => 8, 'paxMax' => 12])
        ->and($a[1])->toMatchArray(['sumber' => 'evt', 'jam' => '19:00', 'selesai' => '22:00', 'pax' => 80, 'tempat' => 'Main'])
        ->and($a[2])->toMatchArray(['sumber' => 'mkt', 'pax' => 120, 'fb' => 'Prasmanan', 'tempat' => 'Lt 2', 'jam' => '']);
});

it('reads the VIP guest estimate from its upper bound (paxVipAngka)', function () {
    expect(R::vipPax(['paxMin' => 8, 'paxMax' => 12]))->toBe(12)
        ->and(R::vipPax(['paxMin' => 8]))->toBe(8)
        ->and(R::vipPax([]))->toBe(0);
});

it('narrows a reservation: no proofs or transfer data, DP only for those allowed', function () {
    $r = ['id' => 'r1', 'date' => '2026-10-02', 'name' => 'A', 'pax' => 4, 'phone' => '08', 'dpAmount' => 500000,
        'dpStatus' => 'Sudah', 'dps' => [['proofData' => 'data:x']], 'tfName' => 'A', 'dpProofData' => '@f:x', 'log' => []];

    expect(R::reservation($r, false))->toBe(['id' => 'r1', 'date' => '2026-10-02', 'name' => 'A', 'pax' => 4, 'phone' => '08'])
        ->and(R::reservation($r, true))->toMatchArray(['dpAmount' => 500000, 'dpStatus' => 'Sudah'])
        ->and(R::reservation($r, true))->not->toHaveKeys(['dps', 'tfName', 'dpProofData', 'log']);
});

it('filters money out of an event detail for non-Heads, and bill lines for everyone', function () {
    $d = [
        'kNote' => 'tanpa MSG', 'depositNominal' => 5000000, 'dealPax' => 150000, 'hargaPaket' => 1,
        'itemTambah' => [['nama' => 'Kue', 'nominal' => 300000]],
        'rincian' => [['desc' => 'Sewa', 'harga' => 1]], 'tagihanLain' => [['desc' => 'X', 'jumlah' => 2]],
        'layoutImgs' => [['key' => 'lp_a.png', 'name' => 'a.png']],
    ];

    $non = R::detailForBoard($d, false);
    expect(array_keys($non))->toBe(['kNote', 'itemTambah', 'layoutImgs'])
        ->and($non['itemTambah'])->toBe([['nama' => 'Kue']]);

    $head = R::detailForBoard($d, true);
    expect($head)->toHaveKeys(['depositNominal', 'dealPax', 'hargaPaket'])
        ->and($head['itemTambah'][0]['nominal'])->toBe(300000)
        ->and($head)->not->toHaveKeys(['rincian', 'tagihanLain'])
        ->and(R::attachmentKeys($head))->toBe(['lp_a.png']);
});

it('reads Head as a whole word in the Tim column', function () {
    expect(R::isHead('Head Bar'))->toBeTrue()
        ->and(R::isHead('Marketing, Head'))->toBeTrue()
        ->and(R::isHead('Overhead'))->toBeFalse()
        ->and(R::isHead('Headhunter'))->toBeFalse()
        ->and(R::isHead(null))->toBeFalse();
});

it('computes promo status from dates and lists running then upcoming by start', function () {
    $today = '2026-10-10';
    $all = [
        ['id' => 'a', 'nama' => 'Nanti jauh', 'mulai' => '2026-11-01', 'selesai' => '2026-11-30'],
        ['id' => 'b', 'nama' => 'Jalan', 'mulai' => '2026-10-01', 'selesai' => '2026-10-31', 'budget' => 9],
        ['id' => 'c', 'nama' => 'Nanti dekat', 'mulai' => '2026-10-12'],
        ['id' => 'd', 'nama' => 'Habis', 'mulai' => '2026-09-01', 'selesai' => '2026-09-30'],
        ['id' => 'e', 'nama' => 'Jeda', 'paused' => true],
        ['id' => 'f', 'nama' => 'Tanpa tanggal'],
    ];
    $p = R::promos($all, $today);

    expect(array_column($p['promos'], 'id'))->toBe(['f', 'b', 'c', 'a'])
        ->and($p['arsip'])->toBe(2)
        ->and($p['promos'][1]['status'])->toBe('running')
        ->and($p['promos'][1])->not->toHaveKey('budget')
        ->and(R::promoStatus(['selesai' => '2026-10-10'], $today))->toBe('running');
});
