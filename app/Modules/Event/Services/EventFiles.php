<?php

namespace App\Modules\Event\Services;

use App\Support\Modules;
use App\Support\RowSync;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Event files on disk — port of the berkas_* functions (talent transfer
 * receipts, talent documents such as KTP/NPWP/contracts, event posters).
 *
 * Layout (identical to legacy, so both backends share the folder during cutover):
 *   <EVENT_DATA_DIR>/files/ev_<16 hex>.<ext>
 *
 *  - images (jpg/png/webp/gif) and PDF only, 8 MB max
 *  - the state keeps only the pointer {key,name,size,at}
 *  - NO orphan cleanup, on purpose: the state can be saved partially, so an
 *    automatic sweep could delete a KTP that is still in use. Unused files
 *    only cost disk space.
 *
 * The legacy fallback folders (event-db next to public_html, or db/ inside
 * the backend) are not reproduced: EVENT_DATA_DIR applies.
 */
class EventFiles
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    private ?string $dir = null;

    public function dir(): string
    {
        if ($this->dir !== null) {
            return $this->dir;
        }
        $d = (string) Modules::dataDir('event');
        if ($d !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $d)) {
            $d = base_path($d);
        }
        if ($d === '') {
            $d = storage_path('app/event-db');
        }
        if (! is_dir($d) && ! @mkdir($d, 0775, true)) {
            throw new RuntimeException('DATA_DIR tidak bisa dibuat: '.$d);
        }

        return $this->dir = (realpath($d) ?: $d);
    }

    public function filesDir(): string
    {
        return $this->dir().'/files';
    }

    private function ensure(): void
    {
        if (! is_dir($this->filesDir())) {
            @mkdir($this->filesDir(), 0775, true);
        }
        // If the data dir ever lives inside the web root, deny direct access (KTP scans).
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
        $mime = strtolower((string) (is_scalar($mime) ? $mime : ''));
        if (isset($map[$mime])) {
            return $map[$mime];
        }
        $e = strtolower(pathinfo((string) (is_scalar($name) ? $name : ''), PATHINFO_EXTENSION));

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

    /**
     * simpan_berkas(): {dataBase64, fileName, mimeType} -> {key, name, size, at}.
     * Checks in legacy order: empty, type, base64, size.
     */
    public function save(array $p): array
    {
        if (empty($p['dataBase64'])) {
            throw new RuntimeException('file kosong');
        }
        $this->ensure();
        $ext = self::ext($p['mimeType'] ?? '', $p['fileName'] ?? '');
        if ($ext === 'bin') {
            throw new RuntimeException('hanya gambar (jpg/png/webp/gif) atau PDF');
        }
        $bin = base64_decode(preg_replace('#^data:[^,]+,#', '', (string) (is_scalar($p['dataBase64']) ? $p['dataBase64'] : '')), true);
        if ($bin === false) {
            throw new RuntimeException('base64 tidak valid');
        }
        if (strlen($bin) > self::MAX_BYTES) {
            throw new RuntimeException('file melebihi 8MB');
        }
        $key = 'ev_'.bin2hex(random_bytes(8)).'.'.$ext;
        if (@file_put_contents($this->path($key), $bin) === false) {
            throw new RuntimeException('gagal menulis file (cek izin folder)');
        }

        return [
            'key' => $key,
            'name' => isset($p['fileName']) ? RowSync::strRaw($p['fileName']) : $key,
            'size' => strlen($bin),
            'at' => gmdate('c'),
        ];
    }

    /** sajikan_berkas(): 400 for a bad key, 404 when missing (empty bodies, legacy exit codes). */
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
}
