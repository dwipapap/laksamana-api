<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Production data uses one name for a menu and its prasmanan version (and for
 * a base and the dish built on it), so a recipe name is unique per jenis AND
 * kategori, not per jenis (docs/erp/resep-hpp.md, found by core:import
 * erp-resep against a production copy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('resep', function (Blueprint $t): void {
            $t->unique(['jenis', 'kategori', 'nama']);
            $t->dropUnique(['jenis', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('resep', function (Blueprint $t): void {
            $t->unique(['jenis', 'nama']);
            $t->dropUnique(['jenis', 'kategori', 'nama']);
        });
    }
};
