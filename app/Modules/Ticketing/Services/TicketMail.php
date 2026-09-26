<?php

namespace App\Modules\Ticketing\Services;

use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * The shop's mail (legacy kirim_email / kirim_eticket / the reset mail), sent through
 * the `ticketing` mailer: the shop's own domain account over SMTP (TIX_SMTP_*), so
 * it passes SPF/DKIM instead of landing in spam. Tests use Mail::fake().
 *
 * The e-ticket mail never throws: the money is in and the tickets are issued, so
 * a failure is recorded on the order (`email_eticket`), never raised into payment.
 */
class TicketMail
{
    public static function ready(): bool
    {
        $h = (string) config('laksamana.ticketing.smtp_host', '');
        $u = (string) config('laksamana.ticketing.smtp_user', '');

        return $h !== '' && ! str_starts_with($h, 'ISI_') && $u !== '' && ! str_starts_with($u, 'ISI_');
    }

    /** @param list<array{nama:string,mime:string,isi:string}> $attachments */
    public function send(string $to, string $subject, string $html, array $attachments = []): void
    {
        if (! self::ready()) {
            throw new RuntimeException('SMTP belum dikonfigurasi di server.');
        }
        Mail::mailer('ticketing')->to($to)->send(new ShopMail($subject, $html, $attachments));
    }

    /** Called when an order is paid. The PDF (all QR codes) is built separately: if it fails, the mail still goes. */
    public function sendEticket(array $o, array $tickets): array
    {
        if (! self::ready()) {
            return ['ok' => false, 'sebab' => 'SMTP belum dikonfigurasi'];
        }
        try {
            $ev = app(TicketShop::class)->eventAnyStatus((string) $o['event_id']);
            $att = [];
            $pdfWhy = '';
            try {
                $att[] = ['nama' => TicketPdf::fileName($o), 'mime' => 'application/pdf', 'isi' => TicketPdf::eticket($o, $tickets, $ev)];
            } catch (Throwable $e) {
                $pdfWhy = $e->getMessage();
            }
            $this->send($o['email'], 'E-Ticket '.($ev ? $ev['title'] : 'Laksamana Muda').' — '.$o['payment_ref'],
                self::eticketHtml($o, $tickets, $ev, (bool) $att), $att);
            $out = ['ok' => true, 'at' => gmdate('c'), 'pdf' => (bool) $att];
            if ($pdfWhy) {
                $out['pdf_sebab'] = $pdfWhy;
            }

            return $out;
        } catch (Throwable $e) {
            return ['ok' => false, 'sebab' => $e->getMessage(), 'at' => gmdate('c')];
        }
    }

    private static function h($s): string
    {
        return htmlspecialchars((string) $s);
    }

    /** No QR image in the body (mail clients block images): the PDF and a permanent link to the e-ticket page. */
    public static function eticketHtml(array $o, array $tickets, ?array $ev, bool $hasPdf): string
    {
        $title = $ev ? $ev['title'] : 'Event Laksamana Muda';
        $link = TicketShop::siteUrl().'/#tiket/'.rawurlencode($o['payment_ref']).'/'.rawurlencode($o['access_token']);
        $rows = '';
        foreach ($tickets as $t) {
            $pt = (int) ($t['pax_total'] ?? 1);
            $kind = $t['kind'] ?? ($pt > 1 ? 'table' : 'seat');
            if ($kind === 'general') {
                $place = 'Tanpa nomor tempat'.($pt > 1 ? ' &middot; Tiket '.(int) ($t['pax_no'] ?? 0).' dari '.$pt : '');
            } else {
                $place = ($kind === 'table' ? 'Meja ' : '').self::h($t['seat_label'] ?? '')
                    .($pt > 1 ? ' &middot; Tamu '.(int) ($t['pax_no'] ?? 0).' dari '.$pt : '');
            }
            $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #E7E1D3">'
                .'<b>'.self::h($t['ticket_number'] ?? '').'</b> &middot; '
                .$place.' <span style="color:#8C8677">('.self::h($t['tier'] ?? '').')</span>'
                .'</td></tr>';
        }

        return '<div style="font-family:Arial,Helvetica,sans-serif;background:#F7F6F4;padding:24px">'
            .'<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #E7E1D3;border-radius:14px;overflow:hidden">'
            .'<div style="background:#2A2620;color:#fff;padding:20px 24px">'
            .'<div style="font-size:12px;color:#C8961F;letter-spacing:1px">LAKSAMANA MUDA</div>'
            .'<div style="font-size:22px;font-weight:bold;margin-top:4px">'.self::h($title).'</div>'
            .($ev ? '<div style="font-size:13px;color:#ccc;margin-top:4px">'.self::h($ev['venue']).'</div>' : '')
            .'</div>'
            .'<div style="padding:22px 24px">'
            .'<p style="margin:0 0 14px">Halo <b>'.self::h($o['buyer_name']).'</b>, pembayaranmu sudah kami terima. Tiketmu siap.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px">'.$rows.'</table>'
            .($hasPdf
                ? '<p style="margin:16px 0 8px;padding:10px 12px;background:#F7F1E2;border-radius:8px;font-size:13px;color:#5C574D">'
                  .'<b>Lampiran PDF</b> berisi seluruh QR pesanan ini ('.count($tickets).' tiket) — satu halaman dua tiket, '
                  .'siap dicetak atau dibagikan. Bisa dibuka tanpa sinyal.</p>'
                : '')
            .'<p style="margin:18px 0 8px;font-size:13px;color:#5C574D">Atau tunjukkan QR di halaman berikut kepada petugas saat masuk:</p>'
            .'<p><a href="'.self::h($link).'" style="display:inline-block;background:#A9791F;color:#fff;'
            .'padding:12px 20px;border-radius:9px;text-decoration:none;font-weight:bold">Buka e-ticket</a></p>'
            .'<p style="font-size:12px;color:#8C8677;margin-top:16px">Simpan email ini. Tautan di atas berlaku permanen dan hanya bisa dibuka olehmu.<br>'
            .'Kode pesanan: <b>'.self::h($o['payment_ref']).'</b></p>'
            .'</div></div></div>';
    }

    public static function resetHtml(string $name, string $link): string
    {
        return '<div style="font-family:Arial,sans-serif;background:#F7F6F4;padding:24px">'
            .'<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #E7E1D3;'
            .'border-radius:14px;padding:24px">'
            .'<p>Halo <b>'.self::h($name).'</b>,</p>'
            .'<p>Ada permintaan mengatur ulang password akun tiketmu. Tautan ini berlaku <b>1 jam</b> '
            .'dan hanya bisa dipakai sekali.</p>'
            .'<p><a href="'.self::h($link).'" style="display:inline-block;background:#A9791F;'
            .'color:#fff;padding:12px 20px;border-radius:9px;text-decoration:none;font-weight:bold">'
            .'ATUR ULANG PASSWORD</a></p>'
            .'<p style="font-size:12px;color:#8C8677">Kalau bukan kamu yang meminta, abaikan saja email ini '
            .'— passwordmu tidak berubah selama tautan di atas tidak dibuka.</p>'
            .'</div></div>';
    }
}
