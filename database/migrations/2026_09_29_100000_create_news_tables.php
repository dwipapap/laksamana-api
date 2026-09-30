<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * News in core: the homepage "Kabar dari Laksamana" rail, the public /news
 * listing + detail and the Office panel. Greenfield — no legacy backend, no
 * legacy_id, no importer. Spec: laksamana-homepage docs/12-NEWS-API-REQUEST.md.
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

        $s->create('news_kategori', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->string('nama', 120);
            $t->string('slug', 120)->unique();
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $tech($t);
            $actors($t);
            $t->index(['urutan']);
        });

        $s->create('news_artikel', function (Blueprint $t) use ($tech, $actors): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('kategori_id')->constrained('news_kategori')->restrictOnDelete();
            $t->string('judul', 190);
            $t->string('slug', 190)->unique();
            $t->text('ringkasan')->nullable();
            $t->longText('isi')->nullable();
            $t->string('cover_key', 190)->nullable();
            $t->string('penulis', 120)->nullable();
            $t->boolean('tampil')->default(false);
            $t->dateTime('published_at')->nullable();
            $tech($t);
            $actors($t);
            $t->index(['tampil', 'published_at']);
            $t->index(['kategori_id', 'published_at']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['news_artikel', 'news_kategori'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
