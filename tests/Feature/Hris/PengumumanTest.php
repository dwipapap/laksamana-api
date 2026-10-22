<?php

use App\Modules\Hris\Models\HrisDivisi;
use App\Modules\Hris\Models\HrisDivisiKepala;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Services\HrisDivisiService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisPengumumanService;

require_once __DIR__.'/helpers.php';

function pengumumanAdmin(): array
{
    hrisMakeHr('u-admin');
    app(HrisImporKaryawanService::class)->impor('test');

    return ['id' => 'u-admin', 'name' => 'Admin'];
}

it('hides drafts and foreign published announcements from crew, including guessed detail ids', function () {
    $admin = pengumumanAdmin();
    $crew = HrisKaryawan::query()->where('user_id', 'u-yuzaalfarel')->firstOrFail();
    $other = HrisDivisi::query()->where('aktif', true)->where('id', '!=', $crew->divisi_id)->firstOrFail();
    $service = app(HrisPengumumanService::class);

    $draft = $service->create($admin, ['judul' => 'Draf privat', 'isi' => 'Belum terbit.', 'semua' => true]);
    $foreign = $service->create($admin, ['judul' => 'Untuk divisi lain', 'isi' => 'Isi privat.', 'semua' => false,
        'divisi_ids' => [(string) $other->id]]);
    $service->publish($admin, $foreign['id'], ['berubah' => $foreign['berubah']]);
    $token = hrisToken('u-yuzaalfarel');

    expect(collect(test()->getJson('/api/v1/hris/pengumuman', ['Authorization' => 'Bearer '.$token])
        ->assertOk()->json('data'))->pluck('id')->all())->not->toContain($draft['id'], $foreign['id']);
    test()->getJson('/api/v1/hris/pengumuman/'.$draft['id'], ['Authorization' => 'Bearer '.$token])->assertNotFound();
    test()->getJson('/api/v1/hris/pengumuman/'.$foreign['id'], ['Authorization' => 'Bearer '.$token])->assertNotFound();
    test()->getJson('/api/v1/hris/pengumuman?kelola=true', ['Authorization' => 'Bearer '.$token])->assertStatus(403);
});

