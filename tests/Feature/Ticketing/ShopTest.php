<?php

use App\Modules\Ticketing\Services\ShopMail;
use App\Support\Modules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * Public ticket shop on the restored EMS copy: event e2 is Upcoming with a seat map;
 * id02n81bj / id0bogbkq are VIP seats (price 3), id06hjcvx a table (furniture),
 * idt97tumy the unseated Reguler class (price 2). No test reaches Xendit or SMTP.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    config(['laksamana.ticketing.xendit_mock' => true, 'laksamana.ticketing.xendit_callback' => 'cbtest',
        'laksamana.ticketing.admin_fee' => 5000]);
});

function tixDb()
{
    return Modules::db('ticketing');
}

function tixPost(array $body, array $server = []): TestResponse
{
    return test()->call('POST', '/ticketing-api/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'] + $server, json_encode($body));
}

/** A Buyer with a live session; returns the session token. */
function tixBuyer(string $id = 'tu_test1'): string
{
    tixDb()->insert("INSERT INTO tix_users (id,email,pass_hash,name,phone,created_at) VALUES (?,?,'x','Test Buyer','0811',0)", [$id, "$id@example.test"]);
    tixDb()->insert('INSERT INTO tix_sessions (token,user_id,expires_at,created_at) VALUES (?,?,?,0)', ["s_$id", $id, 4102444800000]);

    return "s_$id";
}

/** A Pending order paid through Xendit (not simulated); returns its id. */
function tixPendingOrder(string $invoiceId): string
{
    $o = ['id' => 'ord_test1', 'event_id' => 'e2', 'buyer_name' => 'T', 'phone' => '1', 'email' => 't@example.test',
        'subtotal' => 2, 'fee' => 0, 'total' => 2, 'payment_status' => 'Pending', 'payment_ref' => 'LMTEST01',
        'items' => [['seat_id' => '', 'label' => '', 'tier' => 'Reguler', 'class_id' => 'idt97tumy', 'kind' => 'general', 'capacity' => 1, 'price' => 2]],
        'access_token' => 'acc1', 'expires_at' => '2099-01-01T00:00:00+00:00', 'payment' => ['invoice_id' => $invoiceId]];
    tixDb()->insert("INSERT INTO orders (id,event_id,buyer_name,phone,email,total,payment_status,payment_ref,updated_at,created_at,data) VALUES ('ord_test1','e2','T','1','t@example.test',2,'Pending','LMTEST01',1,1,?)", [json_encode($o)]);

    return 'ord_test1';
}

it('legacy: lists only sellable events, with server-side remaining per class', function () {
    $d = $this->get('/ticketing-api/api.php')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'DENY')->json('data');
    expect(collect($d)->pluck('id'))->toContain('e2')->not->toContain('e1');
    $this->get('/ticketing-api/api.php?action=event&id=e1')->assertJsonPath('error', 'Event tidak ditemukan atau belum dijual.');
});

it('legacy: holds sellable seats only, marks them mine, and releases them', function () {
    $r = tixPost(['action' => 'hold', 'event_id' => 'e2', 'hold_token' => 'tkA', 'seats' => ['id02n81bj', 'id06hjcvx', 'nope']])->assertOk();
    expect($r->json('data.held'))->toBe(['id02n81bj'])
        ->and($r->json('data.ditolak'))->toBe([['id06hjcvx', 'bukan tempat yang dijual'], ['nope', 'tidak ada di denah']]);
    $map = collect($this->get('/ticketing-api/api.php?action=denah&id=e2&hold=tkA')->json('data'))->keyBy('id');
    expect($map['id02n81bj']['status'])->toBe('mine')->and($map['id06hjcvx']['status'])->toBe('perabot');
    tixPost(['action' => 'release', 'hold_token' => 'tkA'])->assertJsonPath('data.released', 1);
});

