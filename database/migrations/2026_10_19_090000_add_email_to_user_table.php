<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contact email on core `user` (#email): nullable + unique, '' = NULL like
 * `username`, so many Users without one stay allowed. Legacy `users` gets the
 * same column via a manual ALTER (docs/db/identity.md) — it has no migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $t): void {
            $t->string('email', 190)->nullable()->unique()->after('username');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('user', function (Blueprint $t): void {
            $t->dropUnique(['email']);
            $t->dropColumn('email');
        });
    }
};
