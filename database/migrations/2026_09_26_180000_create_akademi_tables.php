<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Akademi in core (#57, PRD #1): training-platform rows (indexed columns +
 * the full `data` JSON), composite-key progress maps, the append-only
 * activity trail and the settings documents. Legacy names collide across
 * modules, so every table takes the akademi_ prefix.
 * ERD and legacy mapping: docs/db/akademi.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0003 technical columns. created_by/updated_by stay NULL for
        // akademi: actors are free-text names kept in the row itself, with no
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
        // row verbatim in `data` (open-ended training fields); the typed
        // columns are the query surface, derived from the row on every write.
        $row = function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
        };
        $ms = function (Blueprint $t): void {
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
        };

        $s->create('akademi_users', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $t->string('role', 32)->nullable()->index();
            $t->string('divisi', 64)->nullable()->index();
            $t->string('title', 255)->nullable();
            $t->boolean('active')->default(true)->index();
            $ms($t);
            $tech($t);
        });
        $s->table('akademi_users', $actors);

        $s->create('akademi_divisions', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('name', 255)->nullable();
            $ms($t);
            $tech($t);
        });
        $s->table('akademi_divisions', $actors);

        $s->create('akademi_materials', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('title', 255)->nullable();
            $t->string('kind', 32)->nullable()->index();
            $t->string('cat', 64)->nullable()->index();
            $t->boolean('mandatory')->default(false)->index();
            $t->boolean('published')->default(false)->index();
            $t->integer('passing')->default(0);
            $t->bigInteger('created_at')->default(0);
            $ms($t);
            $tech($t);
        });
        $s->table('akademi_materials', $actors);

        $s->create('akademi_programs', function (Blueprint $t) use ($row, $ms, $tech): void {
            $row($t);
            $t->string('title', 255)->nullable();
            $t->string('bulan', 7)->nullable()->index();
            $t->date('deadline')->nullable()->index();
            $t->bigInteger('created_at')->default(0);
            $ms($t);
            $tech($t);
        });
        $s->table('akademi_programs', $actors);

        // Composite-key progress maps. legacy_id is "user|material" (like
        // jadwal_sel's "user|tgl"); the pair columns stay soft strings with
        // no FKs, as in legacy.
        $s->create('akademi_progress', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->unique();
            $t->string('user_id', 64)->index();
            $t->string('material_id', 64)->index();
            $t->boolean('done')->default(false)->index();
            $t->integer('score')->nullable();
            $t->bigInteger('at_ms')->default(0);
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $t->index(['material_id', 'done']);
            $tech($t);
        });
        $s->table('akademi_progress', $actors);

        $s->create('akademi_prog_prog', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 191)->unique();
            $t->string('user_id', 64)->index();
            $t->string('program_id', 64)->index();
            $t->string('material_id', 64);
            $t->boolean('done')->default(false);
            $t->integer('score')->nullable();
            $t->bigInteger('at_ms')->default(0);
            $t->bigInteger('updated_at')->default(0);
            $t->longText('data');
            $t->index(['program_id', 'done']);
            $tech($t);
        });
        $s->table('akademi_prog_prog', $actors);

        // Append-only trail. legacy_id is the content fingerprint (ac_…).
        $s->create('akademi_activity', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('legacy_id', 64)->unique();
            $t->bigInteger('ts')->default(0)->index();
            $t->string('user_id', 64)->nullable()->index();
            $t->string('action', 64)->nullable()->index();
            $t->longText('data');
            $tech($t);
        });
        $s->table('akademi_activity', $actors);

        // Settings documents (settings, version, createdAt, extra:*). `k`
        // keeps the legacy key verbatim.
        $s->create('akademi_pengaturan', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('k', 64)->unique();
            $t->longText('v');
            $tech($t);
        });
        $s->table('akademi_pengaturan', $actors);
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (['akademi_pengaturan', 'akademi_activity', 'akademi_prog_prog',
            'akademi_progress', 'akademi_programs', 'akademi_materials',
            'akademi_divisions', 'akademi_users'] as $table) {
            $s->dropIfExists($table);
        }
    }
};
