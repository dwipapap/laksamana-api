<?php

declare(strict_types=1);

namespace App\Modules\Homepage\Services;

use RuntimeException;

/**
 * Uploaded homepage banners. No legacy folder exists, so files live under
 * `storage/app/homepage/` (private) exactly like `NewsPhotos` does for news.
 *
 * The storage key is always server-made (`hb_<hex>.<ext>`); a client key or
 * data URL is never rendered — images reach the browser only through a
 * `homepage_banner` row id (docs/api/homepage.md §Promo security).
 */
class HomepagePhotos
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const PREFIX = 'hb_';

    /** mime => extension. Anything else is rejected with 422. */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function dir(): string
    {
        $d = storage_path('app/homepage');
        if (! is_dir($d) && ! @mkdir($d, 0775, true)) {
            throw new RuntimeException('Folder gambar homepage tidak bisa dibuat: '.$d);
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

    /** Whether `$key` names a stored file (the only keys a banner row may point at). */
    public function exists(string $key): bool
    {
        if ($key === '' || str_contains($key, '..')) {
            return false;
        }

        return is_file($this->path($key));
    }

    /**
     * Store an uploaded image. Returns `{key, url}`. `url` is always null by
     * design: the file is private and is only served through a
     * `homepage_banner` row id, never by its key.
     *
     * @param  array{name:string,tmp:string,size:int,mime:?string}  $file
     * @return array{key:string,url:null}
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
        $key = self::PREFIX.bin2hex(random_bytes(4)).'.'.self::TYPES[$mime];
        if (@file_put_contents($this->path($key), $bin) === false) {
            throw new RuntimeException('Gagal menulis berkas (cek izin folder).');
        }

        return ['key' => $key, 'url' => null];
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

    /** Read a stored file for the by-row-id image routes; null when absent. @return array{data:string,type:string}|null */
    public function read(string $key): ?array
    {
        if (! $this->exists($key)) {
            return null;
        }
        $bin = @file_get_contents($this->path($key));
        if ($bin === false || $bin === '') {
            return null;
        }

        return ['data' => $bin, 'type' => self::contentType(strtolower(pathinfo($key, PATHINFO_EXTENSION)))];
    }
}
