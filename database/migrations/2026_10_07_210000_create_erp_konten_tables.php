<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ERP v2 Konten (docs/erp/konten.md), from the konten blobs: brands, content
 * through its production pipeline with per-platform performance rows, the
 * approval chain, ads with their spend and VAT, ad funds, KOL rates and
 * visits, production tasks, the idea bank and the crew's work profile.
 */
return new class extends Migration
{
    private const TABLES = [
        'kru_konten_brand', 'kru_konten', 'ide_konten', 'tugas_produksi', 'kunjungan_kol', 'kol_tarif',
        'dana_iklan', 'iklan_biaya', 'iklan', 'konten_persetujuan', 'konten_tayang', 'konten',
        'kampanye_konten', 'brand_konten',
    ];

    public function up(): void
    {
        $s = Schema::connection('core');

        // ADR-0007 technical columns; created_by/updated_by FK `user`.
        $tech = function (Blueprint $t, bool $softDelete = false): void {
            $t->ulid('created_by')->nullable();
            $t->ulid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            if ($softDelete) {
                $t->softDeletes();
            }
            $t->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('user')->nullOnDelete();
        };
        $legacy = fn (Blueprint $t) => $t->string('legacy_id', 64)->nullable()->unique();
        $orang = function (Blueprint $t, string $kolom): void {
            $t->foreignUlid("{$kolom}_id")->nullable()->constrained('user')->restrictOnDelete();
            $t->string("{$kolom}_impor", 120)->nullable();
        };
        $brand = fn (Blueprint $t, bool $wajib = true) => $wajib
            ? $t->foreignUlid('brand_konten_id')->constrained('brand_konten')->restrictOnDelete()
            : $t->foreignUlid('brand_konten_id')->nullable()->constrained('brand_konten')->restrictOnDelete();

        $s->create('brand_konten', function (Blueprint $t) use ($legacy, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->string('nama', 120)->unique();
            $orang($t, 'pic');
            $t->string('deskripsi', 1000)->nullable();
            $t->string('frekuensi', 60)->nullable();
            $t->string('website', 190)->nullable();
            $t->unsignedInteger('kpi_reach')->nullable();
            $t->unsignedInteger('kpi_followers')->nullable();
            $t->json('profil')->nullable(); // tone, pillars, hashtags, audience, colour
            $tech($t, softDelete: true);
        });

        $s->create('kampanye_konten', function (Blueprint $t) use ($legacy, $brand, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $brand($t, false);
            $t->string('nama', 190);
            $t->date('mulai')->nullable();
            $t->date('selesai')->nullable();
            $t->string('catatan', 1000)->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('konten', function (Blueprint $t) use ($legacy, $brand, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $brand($t);
            $t->foreignUlid('kampanye_konten_id')->nullable()->constrained('kampanye_konten')->restrictOnDelete();
            $t->string('judul', 255);
            $t->string('status', 10)->default('idea');
            $t->string('prioritas', 8)->default('medium');
            $t->string('jenis_konten', 40)->nullable(); // Reel, Story, …
            $t->string('pilar', 60)->nullable();
            $t->string('seri', 120)->nullable();
            $t->string('objective', 60)->nullable();
            $orang($t, 'pic');
            $t->date('tenggat')->nullable();
            $t->date('tanggal_tayang')->nullable();
            $t->time('jam_tayang')->nullable();
            $t->json('isi')->nullable(); // hook, script, caption, shot list, refs, checklist, comments
            $tech($t, softDelete: true);
            $t->index(['status', 'tanggal_tayang']);
        });

        $s->create('konten_tayang', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('konten_id')->constrained('konten')->cascadeOnDelete();
            $t->string('platform', 8);
            $t->timestamp('tayang_at')->nullable();
            $t->string('url', 500)->nullable();
            foreach (['reach', 'views', 'likes', 'comments', 'shares', 'saves', 'followers'] as $m) {
                $t->unsignedInteger($m)->nullable();
            }
            $t->decimal('er', 6, 2)->nullable();
            $tech($t);
            $t->unique(['konten_id', 'platform']);
        });

        $s->create('konten_persetujuan', function (Blueprint $t) use ($orang, $tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('konten_id')->constrained('konten')->cascadeOnDelete();
            $t->string('tahap', 20);
            $t->string('status', 10)->default('menunggu');
            $orang($t, 'oleh');
            $t->timestamp('diputuskan_at')->nullable();
            $t->string('catatan', 500)->nullable();
            $tech($t);
            $t->unique(['konten_id', 'tahap']);
        });

        $s->create('iklan', function (Blueprint $t) use ($legacy, $brand, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $brand($t);
            $t->string('nama', 190);
            $t->string('platform', 20);
            $t->string('objective', 20)->nullable();
            $t->string('status', 8)->default('draft');
            $t->decimal('budget', 15, 0)->default(0);
            $t->string('target', 255)->nullable();
            $t->string('rentang_usia', 20)->nullable();
            $t->date('mulai')->nullable();
            $t->date('selesai')->nullable();
            $t->unsignedInteger('impressions')->nullable();
            $t->unsignedInteger('chats')->nullable();
            $t->string('catatan', 1000)->nullable();
            $tech($t, softDelete: true);
        });

        $s->create('iklan_biaya', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('iklan_id')->constrained('iklan')->cascadeOnDelete();
            $t->date('tanggal');
            $t->decimal('nominal', 15, 0); // before VAT
            $t->decimal('ppn', 15, 0)->default(0); // VAT computed when written, kept as it was
            $t->string('catatan', 255)->nullable();
            $tech($t);
        });

        $s->create('dana_iklan', function (Blueprint $t) use ($legacy, $brand, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $brand($t);
            $t->date('tanggal');
            $t->decimal('nominal', 15, 0);
            $t->string('catatan', 255)->nullable();
            $tech($t);
        });

        $s->create('kol_tarif', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('kol_id')->constrained('kol', 'pihak_id')->restrictOnDelete();
            $t->decimal('tarif', 15, 0)->nullable();        // per post
            $t->decimal('tarif_gambar', 15, 0)->nullable(); // per image
            $t->date('berlaku_dari');
            $tech($t);
            $t->unique(['kol_id', 'berlaku_dari']);
        });

        $s->create('kunjungan_kol', function (Blueprint $t) use ($legacy, $brand, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $t->foreignUlid('kol_id')->nullable()->constrained('kol', 'pihak_id')->restrictOnDelete();
            $brand($t, false);
            $t->string('judul', 190);
            $t->date('tanggal');
            $t->time('jam')->nullable();
            $t->string('lokasi', 190)->nullable();
            $t->string('objective', 60)->nullable();
            $t->string('status', 10)->default('planned');
            $orang($t, 'pic');
            $t->string('catatan', 1000)->nullable();
            $tech($t);
        });

        $s->create('tugas_produksi', function (Blueprint $t) use ($legacy, $brand, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $brand($t, false);
            $t->string('judul', 190);
            $t->string('jenis', 8)->nullable(); // shoot | design | edit
            $t->date('tanggal')->nullable();
            $orang($t, 'pic');
            $t->string('status', 8)->default('todo');
            $t->string('prioritas', 8)->default('medium');
            $t->string('catatan', 1000)->nullable();
            $tech($t);
        });

        $s->create('ide_konten', function (Blueprint $t) use ($legacy, $brand, $orang, $tech): void {
            $t->ulid('id')->primary();
            $legacy($t);
            $brand($t, false);
            $t->string('judul', 255);
            $t->string('platform', 8)->nullable();
            $t->string('kategori', 60)->nullable();
            $t->string('sumber', 190)->nullable();
            $t->string('tren', 190)->nullable();
            $t->string('prioritas', 8)->default('medium');
            $t->string('status', 16)->nullable(); // free text in legacy
            $orang($t, 'pemilik');
            $tech($t, softDelete: true);
        });

        $s->create('kru_konten', function (Blueprint $t) use ($tech): void {
            $t->foreignUlid('user_id')->primary()->constrained('user')->cascadeOnDelete();
            $t->unsignedSmallInteger('kapasitas')->nullable(); // items per week
            $t->string('keahlian', 500)->nullable();
            $t->boolean('tersedia')->default(true);
            $t->json('jam_kerja')->nullable();
            $tech($t);
        });

        $s->create('kru_konten_brand', function (Blueprint $t) use ($tech): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained('kru_konten', 'user_id')->cascadeOnDelete();
            $t->foreignUlid('brand_konten_id')->constrained('brand_konten')->cascadeOnDelete();
            $tech($t);
            $t->unique(['user_id', 'brand_konten_id']);
        });

        $prioritas = "prioritas IN ('urgent','high','medium','low')";
        $platform = "platform IN ('ig','tt','yt','fb','website','threads','google')";
        foreach ([
            'konten' => [
                "status IN ('idea','research','draft','script','design','shooting','editing','revision','approval','scheduled','posted','cancelled')",
                $prioritas,
            ],
            'konten_tayang' => [$platform, 'er IS NULL OR er BETWEEN 0 AND 100'],
            'konten_persetujuan' => [
                "tahap IN ('planner','designer','editor','account_manager','content_director','publish')",
                "status IN ('menunggu','disetujui','revisi')",
                "(status = 'menunggu') = (diputuskan_at IS NULL)",
            ],
            'kampanye_konten' => ['selesai IS NULL OR mulai IS NULL OR selesai >= mulai'],
            'iklan' => [
                "status IN ('draft','active','paused','ended')",
                'budget >= 0',
                'selesai IS NULL OR mulai IS NULL OR selesai >= mulai',
            ],
            'iklan_biaya' => ['nominal >= 0', 'ppn >= 0'],
            'dana_iklan' => ['nominal >= 0'],
            'kol_tarif' => ['tarif IS NULL OR tarif >= 0', 'tarif_gambar IS NULL OR tarif_gambar >= 0'],
            'kunjungan_kol' => ["status IN ('planned','confirmed','came','noshow','done')"],
            'tugas_produksi' => [$prioritas, "status IN ('todo','doing','done')", "jenis IS NULL OR jenis IN ('shoot','design','edit')"],
            'ide_konten' => [$prioritas],
        ] as $table => $checks) {
            foreach ($checks as $i => $expr) {
                DB::connection('core')->statement(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_chk_{$i}` CHECK ({$expr})"
                );
            }
        }
    }

    public function down(): void
    {
        $s = Schema::connection('core');
        foreach (self::TABLES as $table) {
            $s->dropIfExists($table);
        }
    }
};
