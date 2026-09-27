#!/usr/bin/env node
/*
 * agent-worktree.mjs — give an agent a worktree that actually runs ITS OWN code.
 *
 *   node tools/agent-worktree.mjs <issue> <slug>     # create ../wt-<issue>-<slug>
 *   node tools/agent-worktree.mjs --check <path>     # verify an existing worktree
 *
 * TWO TRAPS this closes (both hit the event+ticketing cutover, #61):
 *
 * 1. vendor/ must NOT be a symlink or junction to the main checkout. Composer
 *    bakes `$vendorDir = dirname(__DIR__); $baseDir = dirname($vendorDir)` into
 *    vendor/composer/autoload_*.php, so a linked vendor makes PHP load the MAIN
 *    checkout's app/** and tests/**. Every edit in the worktree then looks
 *    "ignored" by artisan, Pest and parity — silently. This script copies
 *    vendor/composer + vendor/autoload.php for real (so $baseDir is the
 *    worktree) and links only the package directories.
 *
 * 2. The worktree path is derived from the ISSUE, never from an agent name: two
 *    agents that both pick "wt-agent-a" end up editing one checkout and
 *    corrupting each other's files.
 *
 * It refuses to touch an existing path or branch, and it verifies itself with a
 * real PHP reflection probe before it prints the bootstrap commands.
 *
 * LOCAL ONLY: it never writes outside the new worktree (except the git index and
 * the fetched remote refs).
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const PHP = process.env.PHP_BIN || 'php';

const die = (m) => {
  console.error(`\n${m}\n`);
  process.exit(1);
};
const sh = (args) => execFileSync(args[0], args.slice(1), { cwd: ROOT, stdio: 'inherit' });
const out = (args) => execFileSync(args[0], args.slice(1), { cwd: ROOT, encoding: 'utf8' }).trim();
const isLink = (p) => {
  try {
    return fs.lstatSync(p).isSymbolicLink();
  } catch {
    return false;
  }
};

/** Junctions on Windows need no privileges; symlinks are the POSIX equivalent. */
function linkDir(target, at) {
  if (fs.existsSync(at) || isLink(at)) return false;
  fs.symlinkSync(target, at, process.platform === 'win32' ? 'junction' : 'dir');

  return true;
}

/**
 * Packages whose bin proxies resolve the package from `__DIR__`: they must be
 * real copies, not links, or `vendor/bin/pest` (and phpunit/pint) load the main
 * checkout's code and pest dies (#139). `vendor/bin` itself is copied too, so
 * the proxy file lives here.
 */
const mustCopy = (rel) =>
  rel === 'bin' ||
  /^pestphp[\\/]/.test(rel) ||
  /^phpunit[\\/]/.test(rel) ||
  /^laravel[\\/]pint$/.test(rel);

/**
 * A vendor/ that belongs to this worktree: real autoload.php + real composer/,
 * links for the packages. Never a link to the whole vendor/.
 */
function buildVendor(src, dst) {
  if (!fs.existsSync(path.join(src, 'autoload.php'))) {
    die(`No vendor/ in ${ROOT}. Run \`composer install\` there first.`);
  }
  if (isLink(src)) {
    die(`${src} is a link. Run this from the main checkout, not from another worktree.`);
  }
  fs.mkdirSync(dst, { recursive: true });
  fs.copyFileSync(path.join(src, 'autoload.php'), path.join(dst, 'autoload.php'));
  fs.cpSync(path.join(src, 'composer'), path.join(dst, 'composer'), { recursive: true });

  let linked = 0;
  let copied = 0;
  const copiedPkgs = [];
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    if (e.name === 'composer' || e.name === 'autoload.php') continue;
    const s = path.join(src, e.name);
    const d = path.join(dst, e.name);
    if (!e.isDirectory()) {
      if (!fs.existsSync(d)) {
        fs.copyFileSync(s, d);
        copied++;
      }
      continue;
    }
    if (mustCopy(e.name)) {
      fs.cpSync(s, d, { recursive: true });
      copiedPkgs.push(e.name);
      continue;
    }
    const kids = fs.readdirSync(s, { withFileTypes: true });
    const copyKids = kids.filter((c) => mustCopy(`${e.name}/${c.name}`));
    if (copyKids.length) {
      // a real group dir: copy its bin-shipping children, link the rest
      fs.mkdirSync(d, { recursive: true });
      for (const c of kids) {
        const cs = path.join(s, c.name);
        const cd = path.join(d, c.name);
        if (mustCopy(`${e.name}/${c.name}`)) {
          fs.cpSync(cs, cd, { recursive: true });
          copiedPkgs.push(`${e.name}/${c.name}`);
        } else if (c.isDirectory()) {
          if (linkDir(cs, cd)) linked++;
        } else if (!fs.existsSync(cd)) {
          fs.copyFileSync(cs, cd);
          copied++;
        }
      }
    } else if (linkDir(s, d)) {
      linked++;
    }
  }
  const pkgs = copiedPkgs.length ? ` (${copiedPkgs.join(', ')})` : '';
  console.log(`vendor/: autoload.php + composer/ copied (real), ${linked} package dir(s) linked, ${copied} loose file(s) copied, ${copiedPkgs.length} bin package(s)/dir(s) copied${pkgs}`);
}

