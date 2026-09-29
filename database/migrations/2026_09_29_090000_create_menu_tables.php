<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Menu in core (docs/db/menu.md): the sellable menu of the homepage and the
 * Office panel. Greenfield — no legacy backend, no legacy_id, no importer.
 * ERD and the full column list: docs/db/menu.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; created_by/updated_by FK `user` (identity is in core).
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };

        $s->create('menu_kategori', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->enum('jenis', ['makanan', 'minuman'])->index();
            $t->string('nama', 120);
            $t->string('slug', 120)->unique();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t);
            $actors($t);
            $t->index(['jenis', 'urutan']);
        });

        $s->create('menu_item', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('kategori_id')->constrained('menu_kategori')->restrictOnDelete();
            $t->enum('bagian', ['main_course', 'snack'])->nullable();
            $t->string('nama', 190);
            $t->string('slug', 190)->unique();
            $t->text('deskripsi')->nullable();
            $t->text('komponen')->nullable();
            $t->string('foto_key', 190)->nullable();
            $t->boolean('unggulan')->default(false);
            $t->boolean('rekomendasi')->default(false);
            $t->boolean('pedas')->default(false);
            $t->boolean('vegetarian')->default(false);
            $t->boolean('ramah_anak')->default(false);
            $t->boolean('tampil')->default(true);
            $t->boolean('tersedia')->default(true);
            $t->text('catatan_internal')->nullable();
            $t->integer('urutan')->default(0);
            $tech($t);
            $actors($t);
            $t->index(['kategori_id', 'urutan']);
            $t->index(['tampil', 'unggulan']);
        });

        $s->create('menu_varian', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('item_id')->constrained('menu_item')->cascadeOnDelete();
            $t->string('label', 60)->default('');
            $t->unsignedInteger('harga')->default(0);
            $t->integer('urutan')->default(0);
            $tech($t);
            $actors($t);
            $t->index(['item_id', 'urutan']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['menu_varian', 'menu_item', 'menu_kategori'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
