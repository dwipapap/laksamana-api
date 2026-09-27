# laksamana-api runs on api.laksamanamuda.id, on the same cPanel, for new apps only

Production runs laksamana-api on the **same Rumahweb cPanel account** as laksamana-office, on its own subdomain **`api.laksamanamuda.id`** (dev: **`dev-api.laksamanamuda.id`**), whose document root is the app's `public/`. It is deployed like laksamana-office: GitHub Actions builds it (`composer install --no-dev`, config/route caches) and uploads it by FTP, `develop` → dev, `main` → production, dev first.

**Only new apps use it** (laksamana-office-vue and later clients, through `/api/v1`). The old laksamana-office screens keep calling their own PHP backends through their relative `../<module>-api-mysql/api.php` URLs; nothing routes them to Laravel, and laksamana-office is not changed (it is read-only for this project).

Because the old PHP backends keep writing the **legacy databases**, production Laravel serves every Modul from those same databases (`DB_<MODULE>_CONNECTION` unset = legacy). Both apps then share one source of truth: the same MySQL server, the same rows, and the same `GET_LOCK` names (`NamedLock`), so their writes serialise. A Modul moves to `core` (ADR-0002, by ADR-0004's offline import) only **after its old screens are retired**, when nothing writes its legacy database any more.

## Consequences

- The legacy compat routes stay in the codebase (they are the tested spec of each Backend's behaviour) but receive no production traffic. #81 (route the old URLs to Laravel) is dropped; #82 (retire legacy `sesi` tokens, ADR-0001) is moot while the old screens own their sessions.
- The per-Modul production cutovers (#46–#74) wait until that Modul's old screens are retired; the dev rehearsals can still run against dev.
- New apps call `api.` from another origin, so CORS must allow exactly their origins (not `*`) before the first new app goes live.
- Server access beyond FTP (SSH / cPanel Terminal) is unconfirmed. Until it is, nothing runs `php artisan` on the server: caches are built in CI, and `core` migrations go through phpMyAdmin as ADR-0004 describes. If a shell exists, ADR-0004's import steps can be revisited.
- The cPanel account must offer PHP ≥ 8.2 (8.4 preferred, as developed) for the subdomain, with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd` (ticketing QR/PDF) and outbound SMTP for the ticketing mailer.

## Considered Options

- **Separate VPS**: rejected. The legacy databases live on the cPanel MySQL; Laravel on another server would share no locks with the old backends and add a network hop to every query.
- **Route the old URLs to Laravel** (same-domain rewrite, or asking laksamana-office's developers to change `API_URL`): rejected for now. It couples every cutover to the old app and its developers; new apps get the API without it.
- **Serve Modul from `core` while the old screens still write legacy**: rejected. It creates two diverging sources of truth, which ADR-0002 forbids.