/** Fails loudly when the worktree would execute another checkout's code. */
function verify(dir) {
  const autoload = path.join(dir, 'vendor', 'autoload.php');
  if (!fs.existsSync(autoload)) die(`No vendor/autoload.php in ${dir}`);
  const probe = `require ${JSON.stringify(autoload)}; echo (new ReflectionClass('App\\\\Providers\\\\AppServiceProvider'))->getFileName();`;
  let file = '';
  try {
    file = execFileSync(PHP, ['-r', probe], { cwd: dir, encoding: 'utf8' }).trim();
  } catch (e) {
    die(`vendor check could not run PHP (${e.message.split('\n')[0]}). Is php on PATH?`);
  }
  const want = path.resolve(dir).toLowerCase();
  if (!path.resolve(file).toLowerCase().startsWith(want + path.sep)) {
    die(
      `WRONG CODE PATH — App\\... resolves to\n  ${file}\nbut this worktree is\n  ${dir}\n\n` +
      'vendor/ is (or contains) a link to another checkout, so artisan, Pest and\n' +
      'parity would run THAT code, not this worktree\'s. Fix:\n' +
      `  delete ${path.join(dir, 'vendor')} (the link only) and re-run this script.\n` +
      'More: docs/agents/worktrees.md'
    );
  }
  console.log(`vendor check OK: App\\... loads from ${dir}`);

  // pest probe (#139): vendor/bin/pest must actually run from THIS worktree.
  // Composer's proxies resolve the package from __DIR__, so a linked bin/ or
  // pestphp/phpunit/pint package would run another checkout's pest.
  const pest = path.join(dir, 'vendor', 'bin', 'pest');
  if (!fs.existsSync(pest)) {
    die(`No vendor/bin/pest in ${dir} — run \`composer install\` there first.`);
  }
  let pestOut = '';
  try {
    pestOut = execFileSync(PHP, [pest, '--version'], { cwd: dir, encoding: 'utf8' }).trim();
  } catch (e) {
    die(
      `pest probe FAILED in ${dir}: vendor/bin/pest did not run.\n  ` +
      `${String(e.stderr || e.message || e).trim().split('\n').slice(0, 8).join('\n  ')}\n\n` +
      'vendor/bin or a pest/phpunit/pint package is a link to another checkout, so pest\n' +
      `resolves __DIR__ there. Delete ${path.join(dir, 'vendor')} and re-run this script.\n` +
      'More: docs/agents/worktrees.md'
    );
  }
  if (!/pest/i.test(pestOut)) {
    die(`pest probe gave an unexpected answer in ${dir}:\n  ${pestOut}`);
  }
  console.log(`pest probe OK: ${pestOut.split('\n')[0]}`);
}
/**
 * Unlink every symlink/junction under $root WITHOUT following it (the target is
 * never touched). Needed before deleting a worktree: a recursive delete of a
 * junction via some tools would walk into the main checkout's vendor/.
 */
function unlinkLinks(root) {
  let n = 0;
  const walk = (dir, depth) => {
    if (depth > 3) return;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, e.name);
      if (isLink(p)) {
        try {
          fs.unlinkSync(p);
        } catch {
          fs.rmdirSync(p); // Windows: a junction is removed with rmdir, the target stays
        }
        n++;
        continue;
      }
      if (e.isDirectory()) walk(p, depth + 1);
    }
  };
  walk(root, 0);

  return n;
}

