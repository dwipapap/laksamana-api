# Deploying laksamana-api

ADR-0005: the API runs on the Rumahweb cPanel that also hosts laksamana-office, on its own subdomains, for **new apps only**. Workflow: `.github/workflows/deploy.yml`.

| Target | Host (proposed) | When it deploys |
|---|---|---|
| dev | `dev-api.laksamanamuda.id` | automatically, after `ci` passes on `main` |
| production | `api.laksamanamuda.id` | only by hand: Actions → **deploy** → Run workflow → target `production` |

Claude and the agents never deploy or touch a server (CLAUDE.md §0): GitHub Actions uploads, the owner does the server-side steps below.

## What a deploy does

1. Checks in CI that the locked dependencies install on the server's PHP, and writes the commit hash to `public/build.txt`. **`vendor/` is not uploaded**: over FTP its ~6,200 files took hours, so it is installed on the server with Composer (setup step 9). When `composer.lock` changed since the deployed commit, the run summary says so: then run Composer on the server (see Releasing).
2. Uploads the app folder by FTP (three attempts). `tests/`, `tools/`, `docs/`, `.github/`, `.env*` and logs are never uploaded. Only files that changed since the last deploy are sent; files created on the server (`.env`, logs, compiled views) are never deleted.
3. Verifies: `https://<host>/up` answers 200 and `https://<host>/build.txt` shows the deployed commit. A half-finished upload fails this step.

