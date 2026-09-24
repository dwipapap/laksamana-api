<?php

namespace App\Support\Legacy;

use App\Auth\LegacySessions;
use App\Auth\OfficeAccess;
use App\Modules\Account\Services\AccountService;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Port of `lib_sesi.php` (the byte-identical copy in dw-, jadwal- and
 * kompas-mysql). Legacy modules asked account-api `whoami` over HTTP; here the
 * same answer comes in-process from OfficeAccess.
 *
 * The caller may identify itself two ways:
 *   - the old Office session token (`?sesi=` or `body.sesi`) — existing frontends
 *   - a Sanctum Bearer token — so new apps can also call legacy routes
 *
 * Rejections keep their exact prefixes; frontends branch on them:
 *   sesi_tidak_sah:  unknown/expired token -> log in again
 *   tanpa_modul:     valid token, module not granted -> ask for the checkbox
 *   tidak_berhak:    logged in and has the module, but not this action
 *
 * Scoped per request (like the legacy `static $cache`).
 */
class Sesi
{
    private bool $resolved = false;

    private ?array $user = null;

    private ?array $roster = null;

    public function __construct(
        private readonly Request $request,
        private readonly OfficeAccess $access,
        private readonly LegacySessions $sessions,
    ) {}

    public function token(?LegacyRequest $req = null): string
    {
        $q = $this->request->query('sesi');
        if (is_string($q) && $q !== '') {
            return $q;
        }
        if ($req && isset($req->body['sesi'])) {
            return (string) $req->body['sesi'];
        }

        return '';
    }

    /**
     * The whoami payload {id,name,username,keterangan,modules,adminModules,headDivisi}
     * or null.
     */
    public function user(?LegacyRequest $req = null): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $row = $this->sessions->user($this->token($req));
        if (! $row) {
            $sanctum = Auth::guard('sanctum')->user();
            if ($sanctum && $sanctum->isActive()) {
                $row = $this->access->userById((string) $sanctum->getKey());
            }
        }

        return $this->user = $row ? $this->access->profile($row) : null;
    }

    public static function hasModule(?array $u, string $key): bool
    {
        if (! $u) {
            return false;
        }
        $m = $u['modules'] ?? [];

        return in_array('*', $m, true) || in_array($key, $m, true);
    }

    public static function isModuleAdmin(?array $u, string $key): bool
    {
        if (! $u) {
            return false;
        }
        $a = $u['adminModules'] ?? [];

        return in_array('*', $a, true) || in_array($key, $a, true);
    }

    /** Office roster keyed by id (listDivisiRoster members), per request. */
    public function roster(): array
    {
        if ($this->roster !== null) {
            return $this->roster;
        }
        $out = [];
        foreach (app(AccountService::class)->divisiRoster() as $m) {
            $out[(string) $m['id']] = $m;
        }

        return $this->roster = $out;
    }

    // ---- rejections (thrown; the legacy controller turns them into {ok:false,error}) ----

    public static function rejectUnknown(): never
    {
        throw new RuntimeException('sesi_tidak_sah: Sesi Anda tidak dikenali atau sudah berakhir. Muat ulang halaman (Ctrl+F5), lalu masuk lagi lewat Laksamana Office.');
    }

    public static function rejectForbidden(string $what): never
    {
        throw new RuntimeException('tidak_berhak: '.$what);
    }

    public static function rejectNoModule(string $checkboxLabel): never
    {
        throw new RuntimeException('tanpa_modul: Akun Anda belum diberi akses modul ini. '
            .'Minta admin mencentang "'.$checkboxLabel.'" lewat Kelola Akses di Laksamana Office.');
    }

    /** Convenience: session must be valid AND hold $module. Returns the user. */
    public function requireModule(?LegacyRequest $req, string $module, string $checkboxLabel): array
    {
        $u = $this->user($req);
        if (! $u) {
            self::rejectUnknown();
        }
        if (! self::hasModule($u, $module)) {
            self::rejectNoModule($checkboxLabel);
        }

        return $u;
    }

    public function databaseName(string $module): string
    {
        return Modules::databaseName($module);
    }
}
