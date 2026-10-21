#!/usr/bin/env bash
# Deploy a commit to the Rumahweb cPanel over SSH, from the owner's machine.
#
# The fallback for .github/workflows/deploy.yml while Rumahweb's firewall drops
# the GitHub runners (FTP :21 and HTTPS both time out, docs/deploy.md
# Troubleshooting). Same result as the workflow: the app code without tests/,
# tools/, docs/; `.env`, `vendor/`, logs and cPanel's ini files are never
# touched; files the commit deleted since the deployed one are removed;
# Composer runs only when composer.lock changed; `build.txt` is written last,
# so a half-finished run is visible from outside.
#
# Config and routes are cached on the server (api#312): both caches are
# cleared before the upload (the old code keeps working, just uncached) and
# rebuilt after it, so a new route or config key is never hidden behind a
# stale cache. The caches hold absolute paths, so they are always built here,
# on the server (docs/deploy.md "Route and config cache").
#
# Never runs `php artisan migrate`: a new core migration is applied on purpose
# (docs/deploy.md "New core migrations"), so the script only names them.
#
# Usage:  tools/deploy-ssh.sh <production|dev> [ref] [-y]   (ref default: origin/main)
# Env:    DEPLOY_SSH_HOST (default: laksamana-cpanel, an ~/.ssh/config alias)
set -euo pipefail

TARGET="${1:-}"; REF="origin/main"; YES=0
shift || true
for a in "$@"; do
  case "$a" in
    -y) YES=1 ;;
    *) REF="$a" ;;
  esac
done
SSH_HOST="${DEPLOY_SSH_HOST:-laksamana-cpanel}"

case "$TARGET" in
  production) DIR="laksamana-api";     HOST="api.laksamanamuda.id" ;;
  dev)        DIR="laksamana-api-dev"; HOST="dev-api.laksamanamuda.id" ;;
  *) echo "Usage: tools/deploy-ssh.sh <production|dev> [ref] [-y]" >&2; exit 2 ;;
esac

cd "$(dirname "$0")/.."
# Same exclusions as deploy.yml (vendor/, node_modules/, .env are not in git anyway).
PATHS=(. ':!tests' ':!tools' ':!docs' ':!.github' ':!.claude' ':!phpunit.xml' ':!.env.example')

git fetch -q origin
SHA="$(git rev-parse "$REF^{commit}")"
PREV="$(curl -fsS --max-time 20 "https://$HOST/build.txt?$(date +%s)" 2>/dev/null | tr -d '[:space:]' || true)"

COMPOSER=1; DELETED=""; MIGRATIONS=""
if [ -n "$PREV" ] && git cat-file -e "$PREV^{commit}" 2>/dev/null; then
  git diff --quiet "$PREV" "$SHA" -- composer.lock && COMPOSER=0
  DELETED="$(git diff --no-renames --name-only --diff-filter=D "$PREV" "$SHA" -- "${PATHS[@]}")"
  MIGRATIONS="$(git diff --name-only --diff-filter=A "$PREV" "$SHA" -- database/migrations)"
fi

echo "Target     : $TARGET ($HOST → ~/$DIR via $SSH_HOST)"
echo "Deployed   : ${PREV:-unknown}"
echo "Deploying  : $SHA ($(git log -1 --format=%s "$SHA"))"
echo "Composer   : $([ "$COMPOSER" = 1 ] && echo 'yes (composer.lock changed or deployed commit unknown)' || echo no)"
echo "Removed    : $(printf '%s' "$DELETED" | grep -c . || true) file(s)"
if [ -n "$MIGRATIONS" ]; then
  echo "NEW CORE MIGRATIONS (not run — apply them on purpose):"
  printf '  %s\n' $MIGRATIONS
fi
if [ "$YES" != 1 ]; then
  read -r -p "Lanjut deploy? [y/N] " ok
  [ "$ok" = y ] || [ "$ok" = Y ] || { echo "Dibatalkan."; exit 1; }
fi

# Refuse a folder that is not an installed app (wrong DIR would scatter files).
ssh "$SSH_HOST" "test -f ~/$DIR/.env -a -d ~/$DIR/vendor" \
  || { echo "~/$DIR has no .env or vendor/: not an installed app, aborting." >&2; exit 1; }

echo "Clearing the config + route cache..."
ssh "$SSH_HOST" "cd ~/$DIR && php artisan config:clear && php artisan route:clear"

echo "Uploading..."
git archive --format=tar "$SHA" -- "${PATHS[@]}" | ssh "$SSH_HOST" "cd ~/$DIR && tar -xf -"

if [ -n "$DELETED" ]; then
  echo "Removing files deleted since $PREV..."
  printf '%s\n' "$DELETED" | ssh "$SSH_HOST" "cd ~/$DIR && while IFS= read -r f; do
    case \"\$f\" in ''|/*|*..*|.env*|vendor/*|storage/*) continue ;; esac
    rm -f -- \"\$f\"
  done"
fi

if [ "$COMPOSER" = 1 ]; then
  echo "Composer install..."
  # Rumahweb disables proc_open on the CLI: skip scripts, discover packages by hand.
  ssh "$SSH_HOST" "cd ~/$DIR && php ~/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress && php artisan package:discover --ansi"
fi

echo "Caching config + routes..."
# A failed cache must not be left half-built: clear both and stop before
# build.txt, so Verify fails and the app keeps serving (uncached) the new code.
ssh "$SSH_HOST" "cd ~/$DIR && { php artisan config:cache && php artisan route:cache; } || { php artisan config:clear; php artisan route:clear; exit 1; }" \
  || { echo "config/route cache FAILED (both cleared, app runs uncached). build.txt not written." >&2; exit 1; }

ssh "$SSH_HOST" "cd ~/$DIR && echo $SHA > public/build.txt"

echo "Verifying..."
up="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "https://$HOST/up")"
got="$(curl -fsS --max-time 20 "https://$HOST/build.txt?$(date +%s)" | tr -d '[:space:]')"
echo "/up=$up build.txt=$got"
[ "$up" = 200 ] && [ "$got" = "$SHA" ] || { echo "VERIFY FAILED" >&2; exit 1; }
echo "Deployed $SHA to $TARGET."
