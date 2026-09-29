<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Shared fixtures for the menu tests. The menu is greenfield and lives in
 * `core`, so rows are inserted straight there (no legacy importer exists).
 */

/** A Sanctum token for the superadmin test user (module:menu passes via '*'). */
function menuToken(): string
{
    return loginAs(officeUser('u-wandi'));
}

/** Id of a category seeded by `menu:seed`. */
function menuKat(string $slug): string
{
    return (string) DB::connection('core')->table('menu_kategori')->where('slug', $slug)->value('id');
}

/** Insert one item directly into core. Returns its id. */
function menuRow(array $over = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('menu_item')->insert(array_merge([
        'id' => $id,
        'kategori_id' => menuKat('nusantara'),
        'bagian' => 'main_course',
        'nama' => 'Nasi Goreng Laksamana',
        'slug' => 'item-'.Str::lower(Str::random(8)),
        'deskripsi' => '',
        'komponen' => 'nasi, telur mata sapi, sambal',
        'unggulan' => true,
        'rekomendasi' => false,
        'pedas' => false,
        'vegetarian' => false,
        'ramah_anak' => true,
        'tampil' => true,
        'tersedia' => true,
        'urutan' => 10,
        'created_at' => now(),
        'updated_at' => now(),
        'version' => 1,
    ], $over));

    return $id;
}

function menuVarian(string $itemId, string $label, int $harga, int $urutan = 1): void
{
    DB::connection('core')->table('menu_varian')->insert([
        'id' => strtolower((string) Str::ulid()),
        'item_id' => $itemId, 'label' => $label, 'harga' => $harga, 'urutan' => $urutan,
        'created_at' => now(), 'updated_at' => now(), 'version' => 1,
    ]);
}

/** Create one item through the office API; returns the office shape. */
function menuCreateItem(array $over = []): array
{
    return test()->withToken(menuToken())->postJson('/api/v1/menu/office/item', array_merge([
        'kategori_id' => menuKat('nusantara'),
        'nama' => 'Nasi Goreng Laksamana',
        'bagian' => 'main_course',
        'varian' => [['label' => '', 'harga' => 45000]],
    ], $over))->assertCreated()->json('data');
}

/** A real 1x1 PNG (so the upload path sees an image mime). */
function menuPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'menu.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
    );
}
