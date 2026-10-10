<?php

/* u-novi holds module `finance` (not admin); u-adit has no finance. */

it('requires login and the finance module', function () {
    $this->getJson('/api/v1/finance/tagihan')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/finance/tagihan')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('validates a new tagihan exactly like tg_simpan', function () {
    $token = loginAs(officeUser('u-novi'));

    $this->withToken($token)->postJson('/api/v1/finance/tagihan', ['nama' => ''])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_request')
        ->assertJsonPath('error.message', 'Nama tagihan wajib diisi.');

    $this->withToken($token)->postJson('/api/v1/finance/tagihan', ['nama' => 'Wifi', 'nominal' => -100])
        ->assertStatus(422)->assertJsonPath('error.message', 'Nominal tidak boleh minus.');

    $this->withToken($token)->postJson('/api/v1/finance/tagihan', ['nama' => 'Wifi', 'siklus' => 5])
        ->assertStatus(422)->assertJsonPath('error.message', 'Siklus tidak dikenal: 5 bulan.');

    // `mulai` may be empty, but a filled-but-invalid date is rejected, never emptied.
    $this->withToken($token)->postJson('/api/v1/finance/tagihan', ['nama' => 'Wifi', 'mulai' => '2026-02-31'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tanggal jatuh tempo pertama tidak sah: 2026-02-31');

    $this->withToken($token)->postJson('/api/v1/finance/tagihan', ['nama' => 'Wifi Tanpa Tanggal', 'mulai' => ''])
        ->assertCreated()->assertJsonPath('data.mulai', '');
});

it('records the session user as pencatat, never the body', function () {
    $token = loginAs(officeUser('u-novi'));
    $me = officeUser('u-novi')['name'];

    $res = $this->withToken($token)->postJson('/api/v1/finance/tagihan',
        ['nama' => 'Wifi CK', 'kategori' => 'Internet', 'nominal' => 150000, 'siklus' => 1,
            'mulai' => '2026-10-05', 'metode' => 'Transfer', 'catatan' => 'x', 'oleh' => 'spoof'])
        ->assertCreated()->assertJsonPath('data.nama', 'Wifi CK')
        ->assertJsonPath('data.nominal', 150000)->assertJsonPath('data.siklus', 1)
        ->assertJsonPath('data.mulai', '2026-10-05')->assertJsonPath('data.aktif', true)
        ->assertJsonPath('data.dibuatOleh', $me);
    $id = $res->json('data.id');

    $list = $this->withToken($token)->getJson('/api/v1/finance/tagihan')->assertOk()
        ->assertJsonPath('data.tagihan.0.nama', 'Wifi CK');
    expect(collect($list->json('data.tagihan'))->firstWhere('id', $id)['dibuatOleh'])->toBe($me);
});

it('edits a tagihan without touching its payment history', function () {
    $token = loginAs(officeUser('u-novi'));
    $id = $this->withToken($token)->postJson('/api/v1/finance/tagihan',
        ['nama' => 'Spotify', 'nominal' => 50000, 'siklus' => 1, 'mulai' => '2026-10-01'])
        ->assertCreated()->json('data.id');

    $this->withToken($token)->postJson("/api/v1/finance/tagihan/$id/payments",
        ['periode' => '2026-10-01', 'tglBayar' => '2026-10-02', 'nominal' => 50000])
        ->assertCreated();

    // PATCH is partial: fields not sent keep their values (legacy defaults would wipe them).
    $this->withToken($token)->patchJson("/api/v1/finance/tagihan/$id", ['nominal' => 65000])
        ->assertOk()->assertJsonPath('data.nominal', 65000)
        ->assertJsonPath('data.nama', 'Spotify')->assertJsonPath('data.mulai', '2026-10-01');

    // The recorded payment keeps the nominal AS OF THEN, and the total reads it.
    $bayar = $this->withToken($token)->getJson('/api/v1/finance/tagihan')->assertOk()->json('data.bayar');
    expect($bayar)->toHaveCount(1)->and($bayar[0]['nominal'])->toBe(50000);

    $this->withToken($token)->patchJson('/api/v1/finance/tagihan/999999', ['nama' => 'x'])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('pays one due date exactly once, until it is cancelled', function () {
    $token = loginAs(officeUser('u-novi'));
    $me = officeUser('u-novi')['name'];
    $id = $this->withToken($token)->postJson('/api/v1/finance/tagihan',
        ['nama' => 'Wifi Biznet', 'nominal' => 300000, 'siklus' => 1, 'mulai' => '2026-10-10'])
        ->assertCreated()->json('data.id');

    $this->withToken($token)->postJson("/api/v1/finance/tagihan/$id/payments",
        ['periode' => '', 'tglBayar' => '2026-10-10', 'nominal' => 300000])
        ->assertStatus(422)->assertJsonPath('error.message', 'Periode (jatuh tempo yang dibayar) wajib diisi.');
    $this->withToken($token)->postJson("/api/v1/finance/tagihan/$id/payments",
        ['periode' => '2026-10-10', 'tglBayar' => '2026-10-10', 'nominal' => 0])
        ->assertStatus(422)->assertJsonPath('error.message', 'Nominal yang dibayar wajib diisi.');

    $pid = $this->withToken($token)->postJson("/api/v1/finance/tagihan/$id/payments",
        ['periode' => '2026-10-10', 'tglBayar' => '2026-10-10', 'nominal' => 300000, 'oleh' => 'spoof'])
        ->assertCreated()->assertJsonPath('data.nominal', 300000)
        ->assertJsonPath('data.periode', '2026-10-10')->assertJsonPath('data.oleh', $me)
        ->json('data.id');

    // The same due date cannot be paid twice (the server guard, not the screen).
    $this->withToken($token)->postJson("/api/v1/finance/tagihan/$id/payments",
        ['periode' => '2026-10-10', 'tglBayar' => '2026-10-11', 'nominal' => 300000])
        ->assertStatus(422)->assertJsonFragment(['code' => 'invalid_request']);

    // Cancelling needs a reason, works once, and frees the due date again.
    $this->withToken($token)->postJson("/api/v1/finance/tagihan/payments/$pid/cancel", [])
        ->assertStatus(422)->assertJsonPath('error.message', 'Alasan pembatalan wajib diisi.');
    $this->withToken($token)->postJson("/api/v1/finance/tagihan/payments/$pid/cancel", ['alasan' => 'salah tanggal'])
        ->assertOk()->assertJsonPath('data.batalAlasan', 'salah tanggal')
        ->assertJsonPath('data.batalOleh', $me);
    $this->withToken($token)->postJson("/api/v1/finance/tagihan/payments/$pid/cancel", ['alasan' => 'lagi'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Pembayaran tidak ditemukan atau sudah dibatalkan.');

    $this->withToken($token)->postJson("/api/v1/finance/tagihan/$id/payments",
        ['periode' => '2026-10-10', 'tglBayar' => '2026-10-12', 'nominal' => 300000])
        ->assertCreated();

    // Total "sudah dibayar" = live rows only, with the nominal as paid.
    $bayar = collect($this->withToken($token)->getJson('/api/v1/finance/tagihan')->assertOk()->json('data.bayar'))
        ->where('tagihanId', $id);
    expect($bayar->where('batalAt', null)->sum('nominal'))->toBe(300000)
        ->and($bayar->count())->toBe(2);

    $this->withToken($token)->postJson('/api/v1/finance/tagihan/payments/999999/cancel', ['alasan' => 'x'])
        ->assertStatus(404);
});

it('deactivates a tagihan instead of deleting it', function () {
    $token = loginAs(officeUser('u-novi'));
    $id = $this->withToken($token)->postJson('/api/v1/finance/tagihan', ['nama' => 'Lama'])
        ->assertCreated()->json('data.id');

    $this->withToken($token)->putJson("/api/v1/finance/tagihan/$id/active", ['aktif' => false])
        ->assertOk()->assertJsonPath('data.aktif', false)->assertJsonPath('data.id', $id);

    $row = collect($this->withToken($token)->getJson('/api/v1/finance/tagihan')->assertOk()->json('data.tagihan'))
        ->firstWhere('id', $id);
    expect($row['aktif'])->toBe(false);

    $this->withToken($token)->putJson("/api/v1/finance/tagihan/$id/active", ['aktif' => true])
        ->assertOk()->assertJsonPath('data.aktif', true);

    // No DELETE route anywhere on this resource.
    $this->withToken($token)->deleteJson("/api/v1/finance/tagihan/$id")->assertStatus(405);
});
