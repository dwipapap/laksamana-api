<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receipt files on disk — port of the receipt_* functions.
 *
 * Layout (identical to legacy, so both backends share the folder during cutover):
 *   <DATA_DIR>/receipts/rc_<hex>.<ext>
 *
 * Differences from the marketing variant, kept deliberately:
 *  - 8 MB limit (not 40), images & PDF only (else `bin`).
 *  - NO chunked upload (konten never had uploadChunk).
 *  - NO trash folder: orphans older than 1 hour are hard-unlinked on EVERY
 *    saveAll (after commit). The 1-hour grace period exists because upload
 *    and save are two separate steps — without it, another crew's save could
 *    delete a just-uploaded file before it is recorded in any row.
 *  - Live keys are read from the DATABASE (content/assets/bank), never from
 *    a payload: save_all accepts partial payloads, so a payload without
 *    `content` would otherwise mark EVERY file as orphan.
 *
 * The legacy data-dir fallback (marketing-db folder next to the backend when
 * DATA_DIR is unset) is NOT reproduced: the explicit KONTEN_DATA_DIR applies.
 */
class KontenFiles
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const GC_SAFE_SECONDS = 3600;

    private ?string $dir = null;

    public function dir(): string
    {
        if ($this->dir !== null) {
            return $this->dir;
        }
        $d = (string) Modules::dataDir('konten');
        if ($d !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $d)) {
            $d = base_path($d);
        }
        if ($d === '') {
            $d = storage_path('app/konten-db');
        }
        if (! is_dir($d) && ! @mkdir($d, 0775, true)) {
            throw new RuntimeException('DATA_DIR tidak bisa dibuat: '.$d);
        }

        return $this->dir = (realpath($d) ?: $d);
    }

    public function filesDir(): string
    {
        return $this->dir().'/receipts';
    }

    private function ensure(): void
    {
        if (! is_dir($this->filesDir())) {
            @mkdir($this->filesDir(), 0775, true);
        }
        // If the data dir ever lives inside the web root, deny direct access.
        $public = realpath(public_path()) ?: public_path();
        if (str_starts_with($this->dir(), $public)) {
            $ht = $this->dir().'/.htaccess';
            if (! file_exists($ht)) {
                @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
            }
        }
    }

    public static function ext(mixed $mime, mixed $name): string
    {
        $map = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
        $mime = strtolower((string) $mime);
        if (isset($map[$mime])) {
            return $map[$mime];
        }
        $e = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return in_array($e, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'], true) ? ($e === 'jpeg' ? 'jpg' : $e) : 'bin';
    }

    public static function contentType(string $ext): string
    {
        return ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
    }

    public function path(string $key): string
    {
        return $this->filesDir().'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $key);
    }

    /** Whole-file upload. Returns {key, name}. */
    public function save(array $p): array
    {
        if (empty($p['dataBase64'])) {
            throw new RuntimeException('file kosong');
        }
        $this->ensure();
        $ext = self::ext($p['mimeType'] ?? '', $p['fileName'] ?? '');
        $bin = base64_decode(preg_replace('#^data:[^,]+,#', '', (string) $p['dataBase64']), true);
        if ($bin === false) {
            throw new RuntimeException('base64 tidak valid');
        }
        if (strlen($bin) > self::MAX_BYTES) {
            throw new RuntimeException('file melebihi 8MB');
        }
        $key = 'rc_'.bin2hex(random_bytes(8)).'.'.$ext;
        if (file_put_contents($this->path($key), $bin) === false) {
            throw new RuntimeException('gagal menulis file (cek izin folder)');
        }

        // name = the original name for display; the frontend builds the
        // view URL from the key.
        return ['key' => $key, 'name' => isset($p['fileName']) ? (string) $p['fileName'] : $key];
    }

    /** Keys still referenced anywhere in the database (never from a payload). */
    public function keysInUse(): array
    {
        $live = [];
        $db = Modules::db('konten');
        // Files can be pointed at from several places, all in the form
        // "...?action=receipt&key=xxx" or {key:...} — both are caught.
        foreach (['content', 'assets', 'bank'] as $t) {
            foreach ($db->select("SELECT data FROM `$t`") as $row) {
                $s = (string) $row->data;
                if (preg_match_all('/[?&]key=([^&"\'\\\\]+)/', $s, $m)) {
                    foreach ($m[1] as $k) {
                        $live[urldecode($k)] = true;
                    }
                }
                if (preg_match_all('/"key"\s*:\s*"([^"]+)"/', $s, $m2)) {
                    foreach ($m2[1] as $k) {
                        $live[$k] = true;
                    }
                }
            }
        }

        return $live;
    }

    /** Hard-unlink orphan files older than 1 hour. Returns the removed count. */
    public function gc(): int
    {
        $dir = $this->filesDir();
        if (! is_dir($dir)) {
            return 0;
        }
        $live = $this->keysInUse();
        $limit = time() - self::GC_SAFE_SECONDS;
        $buang = 0;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || isset($live[$f])) {
                continue; // still in use
            }
            $p = $dir.'/'.$f;
            if (! is_file($p) || filemtime($p) > $limit) {
                continue; // just uploaded -> do not touch
            }
            if (@unlink($p)) {
                $buang++;
            }
        }

        return $buang;
    }

    /** Stream a stored file; 400 for a bad key, 404 when missing (legacy exit codes). */
    public function stream(string $key): Response
    {
        if ($key === '' || str_contains($key, '..')) {
            return new Response('', 400);
        }
        $p = $this->path($key);
        if (! is_file($p)) {
            return new Response('', 404);
        }
        $r = new BinaryFileResponse($p, 200, [
            'Content-Type' => self::contentType(strtolower(pathinfo($p, PATHINFO_EXTENSION))),
            'Cache-Control' => 'private, max-age=86400',
        ]);
        $r->setContentDisposition('inline', basename($p));

        return $r;
    }

    /** Disk figures for stats. */
    public function diskStats(): array
    {
        $live = $this->keysInUse();
        $n = $bytes = $orphans = 0;
        if (is_dir($this->filesDir())) {
            foreach (scandir($this->filesDir()) ?: [] as $f) {
                $p = $this->filesDir().'/'.$f;
                if ($f === '.' || $f === '..' || ! is_file($p)) {
                    continue;
                }
                $n++;
                $bytes += filesize($p);
                if (! isset($live[$f])) {
                    $orphans++;
                }
            }
        }
        $public = realpath(public_path()) ?: public_path();

        return [
            'berkas' => $n, 'berkasMB' => round($bytes / 1048576, 3), 'berkasYatim' => $orphans,
            'folderBerkas' => $this->filesDir(),
            'amanDiLuarWeb' => ! str_starts_with($this->dir(), $public),
        ];
    }
}
