<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('core_import_probe', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('legacy_id', 191)->unique();
            $table->string('name', 120);
            $table->string('username', 40)->nullable();
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('core_import_probe');
    }
};
