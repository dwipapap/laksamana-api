<?php

use App\Modules\Kompas\Services\KompasState;
use App\Support\Modules;

/**
 * Kompas SQL in legacy names, run on whichever storage the Modul is on:
 * the kompas_* tables and `legacy_id` once kompas is on core (#69).
 */
function kpRow(string $table, int|string $id, string $cols = '*'): ?stdClass
{
    return Modules::db('kompas')->selectOne('SELECT '.$cols.' FROM `'.KompasState::t($table).'` WHERE `'.KompasState::idCol().'`=?', [$id]);
}

/**
 * The single omset blob (`app_state`), read without naming its key: `data` is
 * the blob, `ts` its stored version and `by` the name that saved it (legacy
 * `updated_by`, `oleh` on core).
 */
function kpState(): stdClass
{
    return Modules::db('kompas')->selectOne('SELECT `data`,`updated_at`,`'.KompasState::byCol().'` AS `by` FROM `'.KompasState::t('app_state').'`');
}

/** The omset blob as an assoc array. */
function kpBlob(): array
{
    return json_decode(kpState()->data, true);
}

/** The blob's stored version (`app_state.updated_at`). */
function kpTs(): int
{
    return (int) kpState()->updated_at;
}

/** The name recorded as the blob's author. */
function kpBy(): string
{
    return (string) kpState()->by;
}
