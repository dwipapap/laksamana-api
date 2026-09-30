<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * homepage in core: the on/off switch for which EMS event appears on the public
 * website. Greenfield — no legacy backend, no legacy_id, no importer. The event
 * data itself stays in EMS (`event_*`), reached only through EventState.
 *
 * One row per event that has ever been touched: `event_id` is the EMS id, NOT a
 * foreign key (the EMS database is separate from core). No row = off.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; created_by/updated_by FK `user` (identity is in core).
        $s->create('homepage_event', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('event_id', 64)->unique();
            $t->boolean('tampil')->default(false);
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('homepage_event');
    }
};