it('legacy: refuses bodies over 256 KB with 413', function () {
    $this->call('POST', '/ticketing-api/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('x', 256 * 1024 + 1))
        ->assertStatus(413)->assertExactJson(['ok' => false, 'error' => 'Permintaan terlalu besar.']);
});

it('webhook: 401 on a wrong token, 500 when Xendit cannot confirm, Paid once Xendit says PAID', function () {
    $oid = tixPendingOrder('inv_123');
    config(['laksamana.ticketing.xendit_secret' => 'xnd_development_test']);
    tixPost(['external_id' => $oid, 'status' => 'PAID'], ['HTTP_X_CALLBACK_TOKEN' => 'wrong'])->assertStatus(401)->assertJsonPath('error', 'Token callback salah.');

    Http::fake(['api.xendit.co/v2/invoices/inv_123' => Http::sequence()
        ->push(['status' => 'PENDING'])
        ->push(['status' => 'PAID', 'payment_method' => 'QR_CODE', 'paid_amount' => 2])]);
    tixPost(['external_id' => $oid, 'status' => 'PAID'], ['HTTP_X_CALLBACK_TOKEN' => 'cbtest'])->assertStatus(500)
        ->assertJsonPath('error', 'Xendit menyatakan invoice belum lunas (PENDING) — webhook diabaikan.');
    $before = (int) tixDb()->selectOne('SELECT COUNT(*) c FROM tickets')->c;
    tixPost(['external_id' => $oid, 'status' => 'PAID'], ['HTTP_X_CALLBACK_TOKEN' => 'cbtest'])->assertOk()->assertJsonPath('data.tiket', 1);

    $o = json_decode(tixDb()->selectOne('SELECT data FROM orders WHERE id = ?', [$oid])->data, true);
    expect($o['payment_status'])->toBe('Paid')->and($o['payment']['payment_method'])->toBe('QR_CODE')
        ->and($o['email_eticket'])->toBe(['ok' => false, 'sebab' => 'SMTP belum dikonfigurasi'])
        ->and((int) tixDb()->selectOne('SELECT COUNT(*) c FROM tickets')->c)->toBe($before + 1);
});

it('v1: checkout needs a Buyer, prices server-side, and pays through the simulated gateway', function () {
    $this->postJson('/api/v1/tickets/holds', ['event_id' => 'e2', 'hold_token' => 'tkV', 'seats' => ['id0bogbkq']])
        ->assertOk()->assertJsonPath('data.held', ['id0bogbkq']);
    $body = ['event_id' => 'e2', 'hold_token' => 'tkV', 'name' => 'Budi', 'email' => 'budi@example.test', 'phone' => '0812',
        'general' => [['class_id' => 'idt97tumy', 'qty' => 2]], 'total' => 1];
    $this->postJson('/api/v1/tickets/checkout', $body)->assertStatus(401)->assertJsonPath('error.code', 'buyer_required');

    $c = $this->withToken(tixBuyer())->postJson('/api/v1/tickets/checkout', $body)->assertCreated();
    // 3 (VIP seat) + 2×2 (Reguler) + 3 tickets × 5000 admin fee; the browser's `total` is ignored
    expect($c->json('data.total'))->toBe(15007);
    $ref = $c->json('data.ref');
    $tok = $c->json('data.access_token');

    $this->getJson("/api/v1/tickets/orders/$ref?token=wrong")->assertStatus(404);
    $this->getJson("/api/v1/tickets/orders/$ref?token=$tok")->assertOk()->assertJsonPath('data.status', 'Pending');
    $this->postJson("/api/v1/tickets/orders/$ref/simulate-payment", ['token' => $tok])->assertOk()->assertJsonPath('data.tiket', 3);
    $o = $this->getJson("/api/v1/tickets/orders/$ref", ['X-Order-Token' => $tok])->assertOk();
    expect($o->json('data.status'))->toBe('Paid')->and($o->json('data.tickets'))->toHaveCount(3);

    $pdf = $this->get("/api/v1/tickets/orders/$ref/eticket.pdf?token=$tok")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($pdf->getContent(), '%PDF-1.4'))->toBeTrue();
    $seat = collect($this->getJson('/api/v1/tickets/events/e2/seatmap')->json('data'))->firstWhere('id', 'id0bogbkq');
    expect($seat['status'])->toBe('sold');
});

