<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BD OS in core (#59, PRD #1): the eight row tables (indexed columns + the full
 * `data` JSON, exactly as the app edits them) and the settings documents.
 * Every table takes the bd_ prefix. ERD and legacy mapping: docs/db/bd.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns; actors are free-text names inside the rows,
        // so created_by/updated_by stay NULL. The legacy millisecond stamps
        // (`updated_at`, `created_at`) are the change record and the row version
        // v1 exposes; `version` counts accepted writes.
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

        // BD crew. office_user_id is the legacy Office User id the app links;
        // user_id is that same User in core (NULL when unlinked or unknown).
        $table('bd_people', function (Blueprint $t): void {
            $t->string('name', 255)->nullable();
            $t->string('role', 128)->nullable();
            $t->string('divisi', 64)->nullable()->index();
            $t->string('boss_id', 64)->nullable()->index();
            $t->string('office_user_id', 64)->nullable()->index();
            $t->ulid('user_id')->nullable();
            $t->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $t->boolean('active')->default(true);
        });
        $table('bd_projects', function (Blueprint $t): void {
            $t->string('name', 255)->nullable();
            $t->string('type', 64)->nullable();
            $t->string('stage', 32)->nullable()->index();
            $t->string('divisi', 64)->nullable()->index();
            $t->string('pic', 64)->nullable()->index();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable()->index();
            $t->bigInteger('budget')->default(0);
            $t->bigInteger('spent')->default(0);
            $t->string('health', 16)->nullable();
        });
        $table('bd_tasks', function (Blueprint $t): void {
            $t->string('name', 255)->nullable();
            $t->string('divisi', 64)->nullable();
            $t->string('pic', 64)->nullable()->index();
            $t->string('status', 32)->nullable()->index();
            $t->string('priority', 16)->nullable();
            $t->date('deadline')->nullable()->index();
            $t->boolean('important')->default(false);
            $t->boolean('urgent')->default(false);
            $t->string('type', 32)->nullable();
            $t->string('project_id', 64)->nullable()->index();
            $t->integer('progress')->default(0);
            $t->index(['status', 'important', 'urgent']);
        });
        $table('bd_routines', function (Blueprint $t): void {
            $t->string('name', 255)->nullable();
            $t->string('divisi', 64)->nullable()->index();
            $t->string('pic', 64)->nullable()->index();
            $t->string('freq', 16)->nullable();
            $t->boolean('important')->default(false);
            $t->boolean('urgent')->default(false);
            $t->boolean('active')->default(true)->index();
        });
        $table('bd_coord_requests', function (Blueprint $t): void {
            $t->string('title', 255)->nullable();
            $t->string('from_div', 64)->nullable();
            $t->string('to_div', 64)->nullable()->index();
            $t->string('requested_by', 64)->nullable();
            $t->string('assignee', 128)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->string('priority', 16)->nullable();
            $t->date('due_date')->nullable()->index();
            $t->string('project_id', 64)->nullable()->index();
        });
        $table('bd_purchase_orders', function (Blueprint $t): void {
            $t->string('item', 255)->nullable();
            $t->string('vendor', 255)->nullable();
            $t->integer('qty')->default(1);
            $t->string('unit', 32)->nullable();
            $t->string('divisi', 64)->nullable();
            $t->bigInteger('amount')->default(0);
            $t->string('payment', 16)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->date('need_by')->nullable()->index();
            $t->string('pic', 64)->nullable();
            $t->string('project_id', 64)->nullable()->index();
            $t->string('pr_id', 64)->nullable()->index();
        });
        $table('bd_purchase_requests', function (Blueprint $t): void {
            $t->string('no', 32)->nullable()->index();
            $t->string('nama', 255)->nullable();
            $t->string('dept', 64)->nullable();
            $t->date('tanggal')->nullable();
            $t->date('week_start')->nullable()->index();
            $t->string('status', 32)->nullable()->index();
            $t->bigInteger('total')->default(0);
        });
        $table('bd_agenda', function (Blueprint $t): void {
            $t->string('title', 255)->nullable();
            $t->date('tanggal')->nullable()->index();
            $t->string('type', 32)->nullable();
            $t->string('divisi', 64)->nullable();
        });

        // focus, approverSets, promos (promos is also read by kompas through BdState)
        $s->create('bd_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('bd_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['bd_pengaturan', 'bd_agenda', 'bd_purchase_requests', 'bd_purchase_orders',
            'bd_coord_requests', 'bd_routines', 'bd_tasks', 'bd_projects', 'bd_people'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