// ───────────────────────────────── CLI ─────────────────────────────────

const args = process.argv.slice(2);

if (args[0] === '--check') {
  if (!args[1]) die('usage: node tools/agent-worktree.mjs --check <path>');
  const dir = path.resolve(args[1]);
  if (!fs.existsSync(dir)) die(`no such directory: ${dir}`);
  verify(dir);
  process.exit(0);
}

if (args[0] === '--clean') {
  if (!args[1]) die('usage: node tools/agent-worktree.mjs --clean <worktree-path>');
  const dir = path.resolve(args[1]);
  if (dir.toLowerCase() === ROOT.toLowerCase()) die('refusing to clean the main checkout');
  const listed = out(['git', 'worktree', 'list', '--porcelain'])
    .split(/\r?\n\r?\n/)
    .some((b) => b.replace(/\\/g, '/').toLowerCase().includes(dir.replace(/\\/g, '/').toLowerCase()));
  if (!listed) die(`${dir} is not a worktree of this repo; refusing to clean it.`);
  console.log(`${unlinkLinks(dir)} symlink/junction(s) unlinked (their targets are untouched)`);
  sh(['git', 'worktree', 'remove', '--force', dir]);
  console.log(`removed ${dir}`);
  process.exit(0);
}

const [issue, slug] = args;
if (!/^\d+$/.test(issue ?? '')) {
  die('usage: node tools/agent-worktree.mjs <issue-number> <slug>   (e.g. 71 reservasi-cutover)');
}
if (!/^[a-z0-9][a-z0-9-]*$/.test(slug ?? '')) {
  die('slug must be lowercase letters, digits and dashes (e.g. reservasi-cutover)');
}

const branch = `issue-${issue}-${slug}`;
const dir = path.resolve(ROOT, '..', `wt-${issue}-${slug}`);

if (isLink(path.join(ROOT, 'vendor'))) {
  die(`${ROOT}/vendor is a link — run this from the main checkout.`);
}
if (fs.existsSync(dir)) {
  die(`${dir} already exists; someone may own it.\n\n${out(['git', 'worktree', 'list'])}`);
}
if (out(['git', 'branch', '--list', branch])) {
  die(`branch ${branch} already exists:\n\n${out(['git', 'branch', '-vv', '--list', branch])}`);
}
const owned = out(['git', 'worktree', 'list']).split('\n').filter((l) => l.includes(`issue-${issue}-`));
if (owned.length) {
  die(`issue ${issue} already has a worktree:\n\n${owned.join('\n')}\n\n` +
    'Do not create a second one: two agents in one checkout corrupt each other (docs/agents/worktrees.md).');
}

console.log('fetching origin…');
sh(['git', 'fetch', 'origin']);
sh(['git', 'worktree', 'add', dir, '-b', branch, 'origin/main']);

const envFile = path.join(ROOT, '.env');
if (fs.existsSync(envFile)) {
  fs.copyFileSync(envFile, path.join(dir, '.env'));
  console.log('.env copied (never commit it)');
} else {
  console.warn('warning: no .env in the main checkout — copy .env.example and fill it in');
}

const nm = path.join(ROOT, 'node_modules');
if (fs.existsSync(nm)) {
  console.log(linkDir(nm, path.join(dir, 'node_modules')) ? 'node_modules linked' : 'node_modules already present');
}

buildVendor(path.join(ROOT, 'vendor'), path.join(dir, 'vendor'));
verify(dir);

console.log(`
worktree ${dir}
branch   ${branch}

Bootstrap, once per shell (Windows PowerShell):
  cd ${dir}
  $env:PATH = 'C:\\Users\\dwip\\.config\\herd-lite\\bin;' + $env:PATH
  $env:DB_DATABASE      = 'lakk5493_laksamana_core_test_<your-suffix>'
  $env:PARITY_TAG       = '<your-tag>'
  $env:PARITY_PORT_BASE = '<your-port-base>'

Ship: commit -> git fetch origin && git rebase origin/main -> push ->
gh pr create -B main ... -> gh pr checks <pr> -> gh pr merge <pr> --merge --delete-branch

Re-check at any time:  node tools/agent-worktree.mjs --check ${dir}
Delete when done:      node tools/agent-worktree.mjs --clean ${dir}
Rules and etiquette:   docs/agents/worktrees.md
`);

