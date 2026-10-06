<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Found by core:import erp-event against production (event-tiket.md):
 * an event's budget lines carry money, so they get rows instead of JSON;
 * and a talent payment can be rejected (legacy status Rejected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('event_anggaran', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('event_id')->constrained('event')->cascadeOnDelete();
            $t->unsignedSmallInteger('urutan');
            $t->string('item', 190);
            $t->string('jenis', 40)->nullable();
            $t->decimal('anggaran', 15, 0)->default(0);
            $t->decimal('realisasi', 15, 0)->nullable();
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
            $t->unique(['event_id', 'urutan']);
        });
        $db = DB::connection('core');
        $db->statement('ALTER TABLE `event_anggaran` ADD CONSTRAINT `event_anggaran_chk_0` CHECK (anggaran >= 0 AND (realisasi IS NULL OR realisasi >= 0))');
        $db->statement('ALTER TABLE `pembayaran_talent` DROP CONSTRAINT `pembayaran_talent_chk_2`');
        $db->statement("ALTER TABLE `pembayaran_talent` ADD CONSTRAINT `pembayaran_talent_chk_2` CHECK (status IN ('pending','confirmed','paid','rejected'))");
    }

    public function down(): void
    {
        $db = DB::connection('core');
        $db->statement('ALTER TABLE `pembayaran_talent` DROP CONSTRAINT `pembayaran_talent_chk_2`');
        $db->statement("ALTER TABLE `pembayaran_talent` ADD CONSTRAINT `pembayaran_talent_chk_2` CHECK (status IN ('pending','confirmed','paid'))");
        Schema::connection('core')->dropIfExists('event_anggaran');
    }
};