it('lets a scoped announcement writer edit published content with versions and preserves server identity', function () {
    $admin = pengumumanAdmin();
    $employee = HrisKaryawan::query()->where('user_id', 'u-yuzaalfarel')->firstOrFail();
    $divisi = HrisDivisi::query()->where('aktif', true)->where('id', '!=', $employee->divisi_id)->firstOrFail();
    $second = app(HrisDivisiService::class)->create(['nama' => 'Divisi Pengumuman Uji', 'jenis' => 'kantor'], 'test');
    $service = app(HrisPengumumanService::class);
    $service->setGrant($admin, 'u-yuzaalfarel', ['semua_divisi' => false,
        'divisi_ids' => [(string) $divisi->id, (string) $second->id]]);
    $token = hrisToken('u-yuzaalfarel');

    $draft = test()->postJson('/api/v1/hris/pengumuman', [
        'judul' => 'Pemberitahuan', 'isi' => 'Teks awal.', 'semua' => false, 'divisi_ids' => [(string) $divisi->id],
        'author_id' => 'u-admin', 'author_name' => 'Spoofed author',
    ], ['Authorization' => 'Bearer '.$token])->assertCreated()->json('data');
    $published = test()->postJson('/api/v1/hris/pengumuman/'.$draft['id'].'/publish', ['berubah' => $draft['berubah']],
        ['Authorization' => 'Bearer '.$token])->assertOk()->json('data');

    // This changes only the audience relation. It must still advance the parent version.
    $audienceEdit = test()->patchJson('/api/v1/hris/pengumuman/'.$draft['id'], [
        'judul' => 'Pemberitahuan', 'isi' => 'Teks awal.', 'semua' => false, 'divisi_ids' => [(string) $second->id],
        'berubah' => $published['berubah'], 'author_id' => 'u-admin', 'author_name' => 'Spoofed author',
    ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data');
    expect($audienceEdit['berubah'])->toBe($published['berubah'] + 1)
        ->and($audienceEdit['author_id'])->toBe('u-yuzaalfarel')
        ->and($audienceEdit['author_name'])->not->toBe('Spoofed author')
        ->and($audienceEdit['published_at'])->toBe($published['published_at']);

    $edited = test()->patchJson('/api/v1/hris/pengumuman/'.$draft['id'], [
        'judul' => 'Pemberitahuan diperbarui', 'isi' => 'Teks baru.', 'semua' => false,
        'divisi_ids' => [(string) $second->id], 'berubah' => $audienceEdit['berubah'],
    ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data');
    expect($edited['berubah'])->toBe($audienceEdit['berubah'] + 1)
        ->and($edited['judul'])->toBe('Pemberitahuan diperbarui');

    test()->patchJson('/api/v1/hris/pengumuman/'.$draft['id'], [
        'judul' => 'Konflik', 'isi' => 'Konflik.', 'semua' => false, 'divisi_ids' => [(string) $second->id],
        'berubah' => $published['berubah'],
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(409);
});

it('keeps a head bound to canonical divisions even when granted broader announcement rights', function () {
    $admin = pengumumanAdmin();
    $own = app(HrisDivisiService::class)->create(['nama' => 'Kantor Kepala Uji', 'jenis' => 'kantor'], 'test');
    $foreign = app(HrisDivisiService::class)->create(['nama' => 'Kantor Asing Uji', 'jenis' => 'kantor'], 'test');
    HrisDivisiKepala::query()->create(['divisi_id' => $own->id, 'user_id' => 'u-yuzaalfarel']);
    app(HrisPengumumanService::class)->setGrant($admin, 'u-yuzaalfarel', ['semua_divisi' => true, 'divisi_ids' => []]);
    $service = app(HrisPengumumanService::class);
    $me = ['id' => 'u-yuzaalfarel', 'name' => 'Crew'];

    expect($service->options($me)['global'])->toBeFalse();
    $draft = $service->create($me, ['judul' => 'Untuk kantor sendiri', 'isi' => 'Isi.', 'semua' => false,
        'divisi_ids' => [(string) $own->id]]);
    expect($draft['divisi_ids'])->toBe([(string) $own->id]);
    expect(fn () => $service->create($me, ['judul' => 'Semua', 'isi' => 'Isi.', 'semua' => true]))
        ->toThrow(HrisError::class);
    expect(fn () => $service->create($me, ['judul' => 'Asing', 'isi' => 'Isi.', 'semua' => false,
        'divisi_ids' => [(string) $foreign->id]]))->toThrow(HrisError::class);
});

it('limits global grant writers to their own managed records and blocks them after revocation', function () {
    $admin = pengumumanAdmin();
    $service = app(HrisPengumumanService::class);
    $service->setGrant($admin, 'u-yuzaalfarel', ['semua_divisi' => true, 'divisi_ids' => []]);
    $service->create($admin, ['judul' => 'Draf admin', 'isi' => 'Privat.', 'semua' => true]);
    $token = hrisToken('u-yuzaalfarel');

    test()->putJson('/api/v1/hris/pengumuman/grant', [
        'user_id' => 'u-admin', 'semua_divisi' => true, 'divisi_ids' => [],
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    $own = test()->postJson('/api/v1/hris/pengumuman', ['judul' => 'Draf sendiri', 'isi' => 'Isi.', 'semua' => true],
        ['Authorization' => 'Bearer '.$token])->assertCreated()->json('data');
    $rows = test()->getJson('/api/v1/hris/pengumuman?kelola=true', ['Authorization' => 'Bearer '.$token])
        ->assertOk()->json('data');
    expect(collect($rows)->pluck('id')->all())->toBe([$own['id']]);

    $service->revokeGrant($admin, 'u-yuzaalfarel');
    test()->getJson('/api/v1/hris/pengumuman?kelola=true', ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    test()->getJson('/api/v1/hris/pengumuman/'.$own['id'].'?kelola=true', ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    test()->postJson('/api/v1/hris/pengumuman/'.$own['id'].'/publish', ['berubah' => $own['berubah']],
        ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    test()->deleteJson('/api/v1/hris/pengumuman/grant/u-admin', [], ['Authorization' => 'Bearer '.$token])->assertStatus(403);
});
