<?php

declare(strict_types=1);

namespace App\Modules\Automation\Maps;

use App\Modules\Automation\Feeds\ReservasiHarian;

/**
 * Public denah renderer (option A). Draws the built-in table plans with a
 * TERISI overlay for one date + time, as plain HTML — the customer's browser
 * does the drawing, the server only sends kilobytes.
 *
 * Privacy: booked tables are marked TERISI with the blocking time; guest
 * names, phones, fees and DP never leave the server. Per-date master
 * overrides are NOT applied in v1 (built-in plan of the day type only).
 */
final class DenahMap
{
    private const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    private const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
        'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /*
     * Look follows the Office reservasi/marketing seat picker: every FREE table
     * is one cream colour (no per-zone fills — the customer only needs to know
     * "can I sit here"), name + "N pax" inside, "bisa N pax" caption under the
     * table; BOOKED tables are gold with a "terisi" caption. Fixed objects
     * (stage, DJ, entrance, stairs) keep their own neutral styles.
     */
    private const FIXED_CLASS = [
        'stage' => 'fx-stage', 'dj' => 'fx-dark', 'entrance' => 'fx-entrance',
        'floor2' => 'fx-area', 'tangga' => 'fx-muted', 'foh' => 'fx-muted',
    ];

    public function __construct(private readonly ReservasiHarian $reservasi) {}

    /** @return array<string, true> booked table ids at date+time (buffer < 180 min). */
    public function bookedFor(string $date, string $time): array
    {
        $req = self::toMin($time);
        if ($req === null) {
            return [];
        }
        $day = $this->reservasi->daily($date);
        $out = [];
        foreach ($day['rows'] as $r) {
            $tm = self::toMin((string) ($r['time'] ?? ''));
            if ($tm === null || abs($tm - $req) >= 180) {
                continue;
            }
            foreach (explode(',', (string) ($r['tables'] ?? '')) as $t) {
                $t = trim($t);
                if ($t !== '' && $t !== '-') {
                    $out[$t] = true;
                }
            }
        }

        return $out;
    }

    public function render(string $date, string $time, int $expiresAt): string
    {
        $dow = (int) (new \DateTimeImmutable($date.'T00:00:00'))->format('w');
        $weekend = $dow === 0 || $dow === 5 || $dow === 6;
        $booked = $this->bookedFor($date, $time);
        $title = self::HARI[$dow].', '.((int) substr($date, 8, 2)).' '.self::BULAN[((int) substr($date, 5, 2)) - 1].' '.substr($date, 0, 4);
        $exp = (new \DateTimeImmutable('@'.$expiresAt))->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i');

        $body = '<div class="wrap">'
            .'<h1>Denah Meja • '.e($title).' jam '.e(str_replace(':', '.', $time)).' WIB</h1>'
            .'<p class="legend"><span class="sw free"></span>Tersedia<span class="sw busy"></span>Terisi '
            .'<span class="exp">Link berlaku hingga '.e($exp).' WIB</span></p>'
            .$this->canvas($weekend ? 'weekend' : 'weekday', 'Lantai 1', $booked)
            .$this->canvas($weekend ? 'lantai2_weekend' : 'lantai2', 'Lantai 2', $booked)
            .'<p class="foot">Laksamana Muda • denah standar, tanpa nama tamu</p></div>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Denah Meja • '.e($title).'</title><style>'
            .'body{font-family:"Plus Jakarta Sans",Inter,system-ui,-apple-system,sans-serif;background:#faf8f3;color:#3d3426;margin:0;padding:12px}'
            .'.wrap{max-width:1100px;margin:0 auto}h1{font-size:18px;margin:4px 0 2px;color:#2a2318}'
            .'.legend{font-size:13px;color:#7a6f5c}.sw{display:inline-block;width:12px;height:12px;border-radius:3px;margin:0 5px 0 12px;vertical-align:-1px}'
            .'.sw.free{background:#EFE6CE;border:1px solid rgba(120,90,30,.3)}.sw.busy{background:#E0A82E;border:1px solid #b98417}'
            .'.exp{float:right}.floor{font-size:14px;margin:16px 0 6px;color:#2a2318}'
            .'.canvas{position:relative;width:100%;aspect-ratio:1600/1160;background:#f6f3ee;border:1px solid #e8e1d3;border-radius:14px;overflow:hidden;container-type:inline-size}'
            .'.tbl{position:absolute;background:#EFE6CE;border:1px solid rgba(120,90,30,.22);border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;box-sizing:border-box;overflow:hidden;line-height:1.05}'
            .'.tbl b{font-weight:800;color:#5a4a2a;font-size:clamp(8px,1.15cqw,16px)}'
            .'.tbl span{font-weight:700;color:#7d6c49;font-size:clamp(6px,.62cqw,10px)}'
            .'.tbl.busy{background:#E0A82E;border-color:#b98417}.tbl.busy b{color:#3a2a00}.tbl.busy span{color:#5c4200}'
            .'.cap{position:absolute;text-align:center;font-weight:700;color:#8f8676;font-size:clamp(5px,.6cqw,10px);white-space:nowrap;line-height:1}'
            .'.cap.busy{color:#9b2c1c;font-weight:800}'
            .'.fix{position:absolute;display:flex;align-items:center;justify-content:center;text-align:center;border-radius:6px;font-weight:800;letter-spacing:.5px;font-size:clamp(7px,1.6cqw,26px);box-sizing:border-box}'
            .'.fx-stage{background:#B8B2A8;color:#fff}'
            .'.fx-dark{background:#3d3a35;color:#fff;font-size:clamp(6px,.8cqw,13px)}'
            .'.fx-entrance{background:linear-gradient(180deg,#E9851A,#F6B13A);color:#3a2205;writing-mode:vertical-rl;transform:rotate(180deg);font-size:clamp(5px,.65cqw,11px)}'
            .'.fx-area{background:transparent;border:1.5px dashed #d6ccb8;color:#c9bfa9;font-size:clamp(7px,1.1cqw,18px)}'
            .'.fx-muted{background:#d9d2c5;color:#fff;font-size:clamp(6px,.8cqw,13px)}'
            .'.foot{font-size:12px;color:#a39a89;margin:10px 0 20px}'
            .'</style></head><body>'.$body.'</body></html>';
    }

