# account — DONE

The legacy source is `account-mysql/` in laksamana-office. The old URL was `/account-api-mysql/api.php` and it pointed at database `lakk5493_db_account`.

This module is the Office SSO: users, which modules each user may open (grants), module admins, and session tokens.

## Where each piece lives

| What | Where |
|---|---|
| Module-access truth table | `app/Auth/OfficeAccess.php`, ported from `modul_untuk`, `admin_modul_untuk`, the built-in rules and the restricted-module rules |
| Old 64-hex session tokens | `app/Auth/LegacySessions.php` |
| Business rules (every `aksi_*`) | `app/Modules/Account/Services/AccountService.php` |
| Compatibility controller | `app/Modules/Account/Http/Legacy/AccountLegacyController.php`, exposing all 25 legacy actions |
| New API | `app/Modules/Account/Http/V1/*`: `auth/login`, `auth/logout`, `me`, `me/pin`, `me/username`, `account/roster`, `account/modules/{m}/members`, `account/roster` (for roster managers), `account/users*` (superadmin), `account/modules` |

## Verification

- Parity: `tools/parity/cases/account.json`, 139/139 identical.
- Tests: `tests/Feature/Account/AuthTest.php`.

## Known differences

These are deliberate:

- `ping` and `stats` report `backend: 'laravel'` instead of `'php-mysql'`.
- DB errors are masked as `kesalahan database` unless `APP_DEBUG` is on.
- Tim "FOH" grants the built-in jadwal access: the owner-decided #3 fix, not yet in laksamana-office's legacy PHP.
