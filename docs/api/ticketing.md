# Ticketing API v1 — contract

The public ticket shop (laksamanamuda.id/ticketing).

- **Base URL:** `/api/v1/tickets`
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`

**Buyers are not Users.** The shop is public, so it does **not** sit behind `auth:sanctum` + `module:`:

- reads need nothing;
- a seat hold belongs to whoever has its **hold token**;
- an order opens with its **ref + access token**;
- Buyer-only writes (checkout, upgrade) take the **Buyer session token** as `Authorization: Bearer <token>`. Buyer accounts (register, log in, my tickets) are #40.

**Rules that never change:**

- Prices are always computed on the server; a `total` sent by the client is ignored.
- Every write takes the same `GET_LOCK('<db>:tix')` as the legacy shop.
- Expired holds and orders are swept by normal traffic.

**Status:**

- #38: QR + PDF
- #39: events, seat map, holds, checkout, upgrade, payment
- #40: Buyer accounts, mail, milestone (next)

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/events` | Events on sale (EMS `Upcoming`/`Today`), with classes and server-side `sisa` per class (Pending orders already count) |
| GET | `/events/{id}` | One event on sale; `404` otherwise |
| GET | `/events/{id}/seatmap?hold=<token>` | The EMS seat map as designed (x/y/w/h, shape, floor, colours) with live `status`: `available`, `mine`, `held`, `sold`, `checked`, `area`, `perabot` (tables are furniture, never sold). Also `price`/`class_id` for sellable places, and `hold_exp` (ms) for your own |
| GET | `/events/{id}/poster` | The poster image (cacheable), a redirect to the EMS file API, or `404` |
| POST | `/holds` | `{event_id, seats[≤20], hold_token?}` → `{hold_token, expires_at, held[], ditolak[[seat, reason]]}`. A new token is minted when none is sent. Refusals are data, not errors |
| DELETE | `/holds/{token}?seats=a,b` | Releases this token's holds that are not yet bound to an order → `{released}` |
| POST | `/checkout` | Bearer = Buyer. `{event_id, hold_token, name, email, phone, notes?, general:[{class_id, qty}]}` → `201 {order_id, ref, access_token, total, invoice_url}`. Held seats plus unseated tickets; admin fee per ticket; the order waits `TIX_BAYAR_MENIT` minutes |
| POST | `/upgrades` | Bearer = Buyer. `{ticket_id, seat_id}` → `201 {order_id, ref, access_token, selisih, invoice_url}`. Pays only the difference from the price actually paid; downgrades are refused (refunds are crew work); one live upgrade per ticket |
| GET | `/orders/{ref}?token=` | `token` = access token (or header `X-Order-Token`). Status, items, and once Paid the tickets with their `qr_token`. A Pending order is re-checked with Xendit. Throttled per caller |
| GET | `/orders/{ref}/eticket.pdf?token=` | The e-ticket PDF of a Paid order: all live tickets, vector QR, byte-identical layout to the mail attachment |
| POST | `/orders/{ref}/simulate-payment` | `{token}`. Dev only (`XENDIT_MOCK` on a non-production host). Pays through the same path as the webhook |

The **Xendit webhook** stays on its legacy path, `POST /ticketing-api/api.php` with the `x-callback-token` header:

- `401`: wrong token.
- `500`: anything else, so Xendit retries.
- `503`: the Modul is in maintenance.

A PAID webhook is confirmed with Xendit's own API before any ticket is issued.

## Errors

| Status | code | When |
|---|---|---|
| 401 | `buyer_required` | checkout/upgrade without a live Buyer session |
| 404 | `not_found` | unknown or unsold event, unknown order, wrong access token, unknown ticket |
| 413 | `payload_too_large` | body over 256 KB |
| 422 | `validation_failed` | no or invalid seat list |
| 422 | `rejected` | any refusal of the shop rules; `message` is the buyer-facing Indonesian text, e.g. `Kursi 12 keburu terjual. Silakan pilih ulang.` |
| 429 | `too_many_attempts` | too many failed order lookups from this address |
| 503 | `busy` / `module_maintenance` | the `tix` lock timed out / the Modul is frozen |

## Guarantees kept from legacy

- A ticket's QR matrix is identical to the one the ticketing frontend draws (`qrMatrix()`), for any `qr_token` (#38 test vectors).
- One ticket per guest (a 6-seat table issues 6 QR codes).
- Ticket numbers run over the whole order.
- Payment is idempotent: the same webhook twice issues nothing new.
- A Pending order whose invoice cannot be created is cancelled at once and its seats released.
