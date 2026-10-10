<?php

namespace App\Support;

/**
 * Tim — the team(s) a User belongs to, read from the Tim column (`keterangan`).
 *
 * Used by laksamana-office-vue's Beranda to show each team its own modules and
 * teammates. It is NOT an access rule: module access stays with OfficeAccess,
 * and shift Divisi stays with Divisi (this list is broader: Marketing, HR, BD…
 * all fall under Divisi "office"/"nonshift").
 *
 * Matching is whole-word on the Tim column, like Divisi::timCocok, and a User
 * may belong to several teams ("Kitchen Bar" -> kitchen + bar). Order follows
 * TIM, so callers get a stable list.
 */
final class Tim
{
    /** key => [label, words]. Words are lower-case, whole-word matched. */
    public const TIM = [
        'kitchen' => ['Kitchen', ['kitchen', 'dapur', 'cook', 'chef']],
        'bar' => ['Bar', ['bar', 'bartender', 'barista']],
        'floor' => ['Floor', ['floor', 'service', 'waiter', 'waitress', 'host', 'hostess', 'foh']],
        'cashier' => ['Cashier', ['cashier', 'kasir']],
        'marketing' => ['Marketing', ['marketing', 'sales', 'mkt']],
        'konten' => ['Konten', ['konten', 'content', 'creative', 'kreatif', 'desain', 'design', 'sosmed']],
        'event' => ['Event', ['event']],
        'hr' => ['HR', ['hr', 'hrd', 'people']],
        'bd' => ['BD', ['bd', 'bizdev', 'business']],
        'finance' => ['Finance', ['finance', 'keuangan', 'accounting', 'akunting']],
        'manajemen' => ['Manajemen', ['ceo', 'owner', 'manager', 'manajer', 'gm', 'direktur', 'management', 'manajemen', 'nahkoda']],
    ];

    /** @return array<int,string> team keys found in the Tim column, in TIM order */
    public static function keys(?string $keterangan): array
    {
        $kata = preg_split('/[^a-z]+/', strtolower((string) $keterangan), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach (self::TIM as $key => [, $words]) {
            if (array_intersect($words, $kata)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return array<int,array{key:string,label:string}> */
    public static function of(?string $keterangan): array
    {
        return array_map(fn (string $k) => ['key' => $k, 'label' => self::TIM[$k][0]], self::keys($keterangan));
    }
}
