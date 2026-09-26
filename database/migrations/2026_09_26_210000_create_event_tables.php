<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * EMS in core (#61, PRD #1): the event module's tables (the indexed columns +
 * the full `data` JSON the Event Planner edits verbatim) and its settings
 * documents. Ticketing shares this legacy database; the EMS tables it uses
 * live HERE and are reached through EventState (ADR-0002), never directly.
 * ERD and mapping: docs/db/event.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. EMS actors are free-text names inside the
        // rows, so created_by/updated_by stay NULL. The legacy millisecond
        // stamps are the change record and the row version compat/v1 expose;
        // `version` counts accepted writes.
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
        $ms = function (Blueprint $t): void {
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
        };
        $table = function (string $name, Closure $cols) use ($s, $row, $ms, $tech, $actors): void {
            $s->create($name, function (Blueprint $t) use ($row, $cols, $ms, $tech): void {
                $row($t);
                $cols($t);
                $ms($t);
                $tech($t);
            });
            $s->table($name, $actors);
        };

        $table('event_talents', function (Blueprint $t): void {
            $t->string('name', 255)->nullable();
            $t->string('category', 64)->nullable()->index();
            $t->string('phone', 32)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->string('contract_status', 32)->nullable();
            $t->bigInteger('default_fee')->default(0);
        });
        $table('event_events', function (Blueprint $t): void {
            $t->string('title', 255)->nullable();
            $t->string('category', 64)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->string('venue', 255)->nullable();
            $t->dateTime('start_datetime')->nullable()->index();
            $t->dateTime('end_datetime')->nullable();
            $t->integer('capacity')->default(0);
            $t->string('pic', 255)->nullable();
            $t->boolean('is_ticketed')->default(false);
            $t->string('idea_id', 64)->nullable()->index();
        });
        // one row per event: the legacy primary key IS the event id, so the
        // natural key stays `event_id` (unique) instead of a separate legacy_id.
        $table('event_event_details', function (Blueprint $t): void {
            $t->string('event_id', 64)->nullable()->unique();
        });
        $table('event_schedules', function (Blueprint $t): void {
            $t->string('talent_id', 64)->nullable()->index();
            $t->string('event_id', 64)->nullable()->index();
            $t->date('tanggal')->nullable()->index();
            $t->string('start_time', 8)->nullable();
            $t->string('end_time', 8)->nullable();
            $t->string('performance_type', 64)->nullable();
            $t->bigInteger('fee')->default(0);
            $t->string('status', 32)->nullable()->index();
            $t->string('source', 32)->nullable();
            $t->index(['talent_id', 'tanggal', 'status']);
        });
        $table('event_recurring_rules', function (Blueprint $t): void {
            $t->string('talent_id', 64)->nullable()->index();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
        });
        $table('event_talent_payments', function (Blueprint $t): void {
            $t->string('talent_id', 64)->nullable()->index();
            $t->string('period_month', 7)->nullable()->index();
            $t->integer('show_count')->default(0);
            $t->bigInteger('total_amount')->default(0);
            $t->string('status', 32)->nullable()->index();
        });
        $table('event_ticket_classes', function (Blueprint $t): void {
            $t->string('event_id', 64)->nullable()->index();
            $t->string('name', 255)->nullable();
            $t->bigInteger('price')->default(0);
            $t->integer('quota')->default(0);
            $t->integer('sold')->default(0);
            $t->boolean('is_seated')->default(false);
        });
        $table('event_seats', function (Blueprint $t): void {
            $t->string('event_id', 64)->nullable()->index();
            $t->string('ticket_class_id', 64)->nullable()->index();
            $t->string('zone', 64)->nullable();
            $t->string('table_no', 32)->nullable();
            $t->string('status', 32)->nullable();
        });

        $table('event_orders', function (Blueprint $t): void {
            $t->string('event_id', 64)->nullable()->index();
            $t->string('buyer_name', 255)->nullable();
            $t->string('phone', 32)->nullable()->index();
            $t->string('email', 255)->nullable();
            $t->bigInteger('total')->default(0);
            $t->string('payment_status', 32)->nullable()->index();
            $t->string('payment_ref', 64)->nullable()->index();
        });
        $table('event_tickets', function (Blueprint $t): void {
            $t->string('order_item_id', 64)->nullable()->index();
            $t->string('ticket_class_id', 64)->nullable();
            $t->string('seat_id', 64)->nullable()->index();
            $t->string('ticket_number', 64)->nullable();
            $t->string('qr_token', 191)->nullable()->unique();
            $t->string('status', 32)->nullable()->index();
        });
        // append-only: INSERT IGNORE, never updated or deleted.
        $table('event_checkins', function (Blueprint $t): void {
            $t->string('ticket_id', 64)->nullable()->index();
            $t->dateTime('checked_in_at')->nullable()->index();
            $t->string('staff', 255)->nullable();
            $t->string('gate', 64)->nullable();
            $t->string('result', 32)->nullable();
        });
        $table('event_refunds', function (Blueprint $t): void {
            $t->string('order_id', 64)->nullable()->index();
            $t->string('status', 32)->nullable()->index();
        });
        $table('event_ideas', function (Blueprint $t): void {
            $t->string('name', 255)->nullable();
            $t->string('category', 64)->nullable()->index();
            $t->string('frequency', 32)->nullable();
            $t->string('difficulty', 32)->nullable();
        });
        $table('event_calendar_extra', function (Blueprint $t): void {
            $t->string('type', 32)->nullable()->index();
            $t->string('title', 255)->nullable();
            $t->date('tanggal')->nullable()->index();
        });

        // entertainmentRules, role, layoutTemplates (one JSON value each).
        $s->create('event_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('event_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['event_pengaturan', 'event_calendar_extra', 'event_ideas', 'event_refunds',
            'event_checkins', 'event_tickets', 'event_orders', 'event_seats',
            'event_ticket_classes', 'event_talent_payments', 'event_recurring_rules',
            'event_schedules', 'event_event_details', 'event_events', 'event_talents'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
