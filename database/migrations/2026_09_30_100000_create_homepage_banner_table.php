<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * homepage in core: the promo slider rows for the public website — one manual
 * order over two sources. `sumber = unggah` is a banner uploaded in the
 * Homepage panel; `sumber = bd` is a switch row for a BD OS promo whose poster
 * and period stay in BD (`BdState::setting('promos')`) — Homepage never writes
 * a single BD row. Greenfield: no legacy_id, no deleted_at, no importer.
 *
 * `promo_id` is unique because one BD promo has at most one switch row; MySQL
 * treats NULLs as distinct, so the `unggah` rows (NULL) are not constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; created_by/updated_by FK `user` (identity is in core).
        $s->create('homepage_banner', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('sumber', 8);                                // bd | unggah
            $t->string('promo_id', 64)->nullable()->unique();        // BD promo id, required when sumber=bd
            $t->string('gambar_key', 190)->nullable();               // storage/app/homepage key, required when sumber=unggah
            $t->string('alt', 190)->nullable();
            $t->string('href', 500)->nullable();                     // https://… or /relative
            $t->date('mulai')->nullable();                           // optional window, unggah only
            $t->date('selesai')->nullable();
            $t->boolean('tampil')->default(false);                   // the switch: default OFF
            $t->integer('urutan')->default(0);                       // one manual order for both sources
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();

            $t->index(['tampil', 'urutan']);

            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('homepage_banner');
    }
};
