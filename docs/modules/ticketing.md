# ticketing — public ticket shop (laksamanamuda.id/ticketing)

- **Legacy source:** `laksamana-office/ticketing-mysql/` (`api.php`, `lib_ticketing.php`, `lib_pdf.php`, `lib_qr.php`, `schema-tambahan.sql`)
- **Legacy URL:** `/ticketing-api/api.php`, served on the MAIN domain rather than the office host
- **Database:** `lakk5493_db_ems`, SHARED with the event module
- **Config:** everything comes from env and must never be hard-coded:
  - Payments: `XENDIT_SECRET`, `XENDIT_CALLBACK` (webhook token), `XENDIT_MOCK` (dev mode)
  - Site and events: `SITE_URL`, `EVENT_FILES_DIR`, `EVENT_API_URL`
  - Limits: `ADMIN_FEE` (5000), `HOLD_MINUTES` (10), `BAYAR_MENIT` (10), `MAX_PER_PESANAN` (10)
  - Mail: `SMTP_HOST`, `SMTP_PORT` (465), `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_NAME`
  - Local and dev runs **must use mock mode** and never call real Xendit or SMTP.

## Tables

- **From the EMS module:** `events`, `ticket_classes`, `seats`, `orders`, `tickets` (tickets has UNIQUE `qr_token`)
- **Its own** (`schema-tambahan.sql`, run by hand):
  - `seat_holds`, with **UNIQUE `seat_id`**. It is a separate table so that EMS `saveAll` cannot wipe active holds.
  - `tix_users`, with UNIQUE `email`
  - `tix_sessions`
  - `tix_reset`
  - `tix_gagal`, holding rate-limit hashes

## Envelope and limits

- Replies are `{ok, data|error}` with HTTP 200.
- Webhook errors: a wrong token returns 401 and a server error returns 500.
- Requests over 256 KB get 413.
- JSON nesting depth is capped at 16.
- PDO errors are hidden from the client.
- `idBersih()` sanitises ids.

## Actions

| Group | Actions |
|---|---|
| Public reads | `ping`, `events`, `event&id`, `poster&id` (serves the image), `denah&id&hold` |
| Seat holds | `hold` (max 20 seats), `release` |
| Purchase | `checkout` (`nama`, `email`, `hp`, `umum[]`) returns `invoice_url`, `ref` and `access_token`. `upgradeMulai`. `order&ref&token`. |
| Payment | `webhook`, detected by the `x-callback-token` header. `simbayar` is dev-only. |
| Buyer account | `daftar`, `masuk`, `keluar`, `lupaPassword`, `resetPassword`, `saya&sesi`, `tiketSaya&sesi` |
| Dev only | `ujiEmail` |

## Concurrency and background work

- Every write runs under `GET_LOCK('<db>:tix', 10)`.
- `seat_holds.seat_id` is UNIQUE, so two buyers cannot hold the same seat.
- Expired holds and expired pending orders are swept inside normal requests; there is no cron.

## External calls

- **Xendit:** `POST /v2/invoices` and `GET /v2/invoices/{id}`.
- **SMTP:** sends the e-ticket as a PDF attachment. `lib_pdf.php` and `lib_qr.php` are plain PHP with no composer dependencies.

## QR codes must be byte-identical

The frontend draws QR codes in JS, and the server puts them into the PDF. Both must produce exactly the same matrix. Port `lib_qr.php` faithfully and add a test using the vectors in `laksamana-office/tools/uji-qr.js`.

## v1 proposal

- `/api/v1/tickets/events`
- `/api/v1/tickets/holds`
- `/api/v1/tickets/checkout`
- `/api/v1/tickets/orders/{ref}`
- `/api/v1/tickets/me`
- The webhook stays on its legacy path.
