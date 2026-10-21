<?php

namespace App\Modules\Marketing\Services;

/**
 * Reference photos of design requests (`designreqs[].refs`, {t, v, n}) are
 * base64 data URIs kept inside the row. Three rows already made up 77% of the
 * v1 `/state` answer (#341), and every Marketing screen waits for that answer
 * while only the Request Design detail ever shows the photos.
 *
 * So `/state` sends each photo as a stub — {t:'img', n, dipangkas:true}, no
 * `v` — and the photos come from GET /design-requests/{id}. The legacy getAll
 * is untouched: the old Office reads the photos straight from it.
 *
 * The write guard is the half that matters: a client that saves a row it took
 * from `/state` sends the stubs back. Without restore(), one save would replace
 * every photo with nothing — and from the server's side that is a valid write.
 */
final class DesignRefs
{
    public const STUB = 'dipangkas';

    /** @param  list<mixed>  $rows  designreqs as stored */
    public static function trimRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row) || ! isset($row['refs']) || ! is_array($row['refs'])) {
                continue;
            }
            foreach ($row['refs'] as $j => $ref) {
                if (is_array($ref) && ($ref['t'] ?? '') === 'img' && isset($ref['v'])) {
                    unset($ref['v']);
                    $ref[self::STUB] = true;
                    $rows[$i]['refs'][$j] = $ref;
                }
            }
        }

        return $rows;
    }

    /**
     * Put the stored photo back into every stub of $next. A stub is matched to
     * the stored photos by name first, then by its position among the photos;
     * each stored photo is used once. A stub with nothing to match is dropped
     * (it carries no picture), never stored as an empty image.
     */
    public static function restore(array $next, ?array $cur): array
    {
        if (! isset($next['refs']) || ! is_array($next['refs'])) {
            return $next;
        }
        $stored = [];
        foreach ((is_array($cur['refs'] ?? null) ? $cur['refs'] : []) as $ref) {
            if (is_array($ref) && ($ref['t'] ?? '') === 'img' && isset($ref['v']) && $ref['v'] !== '') {
                $stored[] = $ref;
            }
        }
        $used = [];
        $out = [];
        $imgPos = -1;
        foreach ($next['refs'] as $ref) {
            if (! is_array($ref) || ($ref['t'] ?? '') !== 'img') {
                $out[] = $ref;

                continue;
            }
            $imgPos++;
            $isStub = ! empty($ref[self::STUB]) || ! isset($ref['v']) || $ref['v'] === '';
            if (! $isStub) {
                unset($ref[self::STUB]);
                $out[] = $ref;

                continue;
            }
            $hit = null;
            foreach ($stored as $k => $s) {
                if (! isset($used[$k]) && (string) ($s['n'] ?? '') === (string) ($ref['n'] ?? '') && ($ref['n'] ?? '') !== '') {
                    $hit = $k;
                    break;
                }
            }
            if ($hit === null && isset($stored[$imgPos]) && ! isset($used[$imgPos])) {
                $hit = $imgPos;
            }
            if ($hit === null) {
                continue;
            }
            $used[$hit] = true;
            $out[] = $stored[$hit];
        }
        $next['refs'] = array_values($out);

        return $next;
    }
}