it('v1: an unknown event is 404 and a hold without seats is 422', function () {
    $this->getJson('/api/v1/tickets/events/e1')->assertStatus(404);
    $this->postJson('/api/v1/tickets/holds', ['event_id' => 'e2', 'seats' => []])->assertStatus(422);
});

it('maintenance answers a payment webhook with 503 so Xendit retries', function () {
    config(['laksamana.modules.ticketing.maintenance' => true]);
    tixPost(['external_id' => 'x', 'status' => 'PAID'], ['HTTP_X_CALLBACK_TOKEN' => 'cbtest'])->assertStatus(503);
    $this->get('/ticketing-api/api.php?action=events')->assertOk()->assertJsonPath('ok', true);
});

it('v1 Buyers: register, log in, see me and my orders, log out', function () {
    $this->postJson('/api/v1/tickets/buyers', ['name' => 'Rina', 'email' => 'Rina@Example.test', 'password' => 'pendek'])->assertStatus(422);
    $reg = $this->postJson('/api/v1/tickets/buyers', ['name' => 'Rina', 'email' => 'Rina@Example.test', 'phone' => '0812', 'password' => 'rahasia123'])
        ->assertCreated()->assertJsonPath('data.user.email', 'rina@example.test');
    expect($reg->json('data.token'))->toBeString();
    $this->postJson('/api/v1/tickets/buyers', ['name' => 'Lagi', 'email' => 'rina@example.test', 'password' => 'rahasia123'])->assertStatus(409);
    $this->postJson('/api/v1/tickets/sessions', ['email' => 'rina@example.test', 'password' => 'salah1234'])->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
    $tok = $this->postJson('/api/v1/tickets/sessions', ['email' => 'RINA@example.test', 'password' => 'rahasia123'])->assertOk()->json('data.token');

    $this->withToken($tok)->getJson('/api/v1/tickets/me')->assertOk()->assertJsonPath('data.name', 'Rina');
    $this->withToken($tok)->getJson('/api/v1/tickets/me/orders')->assertOk()->assertJsonPath('data', []);
    $this->withToken($tok)->deleteJson('/api/v1/tickets/sessions')->assertOk();
    expect(tixDb()->selectOne('SELECT COUNT(*) c FROM tix_sessions WHERE token = ?', [$tok])->c)->toBe(0);
});

it('mail: the reset link and the paid e-ticket (with its PDF) go out through the ticketing mailer', function () {
    Mail::fake();
    config(['laksamana.ticketing.smtp_host' => 'smtp.example.test', 'laksamana.ticketing.smtp_user' => 'tiket@example.test']);
    tixBuyer();
    $this->postJson('/api/v1/tickets/password/forgot', ['email' => 'tu_test1@example.test'])->assertOk()->assertJsonPath('data.terkirim', true);
    Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->hasTo('tu_test1@example.test') && str_contains($m->body, '#reset/'));
    $reset = tixDb()->selectOne("SELECT token FROM tix_reset WHERE user_id = 'tu_test1'")->token;
    $this->postJson('/api/v1/tickets/password/reset', ['token' => $reset, 'password' => 'barubaru123'])->assertOk();
    expect(tixDb()->selectOne("SELECT COUNT(*) c FROM tix_sessions WHERE token = 's_tu_test1'")->c)->toBe(0);

    tixPendingOrder('SIM-LMTEST01');
    tixDb()->update("UPDATE orders SET data = JSON_SET(data, '$.email', 'pembeli@example.test') WHERE id = 'ord_test1'");
    tixPost(['external_id' => 'ord_test1', 'status' => 'PAID'], ['HTTP_X_CALLBACK_TOKEN' => 'cbtest'])->assertOk();
    Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->hasTo('pembeli@example.test')
        && count($m->files) === 1 && str_starts_with($m->files[0]['isi'], '%PDF-1.4') && $m->files[0]['nama'] === 'E-Ticket-LMTEST01.pdf');
    $o = json_decode(tixDb()->selectOne("SELECT data FROM orders WHERE id = 'ord_test1'")->data, true);
    expect($o['email_eticket']['ok'])->toBeTrue()->and($o['email_eticket']['pdf'])->toBeTrue();
});
