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

## Port notes (#38: QR and PDF)

- `lib_qr.php` → `App\Modules\Ticketing\Services\QrCode::matrix()` and `lib_pdf.php` → `TicketPdf::eticket()` / `::qr()` / `::fileName()`. These are faithful ports with the same penalty/mask choice, the same PDF object layout and the same WinAnsi transliteration.
- **Test vectors:** `tests/Fixtures/ticketing/` was recorded from the legacy PHP by running the legacy libs over fixed synthetic inputs.
  - QR texts cover versions 1..10, plus a too-long text that must throw.
  - Three e-ticket PDFs: seat/table/general/fallback tickets with a CP1252 title and no event, and one capped at `$max`.
  - The attachment names.
- `tests/Unit/TicketingQrPdfTest.php` (DB-free, so it also runs in CI) asserts:
  - identical QR matrices
  - byte-identical PDFs when the zlib build matches the recording, and identical inflated page content with any zlib build
- Legacy `tools/uji-qr.js` proves legacy PHP = frontend JS `qrMatrix()`, so matching the legacy vectors keeps the PDF QR and the on-screen QR the same code.
- **No action surface:** the legacy API exposes no QR or PDF action. The PDF is only a mail attachment, built in `kirim_eticket()` when an order is paid. The compat actions and v1 that use these services arrive with #39 (orders/e-ticket) and #40 (mail, buyer v1).

## Port notes (#39: public site, holds, checkout, payment)

- **Where it lives:** `App\Modules\Ticketing\Services\TicketShop` ports `lib_ticketing.php` section by section (events, seat map, holds, checkout, upgrade, Xendit, webhook, payment, sweeps, `tix_gagal` throttle, order status).
  - `Xendit` wraps the two Xendit calls through Laravel's Http client, so tests fake it; `Http::preventStrayRequests()` guards every ticketing test.
  - `TicketMail` returns the legacy "SMTP not configured" result. The SMTP port itself is #40.
- **Compat route:** `/ticketing-api/api.php` (`TicketingLegacyController`). It keeps the old front door:
  - 413 over 256 KB; JSON depth 16
  - `idBersih`
  - webhook detected by the `x-callback-token` header
  - no-store / nosniff / DENY
  - webhook 401/500
  - DB and PHP errors hidden behind the generic message
- **Config:** the old `config.php` constants become `config('laksamana.ticketing.*')`: `XENDIT_SECRET`, `XENDIT_CALLBACK`, `XENDIT_MOCK`, `TIX_ADMIN_FEE`, `TIX_HOLD_MINUTES`, `TIX_BAYAR_MENIT`, `TIX_MAX_PER_PESANAN`, `EVENT_FILES_DIR`, `EVENT_API_URL`, `TIX_SMTP_*`.
  - The environment (dev vs produksi) and the site URL are still derived from the serving host.
  - Poster files default to the event Modul's `<EVENT_DATA_DIR>/files`.
- **Maintenance:** a request carrying `x-callback-token` gets `503`, not the usual 200 envelope, so Xendit retries instead of losing the payment.
- **Parity:** `tools/parity/cases/ticketing.json` has 58 cases covering every public action, including the full flows:
  - hold → checkout
  - simulated payment → e-ticket
  - webhook paid/expired/idempotent
  - upgrade

  Result: 58/58 identical. After `--keep`, `seat_holds`, `orders`, `tickets`, `seats`, `ticket_classes` and `tix_gagal` match between old and new. The only exceptions are random ids/tokens and the order of seat items inside one order, which legacy reads without `ORDER BY` through an index on random hold ids. The runner gained `legacyDefines`, `laravelEnv`, per-case `headers`, `rawBody[Size]` and `manualRedirect`.
- **Not in this cluster:** the Buyer account actions (`daftar`, `masuk`, `keluar`, `lupaPassword`, `resetPassword`, `saya`, `tiketSaya`) and `ujiEmail` still answer `Aksi tidak dikenal` on Laravel until #40.
