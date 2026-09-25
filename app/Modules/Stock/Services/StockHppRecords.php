<?php

namespace App\Modules\Stock\Services;

use Closure;
use RuntimeException;

/**
 * Single-record access for /api/v1/stock/hpp on top of StockHpp (so every legacy
 * rule — rename follow, Purchasing registration, number parsing — applies).
 *
 * Versions are content hashes (`updated_at` is not bumped by the rename cascade
 * into recipe lines, so it cannot be trusted as a version): an ingredient or a
 * recipe hashes its stored row, a month hashes its usage payload, the settings
 * hash their values. Writes check the version under SELECT … FOR UPDATE inside
 * one transaction.
 */
class StockHppRecords
{
    public function __construct(private readonly StockHpp $hpp) {}

    private static function db()
    {
        return StockSupport::db();
    }

    private function guarded(Closure $current, ?string $version, Closure $fn): mixed
    {
        return self::db()->transaction(function () use ($current, $version, $fn) {
            $cur = $current();
            if ($cur === null) {
                throw new StockConflict('not_found');
            }
            if ($version !== null && ! hash_equals($cur['version'], $version)) {
                throw new StockConflict('stale', $cur['record']);
            }

            return $fn($cur);
        });
    }

    /** A legacy RuntimeException ("Nama bahan kosong", …) is a 422 on v1. */
    private static function invalid(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            if ($e instanceof StockConflict) {
                throw $e;
            }
            throw new StockConflict('invalid', $e->getMessage());
        }
    }

    // ─────────────────────────────── ingredients ──

    /** @return list<array{record:array, version:string}> */
    public function ingredients(): array
    {
        return array_map(fn ($r) => ['record' => StockHpp::ingredientRow($r), 'version' => StockRecords::hash((array) $r)],
            self::db()->select('SELECT * FROM hpp_bahan ORDER BY nama'));
    }

    public function ingredient(string $nama, bool $lock = false): ?array
    {
        $r = self::db()->selectOne('SELECT * FROM hpp_bahan WHERE nama = ?'.($lock ? ' FOR UPDATE' : ''), [$nama]);

        return $r ? ['record' => StockHpp::ingredientRow($r), 'version' => StockRecords::hash((array) $r)] : null;
    }

    /**
     * Create ($nama null) or change one ingredient; only the fields in $f change.
     * `nama` different from $nama renames (recipes and usage follow). A name taken
     * by another row (case-insensitive, like the key) is a 409.
     */
    public function saveIngredient(?string $nama, array $f, ?string $version, string $by): array
    {
        $target = StockHpp::txt($f['nama'] ?? $nama ?? '', 190);
        $fn = function (?array $cur) use ($nama, $f, $target, $by) {
            if ($target !== '' && ($cur === null || $target !== $nama) && $this->ingredient($target, true)) {
                throw new StockConflict('exists');
            }
            $keep = ['satuan', 'qty_beli', 'harga_beli', 'vendor', 'produk', 'kategori', 'catatan', 'di_purchasing', 'sisi_harga'];
            $d = array_intersect_key($cur['record'] ?? [], array_flip($keep));
            $d = (object) (array_merge($d, array_intersect_key($f, array_flip($keep))) + ['nama' => $target]);
            if ($cur && $target !== $nama) {
                $d->namaLama = $nama;
            }
            $res = self::invalid(fn () => $this->hpp->saveIngredientAction($d, $by));

            return ['row' => $this->ingredient($res['nama']), 'report' => array_diff_key($res, array_flip(['status', 'saved', 'nama']))];
        };

        return $nama === null ? self::db()->transaction(fn () => $fn(null))
            : $this->guarded(fn () => $this->ingredient($nama, true), $version, $fn);
    }

    public function deleteIngredient(string $nama, string $version): void
    {
        $this->guarded(fn () => $this->ingredient($nama, true), $version, fn () => $this->hpp->deleteIngredient($nama));
    }

    /** gabungBahan with the source's version: its recipe lines and usage move to $into, then it is deleted. */
    public function mergeIngredient(string $from, string $into, string $version): array
    {
        return $this->guarded(fn () => $this->ingredient($from, true), $version, function () use ($from, $into) {
            $res = $this->hpp->merge($from, $into);
            if ($res['status'] !== 'success') {
                throw new StockConflict('invalid', $res['message']);
            }

            return ['recipes' => $res['resep'], 'into' => $this->ingredient(StockHpp::txt($into, 190))];
        });
    }

    // ─────────────────────────────── recipes ──

    public function recipes(): array
    {
        return array_map(fn ($r) => ['record' => StockHpp::recipeRow($r), 'version' => StockRecords::hash((array) $r)],
            self::db()->select('SELECT * FROM hpp_resep ORDER BY jenis, tipe, nama'));
    }

    public function recipe(string $id, bool $lock = false): ?array
    {
        $r = self::db()->selectOne('SELECT * FROM hpp_resep WHERE id = ?'.($lock ? ' FOR UPDATE' : ''), [$id]);

        return $r ? ['record' => StockHpp::recipeRow($r), 'version' => StockRecords::hash((array) $r)] : null;
    }

    /** Create ($id null; a body `id` is kept, 409 when taken) or change one recipe (only the fields sent). */
    public function saveRecipe(?string $id, array $f, ?string $version, string $by): array
    {
        $fn = function (?array $cur) use ($id, $f, $by) {
            if ($cur === null && ($new = StockHpp::txt($f['id'] ?? '', 48)) !== '' && $this->recipe($new, true)) {
                throw new StockConflict('exists');
            }
            $keep = ['nama', 'jenis', 'tipe', 'seksi', 'kode', 'yield_qty', 'yield_unit', 'harga_lama', 'harga_baru',
                'harga_upsize', 'modal_manual', 'catatan', 'bahan', 'aktif', 'di_purchasing'];
            $d = array_merge(array_intersect_key($cur['record'] ?? [], array_flip($keep)), array_intersect_key($f, array_flip($keep)));
            $d['id'] = $cur ? $id : ($f['id'] ?? '');
            $res = self::invalid(fn () => $this->hpp->saveRecipeAction((object) $d, $by));

            return ['row' => $this->recipe($res['id']), 'report' => array_diff_key($res, array_flip(['status', 'saved', 'id', 'bahan']))];
        };

        return $id === null ? self::db()->transaction(fn () => $fn(null))
            : $this->guarded(fn () => $this->recipe($id, true), $version, $fn);
    }

    public function deleteRecipe(string $id, string $version): void
    {
        $this->guarded(fn () => $this->recipe($id, true), $version, fn () => $this->hpp->deleteRecipe($id));
    }

    // ─────────────────────────────── monthly usage & settings ──

    /** @return array{record:array, version:string} (the version ignores the list of other months) */
    public function month(string $bulan, bool $lock = false): array
    {
        if ($lock) {
            self::db()->select('SELECT bulan FROM hpp_bulan WHERE bulan = ? FOR UPDATE', [$bulan]);
            self::db()->select('SELECT bahan FROM hpp_pakai WHERE bulan = ? FOR UPDATE', [$bulan]);
        }
        $u = $this->hpp->usage($bulan);

        return ['record' => $u, 'version' => StockRecords::hash(array_diff_key($u, ['daftarBulan' => 1]))];
    }

    /** Upsert the month's rows (rows not sent are kept) and its sales figure. */
    public function saveMonth(string $bulan, array $f, string $version, string $by): array
    {
        return $this->guarded(fn () => $this->month($bulan, true), $version, function (array $cur) use ($bulan, $f, $by) {
            $d = (object) [
                'bulan' => $bulan,
                'baris' => $f['baris'] ?? [],
                'penjualan' => array_key_exists('penjualan', $f) ? $f['penjualan'] : $cur['record']['penjualan'],
                'catatan' => array_key_exists('catatan', $f) ? $f['catatan'] : $cur['record']['catatan'],
            ];
            self::invalid(fn () => $this->hpp->saveUsage($d, $by));

            return $this->month($bulan);
        });
    }

    public function settings(bool $lock = false): array
    {
        if ($lock) {
            self::db()->select('SELECT id FROM hpp_setting WHERE id = 1 FOR UPDATE');
        }
        $s = $this->hpp->settings();

        return ['record' => $s, 'version' => StockRecords::hash($s)];
    }

    public function saveSettings(array $f, string $version): array
    {
        return $this->guarded(fn () => $this->settings(true), $version, function () use ($f) {
            $this->hpp->saveSettings((object) $f);

            return $this->settings();
        });
    }
}
