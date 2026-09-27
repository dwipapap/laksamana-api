<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Stock in core (#73, PRD #1): every legacy table of lakk5493_db_stock as a
 * stock_* table with its legacy columns, types, defaults and indexes verbatim
 * (the `data` JSON stays the source of truth, as in reservasi), a ULID `id`,
 * and `legacy_id` where legacy keyed rows by `id`. Natural keys (`nama`,
 * `nomor_order`, `bulan`) stay as they are, unique. ERD, mapping and the
 * deviations (no version/created_by/updated_by): docs/db/stock.md.
 */
return new class extends Migration
{
    /**
     * A lowercase ULID computed by the database: 48-bit ms time + 80 random
     * bits in Crockford base32 (CONV gives 0-9A-V; the REPLACE chain maps
     * I..V to Crockford's alphabet, highest first so nothing is mapped twice).
     * The stock services keep their legacy INSERT statements, so the row id
     * has to come from the column default. MySQL 8.0.13+ / MariaDB 10.2+.
     */
    private function ulidDefault(): Expression
    {
        $b32 = fn (string $e, int $n) => "LPAD(CONV($e,10,32),$n,'0')";
        $sql = 'CONCAT('.$b32('FLOOR(UNIX_TIMESTAMP(NOW(3))*1000)', 10).','
            .$b32('FLOOR(RAND()*1099511627776)', 8).','.$b32('FLOOR(RAND()*1099511627776)', 8).')';
        foreach (['V' => 'Z', 'U' => 'Y', 'T' => 'X', 'S' => 'W', 'R' => 'V', 'Q' => 'T', 'P' => 'S',
            'O' => 'R', 'N' => 'Q', 'M' => 'P', 'L' => 'N', 'K' => 'M', 'J' => 'K', 'I' => 'J'] as $from => $to) {
            $sql = "REPLACE($sql,'$from','$to')";
        }

        return new Expression("(LOWER($sql))");
    }

    public function up(): void
    {
        $s = Schema::connection('core');
        $id = fn (Blueprint $t) => $t->ulid('id')->primary()->default($this->ulidDefault());
        $str = fn (Blueprint $t, string $c, int $n, string $d = '') => $t->string($c, $n)->default($d);
        $num = fn (Blueprint $t, string $c, float $d = 0) => $t->double($c)->default($d);

        $s->create('stock_activity_log', function (Blueprint $t) use ($id, $str): void {
            $id($t);
            $t->string('legacy_id', 64)->unique();
            $str($t, 'waktu', 30);
            $str($t, 'tanggal', 20)->index();
            $str($t, 'modul', 30)->index();
            $str($t, 'aksi', 40)->index();
            $str($t, 'aktor', 120);
            $str($t, 'tim', 40);
            $str($t, 'ringkas', 500);
            $t->longText('data');
        });

        $s->create('stock_ck', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->string('legacy_id', 64)->unique();
            $str($t, 'tanggal', 20)->index();
            $str($t, 'item', 190)->index();
            $str($t, 'arah', 10)->index();
            $num($t, 'qty');
            $num($t, 'qty_input');
            $str($t, 'unit_input', 40);
            $str($t, 'sebab', 40);
            $str($t, 'status', 20)->index();
            $t->string('ref', 64)->nullable();
            $str($t, 'tim', 20);
            $str($t, 'pic', 120);
            $str($t, 'waktu', 30);
            $t->longText('data');
            $t->unique(['ref', 'arah']);
        });

        $s->create('stock_hpp_bahan', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->string('nama', 190)->unique();
            $str($t, 'satuan', 32);
            $num($t, 'qty_beli');
            $num($t, 'harga_beli');
            $str($t, 'vendor', 190);
            $str($t, 'produk', 190)->index();
            $str($t, 'kategori', 64);
            $str($t, 'catatan', 255);
            $t->bigInteger('updated_at')->default(0);
            $str($t, 'updated_by', 120);
            $t->boolean('di_purchasing')->default(1);
            $t->boolean('dibeli_jadi')->default(0);
            $str($t, 'sisi_harga', 8);
        });

        $s->create('stock_hpp_bulan', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->char('bulan', 7)->unique();
            $num($t, 'penjualan');
            $str($t, 'catatan', 255);
            $t->bigInteger('updated_at')->default(0);
            $str($t, 'updated_by', 120);
        });

        $s->create('stock_hpp_pakai', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->char('bulan', 7);
            $t->string('bahan', 190);
            foreach (['sa', 'beli', 'resep', 'spoil', 'team', 'rnd', 'comp', 'opname'] as $c) {
                $num($t, $c);
            }
            $t->bigInteger('updated_at')->default(0);
            $str($t, 'updated_by', 120);
            $t->unique(['bulan', 'bahan']);
        });

        $s->create('stock_hpp_resep', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->string('legacy_id', 48)->unique();
            $t->string('nama', 190)->index();
            $str($t, 'jenis', 16, 'food');
            $str($t, 'tipe', 16, 'base');
            $str($t, 'seksi', 96);
            $num($t, 'yield_qty', 1);
            $str($t, 'yield_unit', 32);
            foreach (['harga_lama', 'harga_baru', 'harga_upsize', 'modal_manual'] as $c) {
                $num($t, $c);
            }
            $t->text('catatan')->nullable();
            $t->longText('bahan');
            $t->boolean('aktif')->default(1);
            $t->bigInteger('updated_at')->default(0);
            $str($t, 'updated_by', 120);
            $t->boolean('di_purchasing')->default(0);
            $str($t, 'kode', 64);
            $t->index(['jenis', 'tipe']);
        });

        $s->create('stock_hpp_setting', function (Blueprint $t) use ($id): void {
            $id($t);
            $t->unsignedTinyInteger('legacy_id')->unique();
            $t->longText('data');
        });

        $s->create('stock_opname', function (Blueprint $t) use ($id, $str): void {
            $id($t);
            $t->string('legacy_id', 64)->unique();
            $str($t, 'tanggal', 20)->index();
            $str($t, 'pic', 120);
            $str($t, 'tim', 20);
            $str($t, 'status', 20, 'Draft')->index();
            $str($t, 'waktu', 30);
            $t->longText('data');
        });

        // the Ordering / Purchasing app users (PIN logins), not Office Users
        foreach (['stock_users', 'stock_ordering_users'] as $table) {
            $s->create($table, function (Blueprint $t) use ($id, $str): void {
                $id($t);
                $t->string('legacy_id', 64)->unique();
                $str($t, 'nama', 190);
                $str($t, 'pin', 20);
                $str($t, 'role', 20);
                $str($t, 'keterangan', 60);
                $t->longText('data');
            });
        }

        $s->create('stock_orders', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->string('nomor_order', 64)->unique();
            $t->integer('row_index')->unique();
            $str($t, 'waktu', 30);
            $str($t, 'item', 190)->index();
            $num($t, 'qty');
            $str($t, 'unit', 40);
            $str($t, 'tgl_datang', 20)->index();
            $str($t, 'pic', 120);
            $str($t, 'status', 20)->index();
            $str($t, 'kedatangan', 40);
            $str($t, 'batch_id', 64)->index();
            $str($t, 'batch_name', 120);
            $str($t, 'tim', 20);
            $t->longText('data');
            $t->index(['tim', 'status']);
        });

        $s->create('stock_products', function (Blueprint $t) use ($id, $str): void {
            $id($t);
            $t->string('nama', 190)->unique();
            $str($t, 'utama', 190)->index();
            $t->longText('data');
        });

        $s->create('stock_serah_terima', function (Blueprint $t) use ($id, $str): void {
            $id($t);
            $t->string('legacy_id', 64)->unique();
            $str($t, 'tanggal', 20)->index();
            $str($t, 'tujuan', 20)->index();
            $str($t, 'penerima', 120);
            $str($t, 'pic', 120);
            $str($t, 'tim', 20);
            $str($t, 'waktu', 30);
            $t->longText('foto');
            $str($t, 'foto_nama', 190);
            $t->longText('data');
        });

        // legacy `stock`: the "Stock Today" snapshot, rewritten whole on every upload
        $s->create('stock_snapshot', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->string('nama', 190)->unique();
            $num($t, 'stock_now');
            $str($t, 'stock_unit', 40);
            $str($t, 'as_of', 30);
            $t->longText('data');
        });

        $s->create('stock_usage_events', function (Blueprint $t) use ($id, $str): void {
            $id($t);
            $t->string('legacy_id', 64)->unique();
            $str($t, 'tanggal', 20)->index();
            $str($t, 'jenis', 40)->index();
            $str($t, 'nama_event', 190);
            $str($t, 'status', 20, 'Rencana')->index();
            $str($t, 'pic', 120);
            $str($t, 'tim', 20);
            $str($t, 'waktu', 30);
            $t->longText('data');
        });

        $s->create('stock_vendors', function (Blueprint $t) use ($id, $str): void {
            $id($t);
            $t->string('nama', 190)->unique();
            $str($t, 'whatsapp', 40);
            $t->longText('data');
        });

        $s->create('stock_waste', function (Blueprint $t) use ($id, $str, $num): void {
            $id($t);
            $t->string('legacy_id', 64)->unique();
            $str($t, 'tanggal', 20)->index();
            $str($t, 'item', 190)->index();
            $num($t, 'qty');
            $str($t, 'unit', 40);
            $str($t, 'sebab', 40)->index();
            $str($t, 'pic', 120);
            $str($t, 'tim', 20);
            $str($t, 'waktu', 30);
            $t->longText('foto');
            $str($t, 'foto_nama', 190);
            $t->longText('data');
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['stock_activity_log', 'stock_ck', 'stock_hpp_bahan', 'stock_hpp_bulan', 'stock_hpp_pakai',
            'stock_hpp_resep', 'stock_hpp_setting', 'stock_opname', 'stock_users', 'stock_ordering_users',
            'stock_orders', 'stock_products', 'stock_serah_terima', 'stock_snapshot', 'stock_usage_events',
            'stock_vendors', 'stock_waste'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