Deliberately **not** done: `config:cache`, `route:cache`, `view:cache` (built in CI they would contain the runner's paths), and no `php artisan` on the server (there may be no shell).

## One-time setup per environment (owner) — see #172

1. **Subdomain** in cPanel, with its document root set to `<app folder>/public`, and the app folder **outside** `public_html` (e.g. `/home/lakk5493/laksamana-api-dev`). Turn on SSL.
2. **PHP ≥ 8.4** for that subdomain (MultiPHP Manager; the locked Symfony packages need 8.4) and for the Terminal CLI (`php -v` must say 8.4), with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip`, `curl`.
3. **FTP account** whose home is the app folder.
4. **GitHub Environment** (repo → Settings → Environments), named `dev` or `production`:
   - secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`;
   - variable `APP_HOST` = the subdomain, without `https://`;
   - optional variables `PHP_VERSION` (default `8.4`, set it to the server's), `FTP_PROTOCOL` (`ftp` default, `ftps` if Rumahweb accepts it), `FTP_DIR` (default `./`).
   - For `production`, add yourself as a required reviewer, so a deploy waits for your click.
5. **Core database**: create an empty database (e.g. `lakk5493_laksamana_core`), then locally run `tools/core-schema.sh` and import the file it writes (`../core-schema.sql`) through phpMyAdmin → Import. It holds every core table empty, plus the migration list. Sanctum tokens for new apps are stored there. Do this for dev before production.
6. **MySQL user** with rights on that core database **and** on every legacy database the API serves (`lakk5493_db_*` for production, the dev ones for dev).
7. **Create `.env`** in the app folder with cPanel File Manager (template below). No deploy ever uploads or deletes it.
8. **Run the first deploy** (Actions → deploy → Run workflow → `dev`). It sends the app code (~400 files); later deploys send only what changed. Verify fails until step 9 is done.
9. **Install Composer and `vendor/` on the server** (cPanel Terminal, one line at a time — the Terminal cuts multi-line pastes):
   ```bash
   mkdir -p ~/bin && cd ~/bin && php -r "copy('https://getcomposer.org/installer','composer-setup.php');" && php composer-setup.php --quiet --filename=composer && rm composer-setup.php
   cd ~/<app folder> && php ~/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-scripts && php artisan package:discover --ansi
   cd ~/<app folder> && php artisan migrate --force   # creates the core tables (Sanctum tokens)
   ```
   Rumahweb disables `proc_open` on the CLI, so the install must skip scripts and discover packages by hand; `php artisan about` always fails there — check with `php artisan --version` plus `curl .../up` instead.
   Then re-run the deploy; Verify must pass.

## `.env` on the server

Generate the key locally with `php artisan key:generate --show` (one per environment).

```dotenv
APP_NAME="Laksamana API"
APP_ENV=production            # dev: production too (debug off); the label below tells them apart
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://api.laksamanamuda.id
LOG_LEVEL=warning

# core: Laravel's own tables (Sanctum tokens) — every Modul stays on legacy (ADR-0005)
DB_CONNECTION=core
DB_HOST=localhost
DB_DATABASE=lakk5493_laksamana_core
DB_USERNAME=...
DB_PASSWORD=...
# legacy databases: one user for all, or override per DB (DB_<ENV>_DATABASE/_USERNAME/_PASSWORD)
DB_LEGACY_USERNAME=...
DB_LEGACY_PASSWORD=...
# dev only, if the dev databases are named differently, e.g.:
# DB_ACCOUNT_DATABASE=lakk5493_db_dev_account

# never set DB_<MODULE>_CONNECTION=core here while that Modul's old screens are in use

CACHE_STORE=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
LAKSAMANA_ENV_LABEL=produksi  # dev: dev

# origins of the new apps allowed to call /api/v1 from a browser (comma-separated)
CORS_ALLOWED_ORIGINS=https://office.laksamanamuda.id

# ticketing mail / payments, as the old ticketing backend's config.php
# TIX_SMTP_HOST=  TIX_SMTP_PORT=465  TIX_SMTP_USER=  TIX_SMTP_PASS=
# XENDIT_SECRET=  XENDIT_CALLBACK=
```

The data folders default to the old backends' (`/home/lakk5493/<x>-db`), so both apps share them. Override with `<KEY>_DATA_DIR` only if the paths differ.

## Releasing to production

If the run summary says **composer.lock changed**, run in cPanel Terminal for that environment first: `cd ~/<app folder> && php ~/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-scripts && php artisan package:discover --ansi`, then re-run the workflow.

1. The change is on `main` and dev has deployed it (Actions shows a green **deploy** run for that commit).
2. Try it on dev.
3. Actions → **deploy** → Run workflow → target `production` (approve it if a reviewer is required).

**Rollback:** run the workflow on the previous good commit (Run workflow → "Use workflow from" a branch or tag at that commit). Nothing in the database changes on deploy, so code rollback is enough.

## Deploying over SSH (fallback)

When Rumahweb's firewall keeps dropping the GitHub runners (Troubleshooting below: FTP and even HTTPS time out), the owner deploys from their own machine over SSH (cPanel SSH Access, port 2223, an `~/.ssh/config` alias `laksamana-cpanel`):

```bash
tools/deploy-ssh.sh production            # or: dev; optional ref (default origin/main); -y skips the prompt
```

It does what the workflow does: uploads the commit without `tests/`, `tools/`, `docs/`, never touches `.env`, `vendor/`, logs or cPanel's ini files, removes files the commit deleted since the deployed one, runs Composer only when `composer.lock` changed, writes `build.txt` last and verifies `/up` + `build.txt`. It never runs `migrate`; new core migrations are listed for a deliberate apply (next section).

## New core migrations

Every Modul stays on legacy in production (ADR-0005), so core rarely changes. When a migration must reach a server: run `tools/core-schema.sh` on the new commit, compare with the tables already there, and apply only the new `CREATE TABLE` statements plus the new `migrations` rows in phpMyAdmin.

## Troubleshooting

- **FTP step times out** (curl code 28): Rumahweb's firewall often drops GitHub runner IPs. Re-run the workflow (a new runner gets a new IP); if it keeps happening, ask Rumahweb support to unblock it.
- **Verify: `/up` = 500**: usually `.env` is missing or wrong (`APP_KEY`, DB credentials), or the PHP version is below 8.4 (`vendor/composer/platform_check.php` says so in the error log).
- **Verify: `build.txt` shows the old commit**: the upload didn't finish; re-run the workflow (only the missing files are sent).
- **`/up` = 404/403**: the subdomain's document root is not `<app folder>/public`.
