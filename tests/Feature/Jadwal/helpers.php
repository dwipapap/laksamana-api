<?php

use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * jadwal tests on both storages (#156). See docs/db/jadwal.md for the mapping.
 *
 * Legacy: `jadwal_sel` is keyed (user_id, tgl) with user_id = legacy id and the
 * actor NAME in `updated_by`; `jadwal_pengajuan.id` is the legacy id; the whole
 * setting lives in `jadwal_setting.data`.
 *
 * core (#47): table names stay, but every id moves to `legacy_id` (fresh ULID
 * PK), `user_id` becomes the User ULID, the actor columns become ULID FKs, and
 * the setting is split into normalised tables. These helpers let a test written
 * in legacy names run on either storage.
 */

/** Legacy user id -> the value the user columns hold on the active storage. */
function jadwalUid(string $legacyId): string
{
    if (! JadwalService::onCore()) {
        return $legacyId;
    }

    return (string) DB::connection('core')->table('user')->where('legacy_id', $legacyId)->value('id');
}

/** A `jadwal_sel` row in legacy shape: user_id = legacy id, updated_by = actor name. */
function jadwalSelRow(string $legacyUid, string $tgl): ?object
{
    $db = Modules::db('jadwal');
    if (! JadwalService::onCore()) {
        return $db->selectOne(
            'SELECT shift, jam_mulai, jam_selesai, catatan, updated_by FROM jadwal_sel WHERE user_id = ? AND tgl = ?',
            [$legacyUid, $tgl]);
    }

    return $db->selectOne(
        'SELECT s.shift, s.jam_mulai, s.jam_selesai, s.catatan, u.nama AS updated_by
         FROM jadwal_sel s
         JOIN user u ON u.id = s.updated_by
         JOIN user ou ON ou.id = s.user_id
         WHERE ou.legacy_id = ? AND s.tgl = ?',
        [$legacyUid, $tgl]);
}

/** How many `jadwal_sel` rows a crew member has on a date. */
function jadwalSelCount(string $legacyUid, string $tgl): int
{
    $db = Modules::db('jadwal');
    if (! JadwalService::onCore()) {
        return (int) $db->selectOne('SELECT COUNT(*) n FROM jadwal_sel WHERE user_id = ? AND tgl = ?', [$legacyUid, $tgl])->n;
    }

    return (int) $db->selectOne(
        'SELECT COUNT(*) n FROM jadwal_sel s JOIN user u ON u.id = s.user_id WHERE u.legacy_id = ? AND s.tgl = ?',
        [$legacyUid, $tgl])->n;
}

/** Inserts a `jadwal_sel` row from the legacy (user_id, tgl, shift). */
function jadwalInsertSel(string $legacyUid, string $tgl, string $shift): void
{
    $row = ['user_id' => jadwalUid($legacyUid), 'tgl' => $tgl, 'shift' => $shift];
    if (JadwalService::onCore()) {
        $row['id'] = strtolower((string) Str::ulid());
        $row['legacy_id'] = $legacyUid.'|'.$tgl;
    }
    Modules::db('jadwal')->table('jadwal_sel')->insert($row);
}

/** A `jadwal_pengajuan` row in legacy shape (id + user_id are legacy ids). */
function jadwalPengajuan(string $legacyId): ?object
{
    $db = Modules::db('jadwal');
    if (! JadwalService::onCore()) {
        return $db->selectOne('SELECT * FROM jadwal_pengajuan WHERE id = ?', [$legacyId]);
    }

    return $db->selectOne(
        'SELECT p.*, u.legacy_id AS user_id, p.legacy_id AS id
         FROM jadwal_pengajuan p JOIN user u ON u.id = p.user_id
         WHERE p.legacy_id = ?',
        [$legacyId]);
}

/** How many `jadwal_pengajuan` rows carry a legacy request id (0 or 1). */
function jadwalPengajuanCount(string $legacyId): int
{
    $db = Modules::db('jadwal');

    return (int) $db->selectOne(
        'SELECT COUNT(*) n FROM jadwal_pengajuan WHERE '.(JadwalService::onCore() ? 'legacy_id' : 'id').' = ?',
        [$legacyId])->n;
}

/** Inserts a `jadwal_pengajuan` row from the legacy (id, user_id, jenis, dates, status). */
function jadwalInsertPengajuan(string $legacyId, string $legacyUid, string $jenis, string $dari, string $sampai, string $status): void
{
    $row = [
        'user_id' => jadwalUid($legacyUid),
        'jenis' => $jenis,
        'tgl_mulai' => $dari,
        'tgl_selesai' => $sampai,
        'status' => $status,
    ];
    if (JadwalService::onCore()) {
        $row['id'] = strtolower((string) Str::ulid());
        $row['legacy_id'] = $legacyId;
    } else {
        $row['id'] = $legacyId;
    }
    Modules::db('jadwal')->table('jadwal_pengajuan')->insert($row);
}

/** The setting as the legacy shape (legacy: stored JSON; core: rebuilt by the service). */
function jadwalSetting(): array
{
    return JsonDoc::toArray(app(JadwalService::class)->setting());
}

/** The setting as raw JSON, for assertions on how a part was serialised. */
function jadwalSettingRaw(): string
{
    if (JadwalService::onCore()) {
        return (string) json_encode(app(JadwalService::class)->setting(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return (string) Modules::db('jadwal')->selectOne('SELECT data FROM jadwal_setting WHERE id = 1')->data;
}

/** How many setting rows exist (legacy singleton blob vs core jadwal_pengaturan). */
function jadwalSettingRowCount(): int
{
    $db = Modules::db('jadwal');
    if (! JadwalService::onCore()) {
        return (int) $db->selectOne('SELECT COUNT(*) n FROM jadwal_setting WHERE id = 1')->n;
    }

    return (int) $db->selectOne('SELECT COUNT(*) n FROM jadwal_pengaturan WHERE legacy_id = ?', ['1'])->n;
}
