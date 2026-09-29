<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menu photos. No legacy folder exists, so this deliberately does NOT use a
 * `data_dir`: files live under `storage/app/menu/` (private) and are streamed
 * by `GET /api/v1/menu/foto/{key}` (§7).
 */
class MenuPhotos
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** mime => extension. Anything else is rejected with 422. */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function dir(): string
    {
        $d = storage_path('app/menu');
        if (! is_dir($d) && ! @mkdir($d, 0775, true)) {
            throw new RuntimeException('Folder foto menu tidak bisa dibuat: '.$d);
        }

        return $d;
    }

    public static function contentType(string $ext): string
    {
        return ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
    }

    public function path(string $key): string
    {
        return $this->dir().'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $key);
    }

    /**
     * Store an uploaded image. Returns {key, url}. Throws RuntimeException with
     * a human message for a non-image, an oversized file, or a write failure.
     *
     * @param  array{name:string,tmp:string,size:int,mime:?string}  $file
     * @return array{key:string,url:string}
     */
    public function save(array $file): array
    {
        $mime = strtolower((string) ($file['mime'] ?? ''));
        if (! isset(self::TYPES[$mime])) {
            throw new RuntimeException('Berkas harus berupa gambar (jpg, png atau webp).');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Berkas melebihi 8MB.');
        }
        $bin = @file_get_contents($file['tmp']);
        if ($bin === false || $bin === '') {
            throw new RuntimeException('Berkas tidak terbaca.');
        }
        $key = 'mn_'.bin2hex(random_bytes(4)).'.'.self::TYPES[$mime];
        if (@file_put_contents($this->path($key), $bin) === false) {
            throw new RuntimeException('Gagal menulis berkas (cek izin folder).');
        }

        return ['key' => $key, 'url' => '/api/v1/menu/foto/'.$key];
    }

    /** Remove a stored file. Missing is not an error. Returns true when unlinked. */
    public function delete(string $key): bool
    {
        if ($key === '' || str_contains($key, '..')) {
            return false;
        }
        $p = $this->path($key);

        return is_file($p) && @unlink($p);
    }

    /** Stream a stored photo; 400 for a bad key, 404 when missing. */
    public function stream(string $key): Response
    {
        if ($key === '' || str_contains($key, '..')) {
            return new Response('', 400);
        }
        $p = $this->path($key);
        if (! is_file($p)) {
            return new Response('', 404);
        }

        return new BinaryFileResponse($p, 200, [
            'Content-Type' => self::contentType(strtolower(pathinfo($p, PATHINFO_EXTENSION))),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // ponytail: orphan files are not garbage-collected in v1 — an item PATCH that
    // clears `foto_key` leaves the file behind. Add GC when it actually accumulates.
}
