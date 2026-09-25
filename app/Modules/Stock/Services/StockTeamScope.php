<?php

namespace App\Modules\Stock\Services;

use App\Support\Legacy\LegacyRequest;
use App\Support\Legacy\Sesi;
use App\Support\Modules;

/**
 * Team scoping of usage / waste / serah terima (pur_batas_tim +
 * pur_sql_batas_tim). The caller is identified by the Office session
 * (`?sesi=` / `body.sesi`), answered in-process by Sesi instead of the old
 * whoami HTTP call.
 *
 * Switch: config laksamana.stock_batas_per_tim (env STOCK_BATAS_PER_TIM);
 * unset = on only when the env label is 'dev', like legacy's default.
 *
 *   null            no limit (switch off, or an admin of `usage` / `*`)
 *   []              sees nothing (unknown caller, or no team in keterangan)
 *   ['Bar','Floor'] only those teams
 */
class StockTeamScope
{
    public function __construct(private readonly Sesi $sesi) {}

    public static function enabled(): bool
    {
        $v = config('laksamana.stock_batas_per_tim');

        return $v === null ? Modules::envLabel() === 'dev' : (bool) $v;
    }

    /** @return list<string>|null */
    public function forRequest(?LegacyRequest $req): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $u = $this->sesi->user($req);
        if (! $u) {
            return [];
        }
        if (Sesi::isModuleAdmin($u, 'usage')) {
            return null;
        }

        return self::teams($u);
    }

    /** pur_tim_pemanggil(): every team named in keterangan (substring match, like legacy). */
    public static function teams(array $u): array
    {
        $ket = strtolower(trim((string) ($u['keterangan'] ?? '')));
        if ($ket === '') {
            return [];
        }
        $out = [];
        foreach (['Kitchen', 'Bar', 'Floor'] as $t) {
            if (str_contains($ket, strtolower($t))) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * pur_sql_batas_tim(): append `AND tim IN (…)`. Returns false when the caller
     * may see nothing (answer an empty list without querying).
     */
    public static function apply(string &$sql, array &$par, ?array $teams): bool
    {
        if ($teams === null) {
            return true;
        }
        $tim = array_values(array_filter($teams, 'strlen'));
        if (! $tim) {
            return false;
        }
        $sql .= ' AND `tim` IN ('.implode(',', array_fill(0, count($tim), '?')).')';
        foreach ($tim as $t) {
            $par[] = $t;
        }

        return true;
    }
}
