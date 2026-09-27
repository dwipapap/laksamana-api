<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reservasi in core (#71, PRD #1): the per-row reservations table (indexed
 * columns + the full `data` JSON, exactly as the app edits it), the
 * append-only audit trail and the settings documents (`master`, `_ver`).
 * Every table takes the reservasi_ prefix. ERD and legacy mapping:
 * docs/db/reservasi.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. The legacy rows carry no Office User, so
        // created_by/updated_by stay NULL. `version` counts accepted writes.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };

        // 1 row per reservation. `updated_at`/`created_at` ARE the app's
        // millisecond stamps (the row version on the wire and the ordering
        // guard in the upsert), so they stay legacy bigints — not Laravel
        // timestamps. `data` is the source of truth; the columns before it are
        // derived from the row on every write, exactly as legacy does.
        $s->create('reservasi_reservations', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->string('name', 255)->nullable();
            $t->string('phone', 32)->nullable()->index();
            $t->date('tanggal')->nullable()->index();
            $t->string('jam', 8)->nullable();
            $t->integer('pax')->default(0);
            $t->string('status', 32)->nullable()->index();
            $t->string('pic_name', 255)->nullable();
            $t->string('source', 64)->nullable();
            $t->bigInteger('dp_amount')->default(0);
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('reservasi_reservations', $actors);

        // Append-only trail (INSERT IGNORE by legacy id, trimmed to the newest
        // 500). No created_at/updated_at: the legacy rule is `ts` alone, and
        // inventing a second stamp would change nothing on the wire.
        $s->create('reservasi_audit', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->bigInteger('ts')->default(0)->index();
            $t->longText('data');
            $tech($t);
        });
        $s->table('reservasi_audit', $actors);

        // master (one JSON blob shared with Service Excellent) and _ver, the
        // GLOBAL version every saveAll must name (baseVer). `k` is the legacy
        // key, so this table is matched on `k`, not on a legacy_id.
        $s->create('reservasi_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('reservasi_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['reservasi_pengaturan', 'reservasi_audit', 'reservasi_reservations'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
