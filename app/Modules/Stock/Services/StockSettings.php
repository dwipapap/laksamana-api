<?php

namespace App\Modules\Stock\Services;

use stdClass;

/**
 * Ordering / Purchasing settings rows of stock_settings.
 *
 * The table does not exist in the restored production/dev dumps, so the
 * compat routes must keep failing exactly as the old PHP does. v1 reports
 * `available:false` with empty defaults and refuses writes until the table is
 * created deliberately during a cutover (never by a migration here).
 */
class StockSettings
{
    public const MODULES = ['ordering', 'purchasing'];

    public function available(): bool
    {
        return (bool) StockSupport::db()->selectOne(
            'SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [StockSupport::table('stock_settings')]
        )->c;
    }

    /** compat ordering-settings.php — a missing table throws into serve()'s 500. */
    public function perms(string $module): stdClass
    {
        $r = StockSupport::db()->selectOne(StockSupport::q('SELECT `data` FROM {stock_settings} WHERE `modul` = ?'), [$module]);

        return $r ? $this->object($r->data) : new stdClass;
    }

    public function savePermsCompat(string $module, mixed $perms): array
    {
        if (! $perms instanceof stdClass) {
            return ['status' => 'error', 'message' => 'data bukan objek'];
        }

        return $this->write($module, $perms);
    }

    /** compat purchasing-settings.php: the old flat shape is read as perms. */
    public function purchasingCompat(): array
    {
        $d = $this->perms('purchasing');
        if (isset($d->perms) || isset($d->templates)) {
            return ['perms' => $d->perms ?? new stdClass, 'templates' => $d->templates ?? new stdClass];
        }

        return ['perms' => $d, 'templates' => new stdClass];
    }

    public function savePurchasingCompat(string $action, mixed $perms, mixed $templates): array
    {
        $s = $this->purchasingCompat();
        if ($action === 'savePerms') {
            if (! $perms instanceof stdClass) {
                return ['status' => 'error', 'message' => 'perms bukan objek'];
            }
            $s['perms'] = $perms;
        } else {
            if (! $templates instanceof stdClass) {
                return ['status' => 'error', 'message' => 'templates bukan objek'];
            }
            $s['templates'] = $templates;
        }

        return $this->write('purchasing', (object) $s);
    }

    /** @return array{record:stdClass, version:string, available:bool} */
    public function readV1(string $module): array
    {
        if (! $this->available()) {
            $record = (object) ['perms' => new stdClass];
            if ($module === 'purchasing') {
                $record->templates = new stdClass;
            }

            return ['record' => $record, 'version' => StockRecords::hash($record), 'available' => false];
        }
        $record = $module === 'purchasing'
            ? (object) $this->purchasingCompat()
            : (object) ['perms' => $this->perms($module)];

        return ['record' => $record, 'version' => StockRecords::hash($record), 'available' => true];
    }

    public function writeV1(string $module, array $fields, string $version): array
    {
        if (! $this->available()) {
            throw new StockConflict('settings_unavailable', 'Tabel stock_settings belum ada di database.');
        }

        return StockSupport::db()->transaction(function () use ($module, $fields, $version) {
            StockSupport::db()->selectOne(StockSupport::q('SELECT `modul` FROM {stock_settings} WHERE `modul` = ? FOR UPDATE'), [$module]);
            $cur = $this->readV1($module);
            if (! hash_equals($cur['version'], $version)) {
                throw new StockConflict('stale', $cur['record']);
            }
            $record = clone $cur['record'];
            if ($module === 'ordering') {
                $record->perms = $this->objectField($fields, 'perms', $record->perms);
            } else {
                $record->perms = $this->objectField($fields, 'perms', $record->perms);
                $record->templates = $this->objectField($fields, 'templates', $record->templates);
            }
            $this->write($module, $record);

            return $this->readV1($module);
        });
    }

    private function write(string $module, stdClass $data): array
    {
        StockSupport::db()->insert(StockSupport::q('INSERT INTO {stock_settings} (`modul`,`data`) VALUES (?,?)
            ON DUPLICATE KEY UPDATE `data`=VALUES(`data`)'), [$module, StockSupport::enc($data)]);

        return ['status' => 'success'];
    }

    private function object(mixed $raw): stdClass
    {
        $d = json_decode((string) $raw);

        return $d instanceof stdClass ? $d : new stdClass;
    }

    private function objectField(array $fields, string $key, stdClass $old): stdClass
    {
        if (! array_key_exists($key, $fields)) {
            return $old;
        }
        if (is_object($fields[$key])) {
            return $fields[$key];
        }
        if (is_array($fields[$key])) {
            return (object) $fields[$key];
        }

        throw new StockConflict('invalid', $key.' harus objek');
    }
}
