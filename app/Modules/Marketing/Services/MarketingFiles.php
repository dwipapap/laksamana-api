<?php

namespace App\Modules\Marketing\Services;

use App\Support\Modules;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transfer proofs & attachments on disk — port of the receipt_* functions.
 *
 * Layout (identical to legacy, so both backends share the folder during cutover):
 *   <DATA_DIR>/receipts/rc_<hex>.<ext>      live files
 *   <DATA_DIR>/receipts_tmp/up_<id>.part    chunked uploads in progress
 *   <DATA_DIR>/receipts_sampah/             orphans moved here after 7 days, purged after 60
 * Orphans are MOVED, never unlinked directly — a GC that did not recognise a
 * new attachment field once deleted event images silently.
 */
class MarketingFiles
{
    public const MAX_BYTES = 40 * 1024 * 1024;

    public const GC_SAFE_SECONDS = 7 * 24 * 3600;

    public const GC_TRASH_SECONDS = 60 * 24 * 3600;

    private ?string $dir = null;

    public function dir(): string
    {
        if ($this->dir !== null) {
            return $this->dir;
        }
        $d = (string) Modules::dataDir('marketing');
        if ($d !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $d)) {
            $d = base_path($d);
        }
        if ($d === '') {
            $d = storage_path('app/marketing-db');
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

    public function tmpDir(): string
    {
        return $this->dir().'/receipts_tmp';
    }

    public function trashDir(): string
    {
        return $this->dir().'/receipts_sampah';
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

    private static function decode(string $b64): string
    {
        $bin = base64_decode(preg_replace('#^data:[^,]+,#', '', $b64), true);
        if ($bin === false) {
            throw new RuntimeException('base64 tidak valid');
        }

        return $bin;
    }

    /** Whole-file upload. Returns {key, name}. */
    public function save(array $p): array
    {
        if (empty($p['dataBase64'])) {
            throw new RuntimeException('file kosong');
        }
        $this->ensure();
        $ext = self::ext($p['mimeType'] ?? '', $p['fileName'] ?? '');
        $bin = self::decode((string) $p['dataBase64']);
        if (strlen($bin) > self::MAX_BYTES) {
            throw new RuntimeException('file melebihi 40MB');
        }
        $key = 'rc_'.bin2hex(random_bytes(8)).'.'.$ext;
        if (file_put_contents($this->path($key), $bin) === false) {
            throw new RuntimeException('gagal menulis file (cek izin folder)');
        }

        return ['key' => $key, 'name' => isset($p['fileName']) ? (string) $p['fileName'] : $key];
    }

    private function tmpPath(mixed $uploadId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $uploadId);
        if ($safe === '' || strlen($safe) > 80) {
            throw new RuntimeException('uploadId tidak sah');
        }

        return $this->tmpDir().'/up_'.$safe.'.part';
    }

    /** ~2 MB chunks so no request ever hits post_max_size. Last chunk renames into place. */
    public function saveChunk(array $p): array
    {
        if (! isset($p['uploadId'])) {
            throw new RuntimeException('uploadId kosong');
        }
        $this->ensure();
        if (! is_dir($this->tmpDir())) {
            @mkdir($this->tmpDir(), 0775, true);
        }
        $path = $this->tmpPath($p['uploadId']);
        $seq = (int) ($p['seq'] ?? 0);
        $last = ! empty($p['last']);
        if ($seq === 0) {
            @unlink($path);
        } elseif (! file_exists($path)) {
            throw new RuntimeException('potongan awal hilang — ulangi unggahan');
        }
        if (isset($p['dataBase64']) && $p['dataBase64'] !== '') {
            $bin = self::decode((string) $p['dataBase64']);
            clearstatcache(true, $path);
            if ((file_exists($path) ? filesize($path) : 0) + strlen($bin) > self::MAX_BYTES) {
                @unlink($path);
                throw new RuntimeException('file melebihi 40MB');
            }
            if (file_put_contents($path, $bin, FILE_APPEND) === false) {
                throw new RuntimeException('gagal menulis potongan (cek izin folder)');
            }
            clearstatcache(true, $path);
        }
        if (! $last) {
            return ['ok' => true, 'seq' => $seq, 'bytes' => (int) @filesize($path)];
        }
        $key = 'rc_'.bin2hex(random_bytes(8)).'.'.self::ext($p['mimeType'] ?? '', $p['fileName'] ?? '');
        if (! @rename($path, $this->path($key))) {
            @unlink($path);
            throw new RuntimeException('gagal menyimpan berkas gabungan');
        }
        foreach (glob($this->tmpDir().'/up_*.part') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }

        return ['key' => $key, 'name' => isset($p['fileName']) ? (string) $p['fileName'] : $key];
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

    // ───────────────────────── garbage collection ──

    private static function collectKeys(mixed $v, array &$live): void
    {
        if (! is_array($v)) {
            return;
        }
        if (isset($v['key']) && is_string($v['key']) && $v['key'] !== '') {
            $live[$v['key']] = true;
        }
        foreach ($v as $x) {
            if (is_array($x)) {
                self::collectKeys($x, $live);
            }
        }
    }

    /** Keys still referenced anywhere inside events (read from the DB, never from a payload). */
    public function keysInUse(): array
    {
        $live = [];
        foreach (Modules::db('marketing')->select('SELECT data FROM events') as $row) {
            $e = json_decode((string) $row->data, true);
            if (! is_array($e)) {
                continue;
            }
            self::collectKeys($e, $live);
            foreach (is_array($e['payments'] ?? null) ? $e['payments'] : [] as $pay) {
                if (! empty($pay['receiptUrl']) && preg_match('/[?&]key=([^&"\']+)/', (string) $pay['receiptUrl'], $m)) {
                    $live[urldecode($m[1])] = true;
                }
            }
        }

        return $live;
    }

    /** Move orphans older than 7 days to the trash; purge trash older than 60 days. */
    public function gc(): int
    {
        $dir = $this->filesDir();
        if (! is_dir($dir)) {
            return 0;
        }
        $live = $this->keysInUse();
        $limit = time() - self::GC_SAFE_SECONDS;
        $moved = 0;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || isset($live[$f])) {
                continue;
            }
            $p = $dir.'/'.$f;
            if (! is_file($p) || filemtime($p) > $limit) {
                continue;
            }
            if (! is_dir($this->trashDir()) && ! @mkdir($this->trashDir(), 0775, true)) {
                continue;
            }
            if (@rename($p, $this->trashDir().'/'.$f)) {
                @touch($this->trashDir().'/'.$f);
                $moved++;
            }
        }
        $this->purgeTrash();

        return $moved;
    }

