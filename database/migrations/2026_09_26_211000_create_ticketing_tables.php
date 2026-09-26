<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ticketing in core (#61, PRD #1): the public shop's OWN tables. The EMS tables
 * it uses (events, ticket_classes, seats, orders, tickets) belong to the event
 * module and live in event_* (#61); ticketing reaches them through EventState
 * (ADR-0002), never directly. ERD and mapping: docs/db/event.md.
 *
 * These five tables are plain relational rows in legacy (no `data` JSON), so
 * they are normalised here: the legacy id moves to `legacy_id` (the legacy
 * primary key for tix_sessions/tix_reset is `token`) and `version` counts
 * accepted writes. `expires_at`/`created_at`/`at` keep their millisecond
 * meaning, because the shop math they feed is in milliseconds.
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
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
        };

        // tix_users: the Buyer (CONTEXT.md) — a member of the public, not a User.
        $s->create('ticketing_buyers', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('email', 191)->nullable()->unique();
            $t->string('pass_hash', 255)->nullable();
            $t->string('name', 255)->nullable();
            $t->string('phone', 32)->nullable();
            $t->bigInteger('created_at')->default(0)->index();
            $tech($t);
        });
        $s->table('ticketing_buyers', $actors);

        // tix_sessions / tix_reset: the legacy primary key is the token.
        foreach (['ticketing_sessions', 'ticketing_resets'] as $name) {
            $s->create($name, function (Blueprint $t) use ($row, $tech): void {
                $row($t);
                $t->string('user_id', 64)->nullable()->index();     // legacy Buyer id, kept verbatim
                $t->ulid('buyer_id')->nullable();                    // …as a real FK
                $t->bigInteger('expires_at')->default(0)->index();
                $t->bigInteger('created_at')->default(0);
                $tech($t);
            });
            $s->table($name, function (Blueprint $t) use ($actors): void {
                $t->foreign('buyer_id')->references('id')->on('ticketing_buyers')->nullOnDelete();
                $actors($t);
            });
        }

        // seat_holds: UNIQUE seat_id is what stops two buyers holding one seat.
        $s->create('ticketing_seat_holds', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('event_id', 64)->nullable()->index();
            $t->string('seat_id', 64)->nullable()->unique();
            $t->string('hold_token', 64)->nullable()->index();
            $t->string('order_id', 64)->nullable()->index();
            $t->bigInteger('expires_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
            $tech($t);
        });
        $s->table('ticketing_seat_holds', $actors);

        // tix_gagal: rate-limit hashes (append + sweep).
        $s->create('ticketing_gagal', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('kunci', 64)->nullable();
            $t->bigInteger('at')->default(0);
            $t->index(['kunci', 'at']);
            $t->index('at');
            $tech($t);
        });
        $s->table('ticketing_gagal', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['ticketing_gagal', 'ticketing_seat_holds', 'ticketing_resets',
            'ticketing_sessions', 'ticketing_buyers'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
