# Denah forwarder — `laksamanamuda.id/d/<token>`

The guest-facing table map is rendered by the API (`/d/<token>`), but the link
the bot sends should live on the **customer domain**. This folder is that
bridge: one PHP file that relays the request, so the address bar stays on
`laksamanamuda.id`.

```
customer WhatsApp ──▶ https://laksamanamuda.id/d/<token>
                          │  (this forwarder)
                          ▼
                      https://api.laksamanamuda.id/d/<token>   ← renders the map
```

## 1. Put the files on the customer domain

On the hosting that serves `laksamanamuda.id`, create `public_html/d/` and copy
**both** files there:

| file | why |
| --- | --- |
| `index.php` | fetches the API page and prints it unchanged |
| `.htaccess` | turns `/d/<token>` into `index.php?t=<token>` |

Keep the folder writable-off and `AllowOverride` on (cPanel default). Nothing
is stored; the token is checked by the API (invalid → 403, expired → 410, both
passed through so the guest still reads the real message).

No `mod_rewrite`? Skip `.htaccess`, call it as
`https://laksamanamuda.id/d/index.php?t=<token>` and in step 2 use
`DENAH_PUBLIC_BASE=https://laksamanamuda.id/d/index.php?t=`. The forwarder
accepts the token from `?t=` too (it appends nothing, so the base keeps its
own `?t=`).

### Docker / nginx instead of Apache

If the domain is served by nginx, skip the PHP and proxy the path (same effect,
no PHP):

```nginx
location /d/ {
    proxy_set_header Host api.laksamanamuda.id;
    proxy_pass https://api.laksamanamuda.id;
    resolver 1.1.1.1 valid=60s;
}
```

## 2. Point the API at the new base

In the API's `.env` (production), set:

```
DENAH_PUBLIC_BASE=https://laksamanamuda.id
```

That is all `POST /api/v1/automation/denah-link` reads (`config/denah.php`);
leave it unset to keep handing out `https://api.laksamanamuda.id/d/<token>`.
The n8n workflow needs no change — it sends whatever `data.url` the API
returns.

Then clear any compiled config (`php artisan config:clear`) if the server ever
caches it.

## 3. Check

```bash
# 1) mint on the API (needs the automation token)
curl -s -X POST https://api.laksamanamuda.id/api/v1/automation/denah-link \
  -H "Authorization: Bearer <automation-token>" -H 'Content-Type: application/json' \
  -d '{"date":"2031-07-10","time":"19:00"}'
# → {"data":{"url":"https://laksamanamuda.id/d/XXXXXXXXXXX.YYYYYYYYYYY"}}

# 2) the customer-domain URL answers with the map
curl -sI "https://laksamanamuda.id/d/<token>" | head -1     # 200
curl -sI "https://laksamanamuda.id/d/rusak"   | head -1     # 403
```

The token is short on purpose (~23 characters: 8 payload bytes + 8-byte
signature, base64url) and dies after 30 minutes on its own.
