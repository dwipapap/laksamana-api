<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS in core (B4: push reminders + geofence). Greenfield — ADR-0009,
 * docs/modules/hris.md (Reminders 1–7, via Expo → FCM/APNs).
 *
 *   hris_push_token   Expo push tokens per user (moved between users by
 *                     re-registering; DeviceNotRegistered deletes them)
 *   hris_reminder_log idempotency: one row per (jenis, ref, user) sent —
 *                     a repeated run never pushes twice
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        };

        $s->create('hris_push_token', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64);
            $t->string('token', 190)->unique();
            $t->string('platform', 16)->default('android'); // android | ios
            $t->timestamp('terakhir_dilihat')->nullable();
            $tech($t);
            $t->index(['user_id']);
        });

        $s->create('hris_reminder_log', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('jenis', 64);
            $t->string('ref', 128)->default('');
            $t->string('user_id', 64);
            $t->bigInteger('waktu')->default(0);
            $tech($t);
            $t->unique(['jenis', 'ref', 'user_id']);
            $t->index(['user_id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_reminder_log', 'hris_push_token'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
