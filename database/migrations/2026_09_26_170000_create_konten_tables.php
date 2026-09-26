<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Konten in core (#55, PRD #1): content-production rows (indexed columns +
 * the full `data` JSON, like marketing #49), the append-only logs and the
 * settings documents. Legacy names collide across modules, so every table
 * takes the konten_ prefix. ERD and legacy mapping: docs/db/konten.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. created_by/updated_by stay NULL for
        // konten: actors are free-text names kept in the row itself, with no
        // link to an Office User. There are no Laravel timestamps: the legacy
        // millisecond stamps (`updated_at`, `created_at`) ARE the change
        // record and already carry those names, and `version` is the ADR
        // concurrency column.
        $tech = function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
        };
        $actors = function (Blueprint $t): void {
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        // Every row table keeps its legacy id in `legacy_id` and the full
        // row verbatim in `data` (open-ended production fields); the typed
        // columns are the query surface, derived from the row on every write.
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
        };
        $ms = function (Blueprint $t): void {
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
        };

        $s->create('konten_users', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('divisi', 64)->nullable()->index();
            $t->integer('capacity')->default(0);
            $t->string('avail', 32)->nullable()->index();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_users', $actors);

        $s->create('konten_brands', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_brands', $actors);

        $s->create('konten_campaigns', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $t->string('brand', 64)->nullable()->index();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_campaigns', $actors);

        $s->create('konten_content', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('title', 255)->nullable();
            $t->string('brand', 64)->nullable()->index();
            $t->string('campaign', 64)->nullable()->index();
            $t->string('platform', 32)->nullable();
            $t->string('pillar', 64)->nullable();
            $t->string('content_type', 64)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->string('priority', 16)->nullable();
            $t->string('pic', 64)->nullable()->index();
            $t->date('deadline')->nullable()->index();
            $t->date('publish_date')->nullable()->index();
            $t->string('publish_time', 8)->nullable();
            $t->bigInteger('updated_at')->default(0)->index();
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $t->index(['status', 'deadline']);
            $tech($t);
        });
        $s->table('konten_content', $actors);

        $s->create('konten_prod_tasks', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('kind', 32)->nullable();
            $t->string('title', 255)->nullable();
            $t->string('brand', 64)->nullable()->index();
            $t->string('pic', 64)->nullable()->index();
            $t->string('priority', 16)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->date('tanggal')->nullable()->index();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_prod_tasks', $actors);

        $s->create('konten_shootings', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('title', 255)->nullable();
            $t->date('tanggal')->nullable()->index();
            $t->string('location', 255)->nullable();
            $t->string('status', 32)->nullable()->index();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_shootings', $actors);

        $s->create('konten_assets', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $t->string('kind', 64)->nullable()->index();
            $t->string('by_user', 64)->nullable();
            $t->bigInteger('at_ms')->default(0)->index();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_assets', $actors);

        $s->create('konten_bank', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('owner', 64)->nullable()->index();
            $t->string('title', 255)->nullable();
            $t->string('brand', 64)->nullable();
            $t->string('platform', 32)->nullable();
            $t->string('kind', 64)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->bigInteger('at_ms')->default(0)->index();
            $ms($t);
            $tech($t);
        });
        $s->table('konten_bank', $actors);

        $s->create('konten_kols', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable()->index();
            $t->string('kol_type', 32)->nullable()->index();
            $t->string('instagram', 255)->nullable();
            $t->string('whatsapp', 32)->nullable();
            $t->bigInteger('rate_value')->default(0);
            $t->bigInteger('updated_at')->default(0);
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('konten_kols', $actors);

        $s->create('konten_visits', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('title', 255)->nullable();
            $t->string('kol_id', 64)->nullable()->index();
            $t->string('brand', 64)->nullable();
            $t->string('pic', 64)->nullable();
            $t->date('tanggal')->nullable()->index();
            $t->string('location', 255)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->bigInteger('updated_at')->default(0);
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('konten_visits', $actors);

        $s->create('konten_ads', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $t->string('brand', 64)->nullable()->index();
            $t->string('platform', 32)->nullable();
            $t->string('objective', 64)->nullable();
            $t->string('status', 32)->nullable()->index();
            $t->bigInteger('budget')->default(0);
            $t->bigInteger('spent')->default(0);
            $t->date('start_date')->nullable()->index();
            $t->date('end_date')->nullable();
            $t->bigInteger('updated_at')->default(0);
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('konten_ads', $actors);

        $s->create('konten_ad_funds', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('platform', 32)->nullable()->index();
            $t->bigInteger('amount')->default(0);
            $t->date('tanggal')->nullable()->index();
            $t->bigInteger('updated_at')->default(0);
            $t->bigInteger('created_at')->default(0);
            $t->longText('data');
            $tech($t);
        });
        $s->table('konten_ad_funds', $actors);

        $s->create('konten_notifs', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('for_user', 64)->nullable()->index();
            $t->string('kind', 32)->nullable();
            $t->bigInteger('at_ms')->default(0)->index();
            $t->boolean('seen')->default(false);
            $ms($t);
            $tech($t);
        });
        $s->table('konten_notifs', $actors);

        // Append-only audit trail, trimmed to the newest 5000.
        $s->create('konten_logs', function (Blueprint $t) use ($row, $tech): void {
            $row($t);
            $t->string('ref_id', 255)->nullable()->index();
            $t->string('action', 255)->nullable();
            $t->string('by_user', 255)->nullable();
            $t->bigInteger('at_ms')->default(0)->index();
            $t->longText('data');
            $tech($t);
        });
        $s->table('konten_logs', $actors);

        // Settings documents (settings, perms, seeded, extra:*). `k` keeps
        // the legacy key verbatim.
        $s->create('konten_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('konten_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['konten_pengaturan', 'konten_logs', 'konten_notifs', 'konten_ad_funds',
            'konten_ads', 'konten_visits', 'konten_kols', 'konten_bank', 'konten_assets',
            'konten_shootings', 'konten_prod_tasks', 'konten_content', 'konten_campaigns',
            'konten_brands', 'konten_users'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
