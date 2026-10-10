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

    private const ZONE_FILL = [
        'green' => '#dcebd2', 'blue' => '#d3e2f7', 'wood' => '#eadfc6',
        'vip' => '#e2d5f6', 'ext' => '#f0e9d4', 'dark' => '#d9dfe7', 'diamond' => '#e7ddf1',
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
            .'<p class="legend"><span class="sw free"></span>Tersedia <span class="sw busy"></span>Terisi '
            .'<span class="exp">Link berlaku hingga '.e($exp).' WIB</span></p>'
            .$this->canvas($weekend ? 'weekend' : 'weekday', 'Lantai 1', $booked)
            .$this->canvas($weekend ? 'lantai2_weekend' : 'lantai2', 'Lantai 2', $booked)
            .'<p class="foot">Laksamana Muda • denah standar, tanpa nama tamu</p></div>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Denah Meja • '.e($title).'</title><style>'
            .'body{font-family:system-ui,-apple-system,sans-serif;background:#f6f4ee;color:#333;margin:0;padding:12px}'
            .'.wrap{max-width:1000px;margin:0 auto}h1{font-size:18px;margin:4px 0 2px}'
            .'.legend{font-size:13px;color:#666}.sw{display:inline-block;width:12px;height:12px;border-radius:3px;margin:0 4px 0 10px;vertical-align:-1px}'
            .'.sw.free{background:#dcebd2;border:1px solid #9db98f}.sw.busy{background:#f3c9c9;border:1px solid #c66}'
            .'.exp{float:right}.floor{font-size:14px;margin:14px 0 4px}'
            .'.canvas{position:relative;width:100%;aspect-ratio:1600/1160;background:#fffdf8;border:1px solid #e0d8c4;border-radius:8px;overflow:hidden}'
            .'.tbl{position:absolute;border:1px solid #b9ac8d;border-radius:6px;font-size:10px;line-height:1.25;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:1px;box-sizing:border-box;overflow:hidden}'
            .'.tbl b{font-size:11px}.tbl.busy{background:#f3c9c9!important;border-color:#c66}.tbl.busy .st{color:#a33;font-weight:bold}'
            .'.fix{position:absolute;background:#cfc8bd;color:#fff;font-weight:bold;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:12px;letter-spacing:1px}'
            .'.foot{font-size:12px;color:#999;margin:10px 0 20px}'
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
            $html .= '<div class="fix" style="left:'.$pct((float) $f['x'], $w).';top:'.$pct((float) $f['y'], $h)
                .';width:'.$pct((float) $f['w'], $w).';height:'.$pct((float) $f['h'], $h).'">'.e((string) $f['label']).'</div>';
        }
        foreach ($plan['tables'] as $t) {
            $id = (string) $t['id'];
            $busy = isset($booked[$id]);
            $fill = self::ZONE_FILL[(string) ($t['zone'] ?? '')] ?? '#eee';
            $html .= '<div class="tbl'.($busy ? ' busy' : '').'" data-table="'.e($id).'" data-status="'.($busy ? 'booked' : 'free').'"'
                .' style="left:'.$pct((float) $t['x'], $w).';top:'.$pct((float) $t['y'], $h)
                .';width:'.$pct((float) $t['w'], $w).';height:'.$pct((float) $t['h'], $h).';background:'.$fill.'">'
                .'<b>'.e($id).'</b><span>'.e((string) ($t['cap'] ?? '')).'</span>'
                .($busy ? '<span class="st">TERISI</span>' : '').'</div>';
        }

        return $html.'</div>';
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
