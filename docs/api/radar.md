# Radar API v1 — contract

The read-only coordination board (laksamana-office `deploy/radar`, "Pusat Koordinasi"). Radar joins Marketing events, Reservasi VIP (kept in Marketing), Event-module events and Reservasi bookings on one board, and lists BD promos. It **writes nothing**: changes happen in the source module.

- **Base URL:** `/api/v1/radar`
- **Auth:** `Authorization: Bearer <token>` + Modul `radar`. Radar is a **built-in Modul for every account** (legacy `modul_bawaan_untuk`, 27 Sep 2026; `OfficeAccess::builtinModules`); an explicit per-user access=0 grant still removes it.
- **Why a contract of its own:** a Radar holder usually does not hold `marketing`, `event`, `reservasi` or `bd`, whose contracts stay closed to them. These endpoints read those modules on the server and return **narrow shapes** only — no CRM, no payment proofs, no transfer data.
- **Money (`bolehUang`):** amounts (prices, DP, settlement, costs, discounts, payment counts, nominal of added items, reservation DP) are sent **only** to a Head (the whole word `Head` in the Office Tim column), a `radar` admin, or a superadmin. The old page hid them on screen only; v1 does not send them. Every read returns `bolehUang` so the screen can say what is hidden.
- **Envelope:** success `{data, meta?}`, failure `{error: {code, message, details?}}`.

## Endpoints

| Method | Path | Returns |
|---|---|---|
| GET | `/board?from=&to=` | `{agenda, reservations, fbFormats, sumber, bolehUang, from, to}`. Both dates optional (default today −31 … today +62, WIB); at most 400 days → **422** otherwise. |
| GET | `/agenda/{sumber}/{id}` | One agenda item for the detail panel / Detail Lengkap. `sumber` = `mkt`, `evt` or `vip`. **404** when the item is not on the board (only what will happen can be opened). |
| GET | `/promos` | `{promos, arsip, sumber}` — running and upcoming BD promos. |
| GET | `/files/{key}` | The binary of a Marketing **event attachment** shown on the board (layout, voucher, rundown files). Any other key — payment receipts included — → **404**. |

### `agenda[]` (satukan)

`{sumber, id, tgl, judul, jam, selesai, tempat, status, pax, jenis, fb}` sorted by date, then time, **no-time items last** in their day. VIP rows add `paxMin`, `paxMax`.

| sumber | from | tgl / jam | tempat | pax | fb |
|---|---|---|---|---|---|
| `mkt` | Marketing `events[]` | `tanggal` / `detail.tamuDatang`–`detail.selesai` | `detail.area` | `pax` (contract) | `detail.fbFormat` |
| `vip` | Marketing `vip[]` | `tanggal` / `jamMulai`–`jamSelesai` | `meja` joined | upper bound `paxMax` → `paxMin` | — |
| `evt` | Event `events[]` | `start_datetime` / `end_datetime`, **shifted to WIB** when zoned (`Z`, `±hh:mm`); plain values as is | `venue` | `capacity` | — |

Only what will happen (agPasti): Marketing `Deal`, `Confirmed`, `Event Done`; Event `Approval`, `Upcoming`, `Event Done` (the statuses of the Event module since Oct 2026, = `EVT_STATUS_HASIL`) plus the old names `Today`, `Finished` for rows not saved since; Planning and Prospect never; VIP every row without `batalAt` that is not cancelled / no-show.

### `reservations[]`

Every reservation with a date in range, **all statuses** (the page hides Cancelled / No-show unless asked). Fields: `id, date, time, name, pax, table, category, status, vip, notes, foodReq, drinkReq, cancelReason, source, picName, phone, member, memberNo`, plus `dpAmount, dpStatus` when `bolehUang`.

### `sumber[]`

`{id, nama, ok, pesan, n}` per source (`mkt`, `evt`, `rsv`; `/promos` adds `bd`). A failed source is `ok:false` — the board still answers from the others, and the screen names the failed one ("tidak terhubung"). `n` = Marketing events, Event events, live reservations in range.

### `/agenda/{sumber}/{id}`

The agenda item plus:
- `mkt`: `mkt: {mktPIC (name), pembayaran (count, or null without bolehUang), detail}` and `klien: {nama, perusahaan, pic, hp} | null`. `detail` is the event's `detail` object **filtered**: money keys (a closed list plus a word pattern — `harga`, `nominal`, `deposit`, `dp…`, `bayar`, `biaya`, `diskon`, `budget`, `sewa`, `invoice`, …) and the `nominal` of name/nominal rows are dropped without `bolehUang`; bill lines (`{desc, harga|jumlah}`) are always dropped. Formatting (labels, menu tables, attachments) is the screen's job.
- `vip`: `vip: {mktPIC, hp, perusahaan, catatan}`, `klien`.
- `evt`: `event: {venue, category, capacity, pic, co_pic, theme, is_ticketed, description}`, `talents: [{nama, jam, tipe}]`.

### `/promos`

Status computed from dates in WIB (never a stored field): `paused` → `upcoming` (before `mulai`) → `ended` (after `selesai`) → `running`. Only `running` and `upcoming` are listed, running first then the nearest start; `arsip` counts the rest. Fields: `status, id, nama, tipe, kategori, benefit, partner, kode, ketentuan, outlet, hari, jamMulai, jamSelesai, kuota, lmPIC, poster, mulai, selesai, paused`.

## Screen → endpoint map

| Screen (deploy/radar `NAV`) | Endpoints |
|---|---|
| Radar (Pusat Koordinasi) | `/board` (period window + 14 days ahead) |
| Kalender Terpadu | `/board` for the month grid |
| Event (Daftar Event) | `/board`; card → `/agenda/{sumber}/{id}` |
| Reservasi | `/board` (reservation detail comes from the board row) |
| Promo | `/promos` |
| Detail Lengkap Event | `/agenda/{sumber}/{id}`, attachments `/files/{key}` |