    /** @param array<string, true> $booked */
    private function canvas(string $key, string $label, array $booked): string
    {
        $plans = VenueLayouts::all();
        $plan = $plans[$key] ?? null;
        if ($plan === null) {
            return '';
        }
        $w = VenueLayouts::W;
        $h = VenueLayouts::H;
        $pct = fn ($v, $base): string => rtrim(rtrim(number_format($v / $base * 100, 3, '.', ''), '0'), '.').'%';
        $html = '<h2 class="floor">'.e($label).'</h2><div class="canvas">';
        foreach ($plan['fixed'] as $f) {
            $cls = self::FIXED_CLASS[(string) ($f['t'] ?? '')] ?? 'fx-muted';
            $html .= '<div class="fix '.$cls.'" style="left:'.$pct((float) $f['x'], $w).';top:'.$pct((float) $f['y'], $h)
                .';width:'.$pct((float) $f['w'], $w).';height:'.$pct((float) $f['h'], $h).'">'.e((string) $f['label']).'</div>';
        }
        foreach ($plan['tables'] as $t) {
            $id = (string) $t['id'];
            $busy = isset($booked[$id]);
            $cap = (string) ($t['cap'] ?? '');
            $paxTeks = $cap !== '' ? $cap.' pax' : '';
            $x = (float) $t['x'];
            $y = (float) $t['y'];
            $tw = (float) $t['w'];
            $th = (float) $t['h'];
            $html .= '<div class="tbl'.($busy ? ' busy' : '').'" data-table="'.e($id).'" data-status="'.($busy ? 'booked' : 'free').'"'
                .' style="left:'.$pct($x, $w).';top:'.$pct($y, $h)
                .';width:'.$pct($tw, $w).';height:'.$pct($th, $h).'">'
                .'<b>'.e($id).'</b>'.($paxTeks !== '' ? '<span>'.e($paxTeks).'</span>' : '').'</div>';
            // Caption UNDER the table, like the Office seat picker: what a free
            // table holds, or that it is taken — readable without zooming in.
            // Skipped where another table sits right below (tight rows like
            // M1–M10): the caption would hide under it, and "N pax" is already
            // inside the box.
            $capTeks = $busy ? 'terisi' : ($paxTeks !== '' ? 'bisa '.$paxTeks : '');
            if ($capTeks !== '' && self::adaRuangBawah($plan, $x, $y + $th, $tw)) {
                $html .= '<div class="cap'.($busy ? ' busy' : '').'" style="left:'.$pct($x - 20, $w).';top:'.$pct($y + $th + 6, $h)
                    .';width:'.$pct($tw + 40, $w).'">'.e($capTeks).'</div>';
            }
        }

        return $html.'</div>';
    }

    /**
     * True when nothing (table or fixed object, except the big lantai-2 area
     * outline) starts within 26 canvas units below $bottom across [$x, $x+$w].
     *
     * @param  array{fixed:list<array>, tables:list<array>}  $plan
     */
    private static function adaRuangBawah(array $plan, float $x, float $bottom, float $w): bool
    {
        $lain = array_merge($plan['tables'], array_filter($plan['fixed'], fn ($f) => ($f['t'] ?? '') !== 'floor2'));
        foreach ($lain as $o) {
            $ox = (float) $o['x'];
            $oy = (float) $o['y'];
            $ow = (float) $o['w'];
            if ($oy >= $bottom - 1 && $oy < $bottom + 26 && $ox < $x + $w && $ox + $ow > $x) {
                return false;
            }
        }

        return true;
    }

    private static function toMin(string $t): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mi = (int) $m[2];
        if ($h > 23 || $mi > 59) {
            return null;
        }

        return $h * 60 + $mi;
    }
}
