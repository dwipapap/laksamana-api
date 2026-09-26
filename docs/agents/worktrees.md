# Agent worktrees

Every agent works in **its own git worktree, one per ISSUE**, so that nobody edits
another agent's checkout. The path and the branch are derived from the issue
number, never from an agent name:

```
../wt-<issue>-<slug>        branch issue-<issue>-<slug>
```

Create it with:

```bash
node tools/agent-worktree.mjs 71 reservasi-cutover     # -> ../wt-71-reservasi-cutover
node tools/agent-worktree.mjs --check ../wt-71-reservasi-cutover   # re-verify later
```

The script creates the worktree from `origin/main`, copies `.env` (never commit
it), links `node_modules/`, builds a `vendor/` that belongs to the worktree, and
**verifies with PHP that `App\...` really loads from the new worktree**. If the
verification fails it exits non-zero and you must not work there.

## Trap 1 — a linked `vendor/` silently runs the main checkout's code

Composer writes this into `vendor/composer/autoload_*.php`:

```php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
```

So when `vendor/` is a symlink or junction to `../laksamana-api/vendor`,
`$baseDir` resolves to `../laksamana-api` and **every `App\…`/`Tests\…` class is
loaded from the main checkout**. Symptoms: your edits appear to have no effect,
`php -l` passes, but `artisan`, Pest and `tools/parity` behave as if the code were
unchanged, and `core:import --list` does not show an importer you just registered.
Nothing warns you.

Do **not** do this:

```powershell
New-Item -ItemType Junction -Path vendor -Target ..\laksamana-api\vendor   # WRONG
```

Do this instead — either run `node tools/agent-worktree.mjs`, or by hand:

```bash
cp ../laksamana-api/vendor/autoload.php vendor/            # a REAL copy
cp -r ../laksamana-api/vendor/composer vendor/composer     # a REAL copy
for d in ../laksamana-api/vendor/*/; do                    # link the packages only
  [ "$(basename "$d")" = composer ] || ln -s "$(realpath "$d")" "vendor/$(basename "$d")"
done
```

and then prove it:

```bash
php -r "require 'vendor/autoload.php'; echo (new ReflectionClass('App\Providers\AppServiceProvider'))->getFileName(), PHP_EOL;"
# the path printed MUST start with your worktree, not with ../laksamana-api
```

Copying the whole `vendor/` tree also works but is slow; `robocopy /MIR` over a
large vendor can take minutes and must never run in the other direction.

## Trap 2 — two agents in one worktree

Worktree names derived from an agent label (`wt-agent-a`) collide: two agents end
up in the same checkout, each one "fixing" the other's in-flight edits (a doubled
doc line, a reverted switch, a re-run `migrate`). Deriving the name from the issue
makes the collision impossible, and the script refuses to touch an existing path
or branch. Before you start:

```bash
git worktree list                        # who owns what
git branch -vv --list 'issue-<N>-*'      # is someone already on my issue?
```

If another worktree already checks out **your** issue, stop and report it instead
of creating a second one.

## Deleting a worktree

```bash
node tools/agent-worktree.mjs --clean ../wt-<issue>-<slug>
```

It unlinks every symlink/junction first (their targets are never touched) and only
then removes the worktree. Never delete a worktree with a recursive delete that
follows reparse points — through a package junction it can walk into this
checkout's `vendor/` and wipe it.

## Own your runtime values

Each agent picks its own so parallel runs cannot clash:

| value | example | where |
|---|---|---|
| test database | `lakk5493_laksamana_core_test_<suffix>` | `$env:DB_DATABASE` |
| parity scratch DBs/tag | `$env:PARITY_TAG = 'evz'` → `parity_evz_*` | env, before `tools/parity` |
| parity ports | `$env:PARITY_PORT_BASE = '9300'` → 9300/9301/9302 | env, before `tools/parity` |
| module switches | `DB_EVENT_CONNECTION=core` … | env, per cutover |

Pest migrates its test database itself (`RefreshDatabase`), and
`tools/parity/parity.mjs` creates and drops its own scratch databases, so no
pre-setup is needed for a throwaway run. The test database is rebuilt only when
the migration files differ from the hash stamped in its `test_schema_stamp`
table, so give each worktree its own `DB_DATABASE`: two checkouts with different
migrations sharing one test database would rebuild it (~90 s) on every run.

## Etiquette (MySQL is shared)

Run only the touched module's test folder, never the full suite (CLAUDE.md §4,
pass criteria). Check how many PHP processes are already running first
(`tasklist | grep -c php` on Windows; more than about six means wait), never run
two test runs at once — every run transacts all legacy databases — and treat
deadlocks or `QueryException`s that only appear under load as contention: rerun
alone before debugging. After a parity run with
`--keep`, drop the leftover `parity_<tag>_*` databases.
