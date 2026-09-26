<?php

namespace App\Modules\Ticketing\Services;

/**
 * E-ticket mail after payment (legacy kirim_eticket). It never throws: the money
 * is in and the tickets are issued, so a mail failure is recorded on the order
 * (`email_eticket`), never raised into the payment path.
 */
class TicketMail
{
    public static function ready(): bool
    {
        $h = (string) config('laksamana.ticketing.smtp_host', '');
        $u = (string) config('laksamana.ticketing.smtp_user', '');

        return $h !== '' && ! str_starts_with($h, 'ISI_') && $u !== '' && ! str_starts_with($u, 'ISI_');
    }

    public function sendEticket(array $o, array $tickets): array
    {
        if (! self::ready()) {
            return ['ok' => false, 'sebab' => 'SMTP belum dikonfigurasi'];
        }

        // ponytail: SMTP sending (with the TicketPdf attachment) is ported in #40; until then a configured server records why nothing went out.
        return ['ok' => false, 'sebab' => 'Pengiriman email belum tersedia di backend ini (#40).', 'at' => gmdate('c')];
    }
}
