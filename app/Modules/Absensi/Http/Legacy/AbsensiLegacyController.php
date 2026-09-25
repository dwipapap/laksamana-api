<?php

namespace App\Modules\Absensi\Http\Legacy;

use App\Modules\Absensi\Services\AbsensiService;
use App\Support\Legacy\Envelope;
use App\Support\Legacy\LegacyController;
use App\Support\Legacy\LegacyRequest;
use App\Support\Legacy\Sesi;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for absensi-mysql/api.php — `/absensi/api/api.php`, which on its
 * own subdomain (absensi.laksamanamuda.id) resolves to `/api/api.php`.
 * Both paths are registered (routes/legacy.php).
 *
 * The whole punch screen runs on this one endpoint: masuk/konteks, absen
 * (face distance, GPS radius, night-shift work date, the six queue reasons),
 * daftarWajah/hapusWajah/wajahDaftar, antrean/putusAbsen, rekap, and the
 * location + settings actions. Full old-vs-new parity lives in
 * tools/parity/cases/absensi.json.
 *
 * The gates keep their exact legacy messages (plain Indonesian, NOT the
 * sesi_tidak_sah:/tanpa_modul: family) — the PWA branches on them.
 */
class AbsensiLegacyController extends LegacyController
{
    protected string $defaultGetAction = 'konteks';

    public function __construct(
        private readonly AbsensiService $absensi,
        private readonly Sesi $sesi,
    ) {}

    /** Session must be valid AND hold the absensi module. */
    private function masuk(LegacyRequest $req): array
    {
        $u = $this->sesi->user($req);
        if (! $u) {
            throw new \RuntimeException('Sesi tidak dikenali. Masuk lagi ya.');
        }
        if (! AbsensiService::boleh($u)) {
            throw new \RuntimeException('Akun ini tidak punya akses modul Absensi.');
        }

        return $u;
    }

    private function hr(LegacyRequest $req): array
    {
        $u = $this->masuk($req);
        if (! $this->absensi->isHr($u)) {
            throw new \RuntimeException('Hanya HR/admin modul yang boleh melakukan ini.');
        }

        return $u;
    }

    /** GET query wins over the body — the `isset($_GET[x]) ? … : $body[x]` idiom. */
    private function param(LegacyRequest $req, string $key, mixed $default = ''): mixed
    {
        $q = $req->http->query($key);

        return $q !== null ? $q : ($req->body[$key] ?? $default);
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    protected function dispatch(LegacyRequest $req): Response
    {
        $b = $req->body;
        $svc = $this->absensi;
        $ambil = fn (string $k, mixed $def = '') => $b[$k] ?? $def;

        switch ($req->action) {
            case 'masuk':
                return Envelope::okData(['user' => $svc->loginMasuk($ambil('nama', ''), $ambil('pin', ''))]);

            case 'konteks':
                return Envelope::okData($svc->context($this->sesi->user($req)));

            case 'absen':
                $u = $this->masuk($req);
                // THE subject always comes from the token, never the body:
                // the one line stopping anyone from punching in their mate —
                // with no "except admins" carve-out (admins fix others' rows
                // via putusAbsen, which leaves a trail).
                $p = [
                    'tipe' => 'USER', 'id' => self::s($u['id']), 'nama' => self::s($u['name']),
                    'arah' => $ambil('arah', ''),
                    'lat' => $ambil('lat', 0), 'lng' => $ambil('lng', 0),
                    'akurasi' => $ambil('akurasi', 0),
                    'descriptor' => $ambil('descriptor', null),
                    'foto' => $ambil('foto', ''),
                    'alasan' => $ambil('alasan', ''),
                ];

                return Envelope::okData($svc->recordPunch($p));

            case 'daftarWajah':
                $u = $this->masuk($req);
                $tipe = strtoupper(self::s($ambil('tipe', 'USER')));
                $id = self::s($ambil('id', ''));
                // Crew may enrol their OWN face (else HR holds every phone
                // one by one and the module never finishes rolling out).
                // Enrolling ANYONE ELSE is HR-only — or someone overwrites
                // their mate's face with their own and punches as them.
                $sendiri = ($tipe === 'USER' && $id === self::s($u['id']));
                if (! $sendiri && ! $svc->isHr($u)) {
                    return Envelope::error('Hanya HR yang boleh mendaftarkan wajah orang lain.');
                }
                $svc->saveFace($tipe, $id, $ambil('nama', ''), $ambil('descriptor', null),
                    $ambil('foto', ''), self::s($u['name']));

                return Envelope::okData(['tersimpan' => true]);

            case 'hapusWajah':
                $this->hr($req);

                return Envelope::okData(['hapus' => $svc->deleteFace($ambil('tipe', 'USER'), $ambil('id', ''))]);

            case 'wajahDaftar':
                $this->hr($req);

                return Envelope::okData($svc->listFaces());

            case 'antrean':
                $this->hr($req);

                return Envelope::okData($svc->queue());

            case 'putusAbsen':
                $u = $this->hr($req);
                $ok = $svc->decidePunch($ambil('id', ''), $ambil('status', ''), $ambil('nota', ''), self::s($u['name']));

                return Envelope::okData(['berubah' => $ok ? 1 : 0]);

            case 'rekap':
                $u = $this->masuk($req);
                $uid = self::s($this->param($req, 'user', ''));
                $tipe = self::s($this->param($req, 'tipe', ''));
                // Ordinary crew only ever see their own recap: without this,
                // one edited URL parameter reads the whole company's hours.
                if (! $svc->isHr($u)) {
                    $uid = self::s($u['id']);
                    $tipe = 'USER';
                }

                return Envelope::okData($svc->recap(
                    $this->param($req, 'dari', ''), $this->param($req, 'sampai', ''), $uid, $tipe));

            case 'simpanLokasi':
                $u = $this->hr($req);

                return Envelope::okData(['id' => $svc->saveLocation($ambil('row', []), self::s($u['name']))]);

            case 'hapusLokasi':
                $this->hr($req);

                return Envelope::okData(['hapus' => $svc->deleteLocation($ambil('id', ''))]);

            case 'simpanSetting':
                $u = $this->hr($req);

                return Envelope::okData($svc->saveSetting($ambil('data', []), self::s($u['name'])));

            case 'ping':
                return Envelope::okData($svc->ping());

            case 'stats':
                return Envelope::okData($svc->stats());

            default:
                return Envelope::error('action tidak dikenal: '.$req->action);
        }
    }
}
