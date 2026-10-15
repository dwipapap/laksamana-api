<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — push devices and geofence crossings (B4). The device only
 * reports ENTER/EXIT; the server decides whether a reminder goes out — and
 * never punches automatically (A8).
 */
final class HrisDeviceController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisReminderService $pengingat,
    ) {
        parent::__construct($access);
    }

    /** Register (or move) an Expo push token for the token holder. */
    public function register(Request $r): JsonResponse
    {
        $d = $r->validate([
            'token' => ['required', 'string', 'max:190'],
            'platform' => ['nullable', 'string', 'max:16'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->pengingat->daftarkanPerangkat(self::s($me['id']), $d['token'], $d['platform'] ?? 'android');

            return ['terdaftar' => true];
        }, 201);
    }

    /** Remove one of the holder's tokens (logout, uninstall). */
    public function remove(Request $r): JsonResponse
    {
        $d = $r->validate(['token' => ['required', 'string', 'max:190']]);
        $me = $this->me($r);

        return $this->run(fn () => ['dihapus' => $this->pengingat->hapusPerangkat(
            self::s($me['id']), $d['token']) ? 1 : 0]);
    }

    /** A geofence crossing from the device; the server may remind, never punch. */
    public function geofence(Request $r): JsonResponse
    {
        $d = $r->validate([
            'lokasi_id' => ['nullable', 'string', 'max:32'],
            'tipe' => ['required', 'string', 'max:8'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengingat->geofence(
            self::s($me['id']), $d['lokasi_id'] ?? '', $d['tipe']));
    }
}