    public function purgeTrash(): int
    {
        $t = $this->trashDir();
        if (! is_dir($t)) {
            return 0;
        }
        $limit = time() - self::GC_TRASH_SECONDS;
        $n = 0;
        foreach (scandir($t) ?: [] as $f) {
            $p = $t.'/'.$f;
            if ($f !== '.' && $f !== '..' && is_file($p) && filemtime($p) <= $limit && @unlink($p)) {
                $n++;
            }
        }

        return $n;
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
        $missing = 0;
        foreach (array_keys($live) as $k) {
            if (! is_file($this->path((string) $k))) {
                $missing++;
            }
        }
        $ns = $bs = 0;
        if (is_dir($this->trashDir())) {
            foreach (scandir($this->trashDir()) ?: [] as $f) {
                $p = $this->trashDir().'/'.$f;
                if ($f !== '.' && $f !== '..' && is_file($p)) {
                    $ns++;
                    $bs += filesize($p);
                }
            }
        }
        $public = realpath(public_path()) ?: public_path();

        return [
            'berkas' => $n, 'berkasMB' => round($bytes / 1048576, 3), 'berkasYatim' => $orphans,
            'berkasHilang' => $missing, 'sampah' => $ns, 'sampahMB' => round($bs / 1048576, 3),
            'folderBerkas' => $this->filesDir(), 'folderSampah' => $this->trashDir(),
            'amanDiLuarWeb' => ! str_starts_with($this->dir(), $public),
        ];
    }
}
