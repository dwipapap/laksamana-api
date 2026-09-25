<?php

namespace App\Modules\Konten\Services;

/**
 * Collection → table map of the konten database (port of collections(),
 * scalar_keys() in lib_konten_mysql.php).
 *
 * Only indexed columns are listed; every other field lives in `data`.
 * App key => RowSync definition. `bank` is kept even though its screen was
 * removed — dropping it from the collections would empty the table.
 */
final class KontenSchema
{
    public static function collections(): array
    {
        return [
            'users' => ['table' => 'users', 'cols' => [
                'name' => ['name', 'str'], 'email' => ['email', 'str'],
                'divisi' => ['division', 'str'], // "div" reserved word in MySQL
                'capacity' => ['capacity', 'int'], 'avail' => ['avail', 'str'],
            ]],
            'brands' => ['table' => 'brands', 'cols' => [
                'name' => ['name', 'str'],
            ]],
            'campaigns' => ['table' => 'campaigns', 'cols' => [
                'name' => ['name', 'str'], 'brand' => ['brand', 'str'],
                'start_date' => ['start', 'date'], 'end_date' => ['end', 'date'],
            ]],
            'content' => ['table' => 'content', 'created' => true, 'cols' => [
                'title' => ['title', 'str'], 'brand' => ['brand', 'str'],
                'campaign' => ['campaign', 'str'], 'platform' => ['platform', 'str'],
                'pillar' => ['pillar', 'str'], 'content_type' => ['contentType', 'str'],
                'status' => ['status', 'str'], 'priority' => ['priority', 'str'],
                'pic' => ['pic', 'str'], 'deadline' => ['deadline', 'date'],
                'publish_date' => ['publishDate', 'date'], 'publish_time' => ['publishTime', 'str'],
            ]],
            // Production tasks stand alone (no content pointer); performer = pic, date = date.
            'prodTasks' => ['table' => 'prod_tasks', 'cols' => [
                'kind' => ['kind', 'str'], 'title' => ['title', 'str'],
                'brand' => ['brand', 'str'], 'pic' => ['pic', 'str'],
                'priority' => ['priority', 'str'], 'status' => ['status', 'str'],
                'tanggal' => ['date', 'date'],
            ]],
            'shootings' => ['table' => 'shootings', 'cols' => [
                'title' => ['title', 'str'], 'tanggal' => ['date', 'date'],
                'location' => ['location', 'str'], 'status' => ['status', 'str'],
            ]],
            'assets' => ['table' => 'assets', 'cols' => [
                'name' => ['name', 'str'], 'kind' => ['type', 'str'],
                'by_user' => ['by', 'str'], 'at_ms' => ['at', 'ms'],
            ]],
            // Bank of ideas: no "type" field; category/platform/status instead.
            'bank' => ['table' => 'bank', 'cols' => [
                'owner' => ['owner', 'str'], 'title' => ['title', 'str'],
                'brand' => ['brand', 'str'], 'platform' => ['platform', 'str'],
                'kind' => ['category', 'str'], 'status' => ['status', 'str'],
                'at_ms' => ['at', 'ms'],
            ]],
            'kols' => ['table' => 'kols', 'created' => true, 'cols' => [
                'name' => ['name', 'str'], 'kol_type' => ['type', 'str'],
                'instagram' => ['instagram', 'str'], 'whatsapp' => ['whatsapp', 'str'],
                'rate_value' => ['rateValue', 'int'],
            ]],
            'visits' => ['table' => 'visits', 'created' => true, 'cols' => [
                'title' => ['title', 'str'], 'kol_id' => ['kolId', 'str'],
                'brand' => ['brand', 'str'], 'pic' => ['pic', 'str'],
                'tanggal' => ['date', 'date'], 'location' => ['location', 'str'],
                'status' => ['status', 'str'],
            ]],
            'ads' => ['table' => 'ads', 'created' => true, 'cols' => [
                'name' => ['name', 'str'], 'brand' => ['brand', 'str'],
                'platform' => ['platform', 'str'], 'objective' => ['objective', 'str'],
                'status' => ['status', 'str'], 'budget' => ['budget', 'int'],
                'spent' => ['spent', 'int'],
                'start_date' => ['start', 'date'], 'end_date' => ['end', 'date'],
            ]],
            'adFunds' => ['table' => 'ad_funds', 'created' => true, 'cols' => [
                'platform' => ['platform', 'str'], 'amount' => ['amount', 'int'],
                'tanggal' => ['date', 'date'],
            ]],
            'notifs' => ['table' => 'notifs', 'cols' => [
                'for_user' => ['to', 'str'], // live data uses "to", not "for"
                'kind' => ['type', 'str'], 'at_ms' => ['at', 'ms'],
                'seen' => ['read', 'bool'], // "read" reserved word in MySQL
            ]],
        ];
    }

    /** Top-level keys that are not lists — stored as-is in settings(k,v). */
    public static function scalarKeys(): array
    {
        return ['settings', 'perms', 'seeded'];
    }

    /** Tables counted by stats. */
    public static function statsTables(): array
    {
        return ['users', 'brands', 'campaigns', 'content', 'prod_tasks', 'shootings',
            'assets', 'bank', 'kols', 'visits', 'ads', 'ad_funds', 'notifs', 'logs'];
    }
}
