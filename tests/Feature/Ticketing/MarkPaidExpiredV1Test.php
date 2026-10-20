<?php

use App\Modules\Ticketing\Services\ShopMail;
use App\Modules\Ticketing\Services\TicketShop;
use App\Support\Modules;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

/*
 * api#306: the `tix` lock no longer covers the PDF + SMTP, cancel and the
 * sweeps write inside it, and a late payment on an Expired order re-checks
 * seats/quota — clash means a refund marker, never tickets.
 *
 * Fixtures ride on event e1 (present in every restored copy): priced,
 * Available seats id0frwszv (K1) and s10 (T10) with no live tickets/holds.
 */
beforeEach(function () {
    config(['laksamana.ticketing.xendit_mock' => true, 'laksamana.ticketing.xendit_callback' => 'cbtest',
        'laksamana.ticketing.admin_fee' => 5000]);
});

function expDb()
{
    return Modules::db('ticketing');
}

function expOrder(string $id, string $status, array $items, int $total = 150000): void
{
    $o = ['id' => $id, 'event_id' => 'e1', 'buyer_name' => 'T', 'phone' => '1', 'email' => 't@example.test',
        'subtotal' => $total, 'fee' => 0, 'total' => $total, 'payment_status' => $status, 'payment_ref' => 'LM'.$id,
        'items' => $items, 'access_token' => 'acc1', 'expires_at' => '2020-01-01T00:00:00+00:00',
        'payment' => ['invoice_id' => 'SIM-'.$id]];
    expDb()->insert(tixSql("INSERT INTO orders (id,event_id,buyer_name,phone,email,total,payment_status,payment_ref,updated_at,created_at,data) VALUES ('$id','e1','T','1','t@example.test',$total,'$status','LM$id',1,1,?)"), [json_encode($o)]);
}

function expTickets(): int
{
    return (int) expDb()->selectOne(tixSql('SELECT COUNT(*) c FROM tickets'))->c;
}

function expOrderRow(string $id): array
{
    return json_decode(expDb()->selectOne(tixSql('SELECT data FROM orders WHERE id = ?'), [$id])->data, true);
}

it('306: markPaid on an Expired order with a taken seat marks refund instead of issuing', function () {
    // Another buyer holds the seat: the place went to someone else meanwhile.
    $held = app(TicketShop::class)->hold('e1', ['id0frwszv'], 'tk-hostile');
    expect($held['held'])->toContain('id0frwszv');

    expOrder('ord_exp1', 'Expired', [
        ['seat_id' => 'id0frwszv', 'label' => 'K1', 'tier' => 'Regular', 'class_id' => 'tc1', 'kind' => 'seat', 'capacity' => 1, 'price' => 150000],
    ]);
    $before = expTickets();

    $r = app(TicketShop::class)->markPaid('ord_exp1', ['payment_method' => 'QR_CODE', 'paid_amount' => 150000]);

    expect($r['refund_needed'] ?? false)->toBeTrue()
        ->and(expTickets())->toBe($before);
    $o = expOrderRow('ord_exp1');
    expect($o['payment_status'])->toBe('Expired')
        ->and($o['refund_needed']['sebab'] ?? '')->toContain('K1')
        ->and($o['payment']['status'] ?? '')->toBe('PAID');
});

it('306: markPaid on an Expired order with free seats still issues and mails', function () {
    Mail::fake();
    config(['laksamana.ticketing.smtp_host' => 'smtp.example.test', 'laksamana.ticketing.smtp_user' => 'tiket@example.test']);

    expOrder('ord_exp2', 'Expired', [
        ['seat_id' => 's10', 'label' => 'T10', 'tier' => 'VIP Table', 'class_id' => 'tc2', 'kind' => 'seat', 'capacity' => 1, 'price' => 500000],
    ], 500000);
    $before = expTickets();

    $r = app(TicketShop::class)->markPaid('ord_exp2', ['payment_method' => 'QR_CODE', 'paid_amount' => 500000]);

    expect($r['tiket'] ?? 0)->toBe(1)->and(expTickets())->toBe($before + 1);
    $o = expOrderRow('ord_exp2');
    expect($o['payment_status'])->toBe('Paid')->and($o['email_eticket']['ok'] ?? false)->toBeTrue();
    Mail::assertSent(ShopMail::class, fn (ShopMail $m) => $m->hasTo('t@example.test'));
});
