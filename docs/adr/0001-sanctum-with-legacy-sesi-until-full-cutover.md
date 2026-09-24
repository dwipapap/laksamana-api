# Sanctum is the only token for v1; legacy Sesi tokens live until every Backend runs on Laravel

Sanctum Bearer tokens are the one way to authenticate against `/api/v1` and the token every new app uses. The legacy compat routes keep accepting the old 64-hex `sesi` tokens (account `sessions` table) as well as Sanctum, because the old frontends store `sesi` and the unported legacy PHP Backends still read and forward it their own way. Switching the legacy `login` to issue Sanctum tokens and retiring the `sessions` table is a later milestone, done only once every Backend is served by Laravel and nothing outside it reads `sesi`.

## Considered Options

- **Sanctum everywhere now** (legacy `login` issues a Sanctum token in the `sesi` field): rejected. Unported PHP Backends may format-check or look up `sesi` themselves, which would break their Aplikasi mid-cutover.
- **Keep both tokens indefinitely**: rejected. It keeps two session stores and the plain legacy token path alive with no end date.
