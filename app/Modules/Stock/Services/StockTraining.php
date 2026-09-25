<?php

namespace App\Modules\Stock\Services;

use App\Support\Modules;

/**
 * Training = the two folders of raw POS exports the forecast model reads.
 * Legacy only stores and lists files; it never opens the spreadsheet. The
 * filename from the browser is never used as a path: it is basenamed, reduced
 * to a safe stem and stamped, exactly like pur_training_nama().
 */
class StockTraining
{
    public const TARGETS = ['usage' => 'usage', 'sales_detail' => 'sales_detail'];

    public const MAX_BYTES = 12 * 1024 * 1024;

    public function dir(): string
    {
        $dir = (string) Modules::dataDir('stock');
        if ($dir === '') {
            throw new StockTrainingError(500, 'folder data latih tidak bisa dibuat');
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new StockTrainingError(500, 'folder data latih tidak bisa dibuat');
        }

        return realpath($dir) ?: $dir;
    }

    public function summary(): array
    {
        $out = ['status' => 'success'];
        foreach (self::TARGETS as $target => $sub) {
            $files = $this->paths($target);
            $latest = $files ? max(array_map('filemtime', $files)) : null;
            $out[$target] = $latest === null
                ? ['files' => 0, 'last_date' => null, 'next_from' => null]
                : ['files' => count($files), 'last_date' => gmdate('d M Y', $latest), 'next_from' => gmdate('d M Y', $latest + 86400)];
        }

        return $out;
    }

    /** @return list<array{name:string,size:int,mtime:int}> */
    public function list(string $target): array
    {
        $this->targetDir($target);
        $out = [];
        foreach ($this->paths($target) as $path) {
            $out[] = ['name' => basename($path), 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
        }

        return $out;
    }

    public function path(string $target, string $name): string
    {
        $dir = $this->targetDir($target);
        $name = $this->safeName($name, false);
        if ($name === null) {
            throw new StockTrainingError(400, 'nama berkas tidak sah');
        }
        $path = $dir.'/'.$name;
        if (! is_file($path)) {
            throw new StockTrainingError(404, 'berkas tidak ada');
        }

        return $path;
    }

    /** compat POST: extension + strict base64 only, exactly like training.php. */
    public function saveCompat(string $target, mixed $filename, mixed $content): array
    {
        $dir = $this->targetDir($target, 'target tidak dikenal');
        $name = $this->stampedName((string) $filename);
        if ($name === null) {
            return ['status' => 'error', 'message' => 'hanya berkas .xlsx/.xls'];
        }
        $bytes = base64_decode((string) $content, true);
        if ($bytes === false || $bytes === '') {
            return ['status' => 'error', 'message' => 'isi berkas tidak terbaca'];
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return ['status' => 'error', 'message' => 'folder data latih tidak bisa dibuat'];
        }
        if (@file_put_contents($dir.'/'.$name, $bytes) === false) {
            return ['status' => 'error', 'message' => 'gagal menyimpan berkas'];
        }

        return ['status' => 'success', 'saved_as' => $name, 'size_kb' => (int) round(strlen($bytes) / 1024)];
    }

    /** v1 upload: the new contract also checks size and the xlsx/xls magic bytes. */
    public function saveV1(string $target, mixed $filename, mixed $content): array
    {
        $dir = $this->targetDir($target);
        $name = $this->stampedName((string) $filename);
        if ($name === null) {
            throw new StockTrainingError(422, 'Hanya berkas .xlsx/.xls.');
        }
        $bytes = base64_decode((string) $content, true);
        if ($bytes === false || $bytes === '') {
            throw new StockTrainingError(422, 'Isi berkas tidak terbaca.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new StockTrainingError(422, 'Berkas melebihi 12 MB.');
        }
        $xlsx = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx';
        $magic = $xlsx ? str_starts_with($bytes, "PK\x03\x04") : str_starts_with($bytes, "\xD0\xCF\x11\xE0");
        if (! $magic) {
            throw new StockTrainingError(422, $xlsx ? 'Berkas .xlsx tidak valid.' : 'Berkas .xls tidak valid.');
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new StockTrainingError(503, 'Folder data latih tidak bisa dibuat.');
        }
        if (@file_put_contents($dir.'/'.$name, $bytes) === false) {
            throw new StockTrainingError(503, 'Gagal menyimpan berkas.');
        }

        return ['name' => $name, 'size' => strlen($bytes), 'target' => $target];
    }

    private function targetDir(string $target, string $unknown = 'target tidak dikenal'): string
    {
        if (! isset(self::TARGETS[$target])) {
            throw new StockTrainingError(400, $unknown);
        }

        return $this->dir().'/'.self::TARGETS[$target];
    }

    /** @return list<string> */
    private function paths(string $target): array
    {
        $dir = $this->dir().'/'.self::TARGETS[$target];
        if (! is_dir($dir)) {
            return [];
        }
        $files = array_merge(glob($dir.'/*.xlsx') ?: [], glob($dir.'/*.xls') ?: []);
        sort($files, SORT_STRING);

        return array_values(array_filter($files, 'is_file'));
    }

    private function stampedName(string $raw): ?string
    {
        $base = basename(str_replace('\\', '/', $raw));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (! in_array($ext, ['xlsx', 'xls'], true)) {
            return null;
        }
        $stem = preg_replace('/[^A-Za-z0-9 _.-]/', '_', pathinfo($base, PATHINFO_FILENAME));
        $stem = trim(substr((string) $stem, 0, 80)) ?: 'data';

        return gmdate('Ymd-His').'_'.$stem.'.'.$ext;
    }

    private function safeName(string $raw, bool $stamped): ?string
    {
        $base = basename(str_replace('\\', '/', $raw));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($base === '' || ! in_array($ext, ['xlsx', 'xls'], true)) {
            return null;
        }

        return $base;
    }
}
