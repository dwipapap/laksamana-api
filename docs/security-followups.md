# Security follow-ups (inherited from the legacy backends)

The legacy compat routes reproduce old behaviour on purpose, so the existing
frontends keep working. The items below are therefore **still open on the
compat routes**. The `/api/v1` surface closes them. Once every frontend has
moved to v1, retire the matching compat action or harden it.

| # | Where (legacy) | Issue | v1 status | Suggested fix for compat |
|---|---|---|---|---|
| 1 | account `users.pin` | PINs are stored in plain text, and the superadmin console displays them | v1 never returns PINs | hash (bcrypt) + reset flow once the console stops showing PINs |
| 2 | account `changePin` | Needs only the **name**, not the current PIN | v1 `PUT /me/pin` requires the current PIN | require a session token |
| 3 | account `sessionRefresh` | Takes a `userId` without any credential and reveals that user's modules | v1 uses `GET /me` (token) | require a session token |
| 4 | account `listDivisiRoster` / `listModuleRoster` | Open to anyone; they expose no_hp, talentaId, HR fields | v1 requires auth | require a session token (frontends already have one) |
| 5 | marketing, konten, akademi, bd, event, finance, kompas (analytics*, saveAll, simpanRekap…), hr, howandi | Write endpoints protected only by an optional shared `API_TOKEN`, empty by default | v1: Sanctum + `module:` middleware | require a session token + module on each write |
| 6 | finance-api | No session checks at all; the vault (brankas) and invoices are open | v1 gated by module (`finance`, `brankas`) | kompas reads brankasGet server-to-server, so it can be closed once kompas uses the in-process call |
| 7 | howandi-life | The token is embedded in the frontend HTML | v1: Sanctum | — |
| 8 | stock `users.php`, `ordering-users.php` | GET returns user PINs | v1 must never return PINs | strip the PINs |
| 9 | `absensi-mysql/config.dev.php` | A real database password is committed to the laksamana-office repo | n/a | rotate the password, remove it from git history |
| 10 | konten `hapus_yang_hilang` | No `_sejak` bound, so it can delete rows created after the client loaded (a bug marketing already fixed) | v1 has no whole-state save | port marketing's `_sejak` guard |
| 11 | akademi / konten data-dir fallback | Falls back to `../marketing-db` (copy-paste bug) | Laravel uses explicit `<KEY>_DATA_DIR` | — |
