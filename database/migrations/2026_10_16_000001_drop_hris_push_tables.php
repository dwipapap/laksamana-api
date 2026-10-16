<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HRIS drops push + geofence (PWA pivot, ADR-0010): Expo device tokens and
 * the reminder idempotency log are gone. Reminders become perhatian[],
 * computed on GET /hris/me/today with no scheduler and no stored state.
 *
 * Never run against a live server from here; this runs locally through the
 * test harness and, once, on dev via the human gate (php artisan migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');
        foreach (['hris_reminder_log', 'hris_push_token'] as $table) {
            $s->dropIfExists($table);
        }
    }

    public function down(): void
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
};
