# n8n workflows — Laksamana automation feeds

Ekspor sanitized dari workflow produksi yang membaca feed read-only
`GET /api/v1/automation/*`. Kontrak feed: `docs/api/automation.md`.
Cutover ke feed ini: 2026-10-07 17:30 WIB (Refs #234).

## Workflows

| File | Fungsi | Jadwal | Feed |
|---|---|---|---|
| `rekap-reservasi-17.json` | Rekap reservasi harian, kirim ke grup koordinasi sore hari | 17:00 WIB (`Jadwal 17 WIB`, timezone `Asia/Jakarta`) | `GET /api/v1/automation/reservasi-harian?days=0` |
| `info-pagi-07.json` | Info event/marketing/VIP pagi hari, kirim ke grup koordinasi | 07:00 WIB (`Jadwal 07 WIB`, timezone `Asia/Jakarta`) | `GET /api/v1/automation/info-pagi` |

Alur tiap workflow (5 node):
`Jadwal → Ambil (HTTP) → Cek meta.errors → Format Pesan → Kirim ke Group Koordinasi (GOWA)`

- Node HTTP: retry on fail 3× jeda 5000 ms, timeout 30 detik.
- `Cek meta.errors`: melempar error berisi `meta.errors` bila feed
  melaporkan kegagalan seksi/hari, sehingga workflow GAGAL dan tidak
  mengirim pesan kosong. `meta.errors` kosong (`{}`) = semua sumber OK.
- Node format hanya memformat teks; logika multi-hari, DP vs `dps[]`,
  pembatalan, dan VIP tetap di server.

## Credential yang dibutuhkan

1. **Header Auth `Laksamana API automation`** (dipakai node HTTP):
   header `Authorization`, value `Bearer <token>`.
   Token dibuat sekali di server API: `php artisan automation:token n8n --user="<akun-teknis>"`
   (kemampuan persis `automation:read`, lihat `docs/api/automation.md`).
   Nilai token TIDAK ikut ter-ekspor — buat credential ini manual di n8n
   tujuan, lalu petakan saat import.
2. **Kredensial pengirim GOWA** (`GOWA account`, referensi id+nama saja yang
   ikut ter-ekspor): petakan ke kredensial GOWA milik instance tujuan.

## Placeholder (wajib diisi saat import)

| Placeholder | Arti | Diisi dengan |
|---|---|---|
| `<WA_GROUP_ID>` | ID grup WhatsApp tujuan (`phoneNumber` node `Kirim ke Group Koordinasi`) | ID grup koordinasi, mis. `<id-grup>@g.us` |

Tidak ada placeholder lain: tidak ada nomor HP, URL webhook, API key,
token, atau password di berkas ini. `pinData` dikosongkan, field
instance (`id`, `versionId`, `shared`, `staticData`) dibuang.

## Cara import

UI: Workflows → ⋯ → Import from File → pilih JSON → petakan credential
(`Laksamana API automation`, GOWA) → isi `<WA_GROUP_ID>` → simpan
**dalam keadaan NONAKTIF** → uji manual sampai node `Format*` → cocokkan
teks dengan eksekusi terakhir → baru aktifkan.

CLI: `n8n import:workflow --input=docs/n8n/<file>.json`
(lalu lakukan pemetaan credential + placeholder yang sama di UI).

## Cara rollback

Workflow lama (`Laksamana Rekap Reservasi 17 WIB`,
`Laksamana Info Pagi 07 WIB` tanpa akhiran `(automation)`) disimpan
**nonaktif** di n8n sebagai rollback: nonaktifkan salinan `(automation)`,
aktifkan workflow lama untuk jadwal yang sama.
