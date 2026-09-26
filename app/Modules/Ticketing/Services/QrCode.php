<?php

namespace App\Modules\Ticketing\Services;

use Exception;

/**
 * QR code generator: byte mode, error correction level M, versions 1..10. This is
 * a faithful port of legacy ticketing-mysql/lib_qr.php, which is itself a port
 * of qrMatrix() in deploy/ticketing/index.html.
 *
 * The on-screen QR (JS) and the PDF QR (this class) MUST be the same matrix for
 * the same text. A mismatch only shows up when staff scan a printed ticket at
 * the door. tests/Unit/TicketingQrPdfTest.php pins this class to vectors
 * recorded from the legacy PHP, which tools/uji-qr.js proves equal to the JS.
 *
 * Ticket tokens are never sent to a third-party QR service: the token alone
 * decides who gets in.
 */
class QrCode
{
    /** Per version: [ecCodewordsPerBlock, blocksG1, dataG1, blocksG2, dataG2] */
    private const M = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0],
        4 => [18, 2, 32, 0, 0], 5 => [24, 2, 43, 0, 0], 6 => [16, 4, 27, 0, 0],
        7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39], 9 => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private static ?array $gf = null;

    /** GF(256) exp/log tables, polynomial 0x11d. */
    private static function gf(): array
    {
        if (self::$gf === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
            self::$gf = ['exp' => $exp, 'log' => $log];
        }

        return self::$gf;
    }

    private static function gfmul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        $g = self::gf();

        return $g['exp'][$g['log'][$a] + $g['log'][$b]];
    }

    /** Generator polynomial for n EC codewords, REVERSED: rsEncode works in descending degree (as rsGen() in the JS). */
    private static function rsGen(int $n): array
    {
        $g = self::gf();
        $poly = [1];
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $next[$j] ^= self::gfmul($poly[$j], $g['exp'][$i]);
                $next[$j + 1] ^= $poly[$j];
            }
            $poly = $next;
        }

        return array_reverse($poly);
    }

    private static function rsEncode(array $data, int $n): array
    {
        $gen = self::rsGen($n);
        $res = array_fill(0, $n, 0);
        foreach ($data as $d) {
            $f = $d ^ $res[0];
            array_shift($res);
            $res[] = 0;
            for ($j = 0; $j < $n; $j++) {
                $res[$j] ^= self::gfmul($gen[$j + 1], $f);
            }
        }

        return $res;
    }

    private static function bitLen(int $x): int
    {
        $n = 0;
        while ($x) {
            $n++;
            $x >>= 1;
        }

        return $n;
    }

    private static function bch(int $value, int $gen, int $bits): int
    {
        $v = $value << ($bits - 1);
        while (self::bitLen($v) >= self::bitLen($gen)) {
            $v ^= $gen << (self::bitLen($v) - self::bitLen($gen));
        }

        return $v;
    }

    /** Level M = 00, combined with the mask number, XORed with the standard mask. */
    private static function formatBits(int $mask): int
    {
        $data = (0 << 3) | $mask;

        return (($data << 10) | self::bch($data, 0x537, 11)) ^ 0x5412;
    }

    private static function versionBits(int $v): int
    {
        return ($v << 12) | self::bch($v, 0x1F25, 13);
    }

    /** The 15 format-info cells, both copies; shared by reservation and writing so they cannot differ. */
    private static function formatCells(int $N): array
    {
        $s = [];
        for ($i = 0; $i <= 5; $i++) {
            $s[] = [$i, 8];
        }
        $s[] = [7, 8];
        $s[] = [8, 8];
        $s[] = [8, 7];
        for ($i = 9; $i < 15; $i++) {
            $s[] = [8, 14 - $i];
        }
        for ($i = 0; $i < 8; $i++) {
            $s[] = [8, $N - 1 - $i];
        }
        for ($i = 8; $i < 15; $i++) {
            $s[] = [$N - 15 + $i, 8];
        }

        return $s;
    }

    /** Standard penalty: same-colour runs, 2x2 blocks, finder-like patterns, dark/light imbalance. */
    private static function penalty(array $m, int $N): int
    {
        $s = 0;
        for ($r = 0; $r < $N; $r++) {
            for ($dir = 0; $dir < 2; $dir++) {
                $run = 1;
                for ($i = 1; $i < $N; $i++) {
                    $a = $dir ? $m[$i - 1][$r] : $m[$r][$i - 1];
                    $b = $dir ? $m[$i][$r] : $m[$r][$i];
                    if ($a === $b) {
                        $run++;
                        if ($run === 5) {
                            $s += 3;
                        } elseif ($run > 5) {
                            $s++;
                        }
                    } else {
                        $run = 1;
                    }
                }
            }
        }
        for ($r = 0; $r < $N - 1; $r++) {
            for ($c = 0; $c < $N - 1; $c++) {
                if ($m[$r][$c] === $m[$r][$c + 1] && $m[$r][$c] === $m[$r + 1][$c] && $m[$r][$c] === $m[$r + 1][$c + 1]) {
                    $s += 3;
                }
            }
        }
        $p = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pR = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $N; $r++) {
            for ($c = 0; $c <= $N - 11; $c++) {
                $c1 = $c2 = $c3 = $c4 = true;
                for ($i = 0; $i < 11; $i++) {
                    if ($m[$r][$c + $i] !== $p[$i]) {
                        $c1 = false;
                    }
                    if ($m[$r][$c + $i] !== $pR[$i]) {
                        $c2 = false;
                    }
                    if ($m[$c + $i][$r] !== $p[$i]) {
                        $c3 = false;
                    }
                    if ($m[$c + $i][$r] !== $pR[$i]) {
                        $c4 = false;
                    }
                }
                $s += 40 * ((int) $c1 + (int) $c2 + (int) $c3 + (int) $c4);
            }
        }
        $dark = 0;
        for ($r = 0; $r < $N; $r++) {
            for ($c = 0; $c < $N; $c++) {
                $dark += $m[$r][$c];
            }
        }

        return $s + (int) floor(abs($dark * 100 / ($N * $N) - 50) / 5) * 10;
    }

    /**
     * NxN matrix of 0/1 (legacy qr_matriks). Throws when the text does not fit
     * version 10 (~210 bytes); a ticket token is far below that.
     *
     * @return list<list<int>>
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);

        $ver = 0;
        for ($v = 1; $v <= 10; $v++) {
            [, $b1, $d1, $b2, $d2] = self::M[$v];
            if (count($bytes) + 2 <= $b1 * $d1 + $b2 * $d2) {
                $ver = $v;
                break;
            }
        }
        if (! $ver) {
            throw new Exception('Teks terlalu panjang untuk QR versi 10 (maks ~210 karakter)');
        }

        [$ecLen, $b1, $d1, $b2, $d2] = self::M[$ver];
        $totalData = $b1 * $d1 + $b2 * $d2;

        // bit stream: mode 0100 + length + payload + terminator + padding
        $bits = [];
        $push = function (int $value, int $n) use (&$bits) {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(4, 4);
        $push(count($bytes), $ver <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            $push($b, 8);
        }
        for ($i = 0; $i < 4 && count($bits) < $totalData * 8; $i++) {
            $bits[] = 0;
        }
        while (count($bits) % 8) {
            $bits[] = 0;
        }
        $words = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($k = 0; $k < 8; $k++) {
                $v = ($v << 1) | $bits[$i + $k];
            }
            $words[] = $v;
        }
        $pad = [0xEC, 0x11];
        for ($i = 0; count($words) < $totalData; $i++) {
            $words[] = $pad[$i % 2];
        }

        // split into blocks, compute EC, interleave
        $dataBlocks = [];
        $ecBlocks = [];
        $p = 0;
        for ($i = 0; $i < $b1 + $b2; $i++) {
            $n = $i < $b1 ? $d1 : $d2;
            $d = array_slice($words, $p, $n);
            $p += $n;
            $dataBlocks[] = $d;
            $ecBlocks[] = self::rsEncode($d, $ecLen);
        }
        $final = [];
        $maxD = max($d1, $d2);
        for ($i = 0; $i < $maxD; $i++) {
            foreach ($dataBlocks as $b) {
                if ($i < count($b)) {
                    $final[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecBlocks as $b) {
                $final[] = $b[$i];
            }
        }

        // function patterns (null = not yet filled)
        $N = $ver * 4 + 17;
        $m = [];
        for ($r = 0; $r < $N; $r++) {
            $m[$r] = array_fill(0, $N, null);
        }
        $put = function (int $r, int $c, int $v) use (&$m, $N) {
            if ($r >= 0 && $r < $N && $c >= 0 && $c < $N) {
                $m[$r][$c] = $v;
            }
        };
        $finder = function (int $r, int $c) use ($put, $N) {
            for ($dr = -1; $dr <= 7; $dr++) {
                for ($dc = -1; $dc <= 7; $dc++) {
                    $rr = $r + $dr;
                    $cc = $c + $dc;
                    if ($rr < 0 || $rr >= $N || $cc < 0 || $cc >= $N) {
                        continue;
                    }
                    $in = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6);
                    $dark = $in && (($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6)
                        || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4));
                    $put($rr, $cc, $dark ? 1 : 0);
                }
            }
        };
        $finder(0, 0);
        $finder(0, $N - 7);
        $finder($N - 7, 0);

        for ($i = 8; $i < $N - 8; $i++) {
            $v = ($i % 2 === 0) ? 1 : 0;
            $put(6, $i, $v);
            $put($i, 6, $v);
        }

        foreach (self::ALIGN[$ver] as $r) {
            foreach (self::ALIGN[$ver] as $c) {
                if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $N - 9) || ($r >= $N - 9 && $c <= 8)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $put($r + $dr, $c + $dc, (abs($dr) === 2 || abs($dc) === 2 || ($dr === 0 && $dc === 0)) ? 1 : 0);
                    }
                }
            }
        }
        $put($N - 8, 8, 1); // dark module

        // reserve format info so data never lands there
        $fmtCells = self::formatCells($N);
        foreach ($fmtCells as $rc) {
            if ($m[$rc[0]][$rc[1]] === null) {
                $m[$rc[0]][$rc[1]] = 0;
            }
        }

        if ($ver >= 7) {
            $vb = self::versionBits($ver);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vb >> $i) & 1;
                $put((int) floor($i / 3), $N - 11 + ($i % 3), $bit);
                $put($N - 11 + ($i % 3), (int) floor($i / 3), $bit);
            }
        }

        // zig-zag data fill from the bottom right
        $free = [];
        for ($r = 0; $r < $N; $r++) {
            for ($c = 0; $c < $N; $c++) {
                $free[$r][$c] = ($m[$r][$c] === null);
            }
        }
        $bitIdx = 0;
        $nextBit = function () use (&$bitIdx, $final) {
            $byte = $final[$bitIdx >> 3] ?? null;
            $b = ($byte === null) ? 0 : (($byte >> (7 - ($bitIdx & 7))) & 1);
            $bitIdx++;

            return $b;
        };
        $up = true;
        for ($col = $N - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // skip the timing column
            }
            for ($i = 0; $i < $N; $i++) {
                $row = $up ? $N - 1 - $i : $i;
                foreach ([$col, $col - 1] as $c) {
                    if ($free[$row][$c]) {
                        $m[$row][$c] = $nextBit();
                    }
                }
            }
            $up = ! $up;
        }

        // pick the mask with the lowest penalty (first wins on a tie)
        $best = null;
        $minPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $t = $m;
            for ($r = 0; $r < $N; $r++) {
                for ($c = 0; $c < $N; $c++) {
                    if (! $free[$r][$c]) {
                        continue;
                    }
                    $hit = match ($mask) {
                        0 => ($r + $c) % 2 === 0,
                        1 => $r % 2 === 0,
                        2 => $c % 3 === 0,
                        3 => ($r + $c) % 3 === 0,
                        4 => ((($r >> 1) + (int) floor($c / 3)) % 2) === 0,
                        5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
                        6 => ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0,
                        7 => ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0,
                    };
                    if ($hit) {
                        $t[$r][$c] ^= 1;
                    }
                }
            }
            $fb = self::formatBits($mask);
            foreach ($fmtCells as $i => $rc) {
                $t[$rc[0]][$rc[1]] = ($fb >> ($i % 15)) & 1;
            }
            $t[$N - 8][8] = 1; // dark module
            $d = self::penalty($t, $N);
            if ($d < $minPenalty) {
                $minPenalty = $d;
                $best = $t;
            }
        }

        return $best;
    }
}
