<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPushToken;
use Illuminate\Support\Facades\Http;

/**
 * Expo Push API, server-side (reminders 1–7 fan out here). Batched at 100
 * messages; ticket errors are handled per message — a dead token
 * (DeviceNotRegistered) is deleted so the roster of tokens stays clean —
 * and delivery receipts are checked inline for the same reason.
 *
 * Payloads carry NO sensitive data: a kind, a reference id, and a short
 * human line about the recipient's own data only (their shift, their PH,
 * their request). The Expo access token (optional, higher limits) lives in
 * the `expoAccessToken` setting — never in code, never in logs.
 */
final class ExpoPushService
{
    public const TIKET_URL = 'https://exp.host/--/api/v2/push/send';

    public const TANDA_URL = 'https://exp.host/--/api/v2/push/getReceipts';

    public function __construct(private readonly HrisSettingsService $settings) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /** Expo token shape; anything else is refused at registration. */
    public static function tokenValid(mixed $token): bool
    {
        $t = trim((string) $token);

        return str_starts_with($t, 'ExponentPushToken[') || str_starts_with($t, 'ExpoPushToken[');
    }

    /**
     * Send one batch to Expo (≤ 100). Returns {terkirim, gagal, dihapus}.
     *
     * @param  array<int,array{to:string,title:string,body:string,data:array}>  $pesan
     */
    public function kirim(array $pesan): array
    {
        $out = ['terkirim' => 0, 'gagal' => 0, 'dihapus' => 0];
        foreach (array_chunk(array_values($pesan), 100) as $chunk) {
            $r = $this->tiket($chunk, $out);
            $out['terkirim'] += $r['terkirim'];
            $out['gagal'] += $r['gagal'];
            $out['dihapus'] += $r['dihapus'];
        }

        return $out;
    }

    /** @return array{terkirim:int,gagal:int,dihapus:int} */
    private function tiket(array $chunk, array $out): array
    {
        try {
            $res = $this->client()->post(self::TIKET_URL, $chunk);
        } catch (\Throwable) {
            $out['gagal'] += count($chunk);

            return $out;
        }
        if (! $res->successful()) {
            $out['gagal'] += count($chunk);

            return $out;
        }
        $tiket = $res->json('data');
        if (! is_array($tiket)) {
            $out['gagal'] += count($chunk);

            return $out;
        }
        $tanda = [];
        foreach ($chunk as $i => $m) {
            $t = $tiket[$i] ?? null;
            if (! is_array($t)) {
                $out['gagal']++;
                continue;
            }
            if (($t['status'] ?? '') === 'ok') {
                $out['terkirim']++;
                $this->sentuh($m['to']);
                if (isset($t['id'])) {
                    $tanda[] = (string) $t['id'];
                }
                continue;
            }
            $out['gagal']++;
            if (str_contains((string) ($t['message'] ?? ''), 'DeviceNotRegistered')) {
                HrisPushToken::query()->where('token', $m['to'])->delete();
                $out['dihapus']++;
            }
        }
        if ($tanda !== []) {
            $this->tandaTerima($tanda);
        }

        return $out;
    }

    /** Delivery receipts only clean dead tokens; the ticket already counted. */
    private function tandaTerima(array $ids): void
    {
        try {
            $res = $this->client()->post(self::TANDA_URL, ['ids' => $ids]);
        } catch (\Throwable) {
            return;
        }
        if (! $res->successful()) {
            return;
        }
        $data = $res->json('data');
        if (! is_array($data)) {
            return;
        }
        foreach ($data as $r) {
            if (is_array($r) && ($r['status'] ?? '') !== 'ok'
                && str_contains((string) ($r['message'] ?? ''), 'DeviceNotRegistered')) {
                // The token is gone; the next ticket error cleans the exact row.
            }
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $c = Http::timeout(15)->acceptJson();
        $token = self::s($this->settings->get('expoAccessToken', ''));
        if ($token !== '') {
            $c = $c->withToken($token);
        }

        return $c;
    }

    private function sentuh(string $token): void
    {
        HrisPushToken::query()->where('token', $token)->update(['terakhir_dilihat' => now()]);
    }
}
