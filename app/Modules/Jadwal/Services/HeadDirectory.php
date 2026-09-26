<?php

namespace App\Modules\Jadwal\Services;

use App\Auth\AccountRepository;
use App\Auth\CoreAccountRepository;
use App\Support\JsonDoc;
use App\Support\Modules;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Who heads which division" — port of jadwal-mysql head_ids().
 *
 * The list lives in `jadwal_setting.data.heads` ({divisi: [userId…]}) — or,
 * once identity is on core (#44), in `kepala_divisi` —
 * where the Jadwal admin assigns it. Legacy account-api fetched it over HTTP
 * (action=headIds) with a 60 s file cache; here it is a direct read of the
 * jadwal database with the same 60 s cache — no HTTP hop, same staleness.
 *
 * Failure = empty map, never an exception: a broken jadwal DB must not break
 * login for the whole Office (legacy rule). Failures are NOT cached, a
 * legitimately empty list IS.
 */
class HeadDirectory
{
    private ?array $memo = null;

    /** @return array<string,array<int,string>> userId => [divisi…] */
    public function map(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $key = 'lm-heads:'.Modules::databaseName('jadwal');
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $this->memo = $cached;
            }
        } catch (Throwable) {
            // cache unavailable: compute without it
        }

        try {
            $map = $this->compute();
        } catch (Throwable) {
            return $this->memo = [];
        }
        try {
            Cache::put($key, $map, 60);
        } catch (Throwable) {
        }

        return $this->memo = $map;
    }

    /** @return array<string,array<int,string>> */
    public function compute(): array
    {
        return self::invert($this->headsByDivisi());
    }

    /**
     * The `heads` map as the jadwal setting holds it (divisi => [userId…]).
     * On core (#44) it is Kepala Divisi, no longer the jadwal blob.
     *
     * @return array<string,mixed>
     */
    public function headsByDivisi(): array
    {
        if (AccountRepository::onCore()) {
            return app(CoreAccountRepository::class)->headsByDivisi();
        }
        $row = Modules::db('jadwal')->selectOne('SELECT `data` FROM `jadwal_setting` WHERE `id` = 1');
        $set = JsonDoc::toArray($row ? JsonDoc::decode($row->data) : null);

        return (isset($set['heads']) && is_array($set['heads'])) ? $set['heads'] : [];
    }

    /** @return array<string,array<int,string>> userId => [divisi…] */
    private static function invert(array $heads): array
    {
        $out = [];
        foreach ($heads as $div => $daftar) {
            if (! is_array($daftar)) {
                continue;
            }
            foreach ($daftar as $uid) {
                $uid = is_scalar($uid) ? trim((string) $uid) : '';
                if ($uid === '') {
                    continue;
                }
                $out[$uid] ??= [];
                if (! in_array((string) $div, $out[$uid], true)) {
                    $out[$uid][] = (string) $div;
                }
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->memo = null;
        try {
            Cache::forget('lm-heads:'.Modules::databaseName('jadwal'));
        } catch (Throwable) {
        }
    }
}
