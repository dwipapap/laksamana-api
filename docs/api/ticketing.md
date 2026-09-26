# Ticketing API v1 — contract

Status: in progress.

- **#38** ports the QR and e-ticket PDF generators (`App\Modules\Ticketing\Services\QrCode`, `TicketPdf`). They have no endpoint of their own.
- **#39** (public site, holds, checkout, payment webhook) and **#40** (buyer accounts, mail, v1) add the endpoints that use them.

## Guarantees the endpoints will keep

- A ticket's QR matrix is identical to the one the ticketing frontend draws (`qrMatrix()` in `deploy/ticketing/index.html`), for any `qr_token`.
- The e-ticket PDF (two tickets per A4 page, vector QR, Helvetica/WinAnsi) is byte-identical to the legacy mail attachment.
- The attachment name is `E-Ticket-<payment_ref, safe chars>.pdf`.
