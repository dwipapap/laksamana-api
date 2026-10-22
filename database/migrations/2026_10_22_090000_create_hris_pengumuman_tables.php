<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('core');
        $tech = static function (Blueprint $t): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->softDeletes();
        };

        $s->create('hris_pengumuman', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('author_id', 64);
            $t->string('author_name', 120);
            $t->string('judul', 180);
            $t->text('isi');
            $t->boolean('audience_all')->default(false);
            $t->string('status', 12)->default('draft');
            $t->timestamp('published_at')->nullable();
            $tech($t);
            $t->index(['status', 'published_at']);
            $t->index(['author_id', 'status']);
        });

        $s->create('hris_pengumuman_divisi', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('pengumuman_id')->constrained('hris_pengumuman')->cascadeOnDelete();
            $t->foreignUlid('divisi_id')->constrained('hris_divisi')->restrictOnDelete();
            $tech($t);
            $t->unique(['pengumuman_id', 'divisi_id']);
            $t->index('divisi_id');
        });

        $s->create('hris_pengumuman_grant', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->string('user_id', 64)->unique();
            $t->boolean('all_divisions')->default(false);
            $t->json('division_ids');
            $t->boolean('active')->default(true);
            $t->string('granted_by', 64);
            $tech($t);
        });
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        $s->dropIfExists('hris_pengumuman_grant');
        $s->dropIfExists('hris_pengumuman_divisi');
        $s->dropIfExists('hris_pengumuman');
    }
};
