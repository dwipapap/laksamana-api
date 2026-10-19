<?php

namespace App\Modules\Konten\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Konten\Services\CampaignAnalytics;
use App\Modules\Konten\Services\RecordConflict;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/konten/analytics — the Campaign analytics panel (Insight, Kalender,
 * Riset, Bank Konten, Pengaturan), see docs/api/konten.md ("Analytics").
 *
 * Gate: module konten|campaign (the Beranda kartu holds both keys); settings
 * writes need a campaign module admin, like legacy campaignSetting.
 * Concurrency: insight/jadwal/bank rows carry version = diubah_at
 * (If-Match header or `version` in the body); missing => 428, stale => 409
 * with error.details.current, identical payload => no-op success.
 */
class CampaignController
{
    public function __construct(
        private readonly CampaignAnalytics $analytics,
        private readonly OfficeAccess $access,
    ) {}

    private function actor(Request $r): string
    {
        return (string) ($this->access->userById((string) $r->user()->getKey())['name'] ?? $r->user()->getKey());
    }

    private function admin(Request $r): bool
    {
        $id = (string) $r->user()->getKey();

        return $this->access->isModuleAdmin($id, 'campaign') || $this->access->isSuperadmin($id);
    }

    private function baseVersion(Request $r): ?int
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            $h = trim($h, ' "W/');
            if ($h !== '' && is_numeric($h)) {
                return (int) $h;
            }
        }
        $v = $r->input('version');

        return $v === null || $v === '' ? null : (int) $v;
    }

    private static function conflict(RecordConflict $e): JsonResponse
    {
        return ApiResponse::error('version_conflict', 'The record was changed by someone else. Reload it and apply your change again.', 409,
            ['current' => $e->current]);
    }

    private function safe(callable $fn): JsonResponse
    {
        try {
            $out = $fn();

            return $out instanceof JsonResponse ? $out : ApiResponse::ok($out);
        } catch (RecordConflict $e) {
            return self::conflict($e);
        } catch (RuntimeException $e) {
            [$code, $message] = self::splitError($e->getMessage());
            $status = $code === 'not_found' ? 404 : 422;

            return ApiResponse::error($code, $message, $status);
        }
    }

    private static function splitError(string $message): array
    {
        if (str_starts_with($message, 'not_found:')) {
            return ['not_found', substr($message, strlen('not_found:'))];
        }

        return ['invalid_request', $message];
    }

    private static function withVersion(array $row, int $version): JsonResponse
    {
        return ApiResponse::ok($row, ['version' => $version], 200, ['ETag' => '"'.$version.'"']);
    }

    // ─────────────────────────── reads ──

    public function state(Request $r): JsonResponse
    {
        return ApiResponse::ok($this->analytics->state($this->admin($r)));
    }

    public function accounts(): JsonResponse
    {
        return ApiResponse::ok($this->analytics->accounts());
    }

    public function top(Request $r): JsonResponse
    {
        $v = $r->validate([
            'platform' => 'nullable|string|max:20',
            'urut' => 'nullable|in:views,er',
            'dari' => 'nullable|date_format:Y-m-d',
            'sampai' => 'nullable|date_format:Y-m-d',
            'hal' => 'nullable|integer|min:1',
            'per' => 'nullable|integer|min:1|max:100',
        ]);

        return ApiResponse::ok($this->analytics->top(
            $v['platform'] ?? '', $v['urut'] ?? 'views',
            $v['dari'] ?? null, $v['sampai'] ?? null,
            (int) ($v['hal'] ?? 1), (int) ($v['per'] ?? 10),
        ));
    }

    public function bank(Request $r): JsonResponse
    {
        $v = $r->validate([
            'status' => 'nullable|in:IDE,TERJADWAL,ARSIP',
            'platform' => 'nullable|string|max:20',
        ]);

        return ApiResponse::ok($this->analytics->listBank($v['status'] ?? '', $v['platform'] ?? ''));
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::ok($this->analytics->state(false)['setting']);
    }

    private function requireVersion(Request $r, ?string $id, mixed $bodyId): ?JsonResponse
    {
        if (($id ?? '') === '' && trim((string) ($bodyId ?? '')) === '') {
            return null;
        }
        if ($this->baseVersion($r) === null) {
            return ApiResponse::error('version_required', 'Send the version you last read (If-Match header or "version").', 428);
        }

        return null;
    }

    // ─────────────────────────── insight ──

    public function saveInsight(Request $r, ?string $id = null): JsonResponse
    {
        $v = $r->validate([
            'id' => 'nullable|string|max:40',
            'platform' => 'nullable|string|max:20',
            'kategori' => 'nullable|string|max:60',
            'temuan' => 'nullable|string',
            'url' => 'nullable|string|max:600',
            'tglTayang' => 'nullable|string|max:10',
            'metrik' => 'nullable|string|max:255',
            'aksi' => 'nullable|string',
            'sumber' => 'nullable|in:manual,tiktok,instagram,konten,ai',
        ]);
        if ($err = $this->requireVersion($r, $id, $v['id'] ?? null)) {
            return $err;
        }

        return $this->safe(function () use ($r, $v, $id) {
            if ($id !== null && $id !== '') {
                $v['id'] = $id;
            }
            $out = $this->analytics->saveInsight($v, $this->actor($r), $this->baseVersion($r));
            $row = $this->analytics->findInsight($out['id']);

            return self::withVersion((array) $row, CampaignAnalytics::versionOfRow($row));
        });
    }

    public function archiveInsight(Request $r, string $id): JsonResponse
    {
        $v = $r->validate(['arsip' => 'required|boolean']);

        return $this->safe(fn () => $this->analytics->archiveInsight($id, (bool) $v['arsip'], $this->actor($r)));
    }

    public function suggestInsights(): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->suggestInsights());
    }

    // ─────────────────────────── jadwal ──

    public function saveSchedule(Request $r, ?string $id = null): JsonResponse
    {
        $v = $r->validate([
            'id' => 'nullable|string|max:40',
            'platform' => 'nullable|string|max:20',
            'judul' => 'nullable|string',
            'caption' => 'nullable|string',
            'tanggal' => 'nullable|string|max:10',
            'jam' => 'nullable|string|max:5',
            'status' => 'nullable|string|max:20',
            'insightId' => 'nullable|string|max:40',
            'pic' => 'nullable|string|max:120',
            'tautan' => 'nullable|string|max:600',
            'catatan' => 'nullable|string',
            'bankId' => 'nullable|string|max:40',
        ]);
        if ($err = $this->requireVersion($r, $id, $v['id'] ?? null)) {
            return $err;
        }

        return $this->safe(function () use ($r, $v, $id) {
            if ($id !== null && $id !== '') {
                $v['id'] = $id;
            }
            $out = $this->analytics->saveSchedule($v, $this->actor($r), $this->baseVersion($r));
            $row = $this->analytics->findSchedule($out['id']);

            return self::withVersion((array) $row, CampaignAnalytics::versionOfRow($row));
        });
    }

    // ─────────────────────────── riset ──

    public function runResearch(Request $r): JsonResponse
    {
        $v = $r->validate([
            'topik' => 'nullable|string',
            'platform' => 'nullable|string|max:40',
            'catatan' => 'nullable|string',
        ]);

        return $this->safe(fn () => $this->analytics->runResearch($v, $this->actor($r)));
    }

    // ─────────────────────────── bank ──

    public function saveBank(Request $r, ?string $id = null): JsonResponse
    {
        $v = $r->validate([
            'id' => 'nullable|string|max:40',
            'judul' => 'nullable|string',
            'platform' => 'nullable|string|max:40',
            'format' => 'nullable|string|max:80',
            'pilar' => 'nullable|string|max:80',
            'hook' => 'nullable|string',
            'caption' => 'nullable|string',
            'tanggal' => 'nullable|string|max:10',
            'jam' => 'nullable|string|max:5',
            'alasan' => 'nullable|string',
            'sumber' => 'nullable|in:manual,riset',
            'batch' => 'nullable|string|max:40',
        ]);
        if ($err = $this->requireVersion($r, $id, $v['id'] ?? null)) {
            return $err;
        }

        return $this->safe(function () use ($r, $v, $id) {
            if ($id !== null && $id !== '') {
                $v['id'] = $id;
            }
            $out = $this->analytics->saveBank($v, $this->actor($r), $this->baseVersion($r));
            $row = $this->analytics->findBank($out['id']);

            return self::withVersion((array) $row, CampaignAnalytics::versionOfRow($row));
        });
    }

    public function archiveBank(Request $r, string $id): JsonResponse
    {
        $v = $r->validate(['arsip' => 'required|boolean']);

        return $this->safe(fn () => $this->analytics->archiveBank($id, (bool) $v['arsip'], $this->actor($r)));
    }

    public function deleteBank(Request $r, string $id): JsonResponse
    {
        return $this->safe(function () use ($r, $id) {
            $v = $this->baseVersion($r);
            $row = $this->analytics->findBank($id);
            if ($row && $v !== null && CampaignAnalytics::versionOfRow($row) !== $v) {
                throw new RecordConflict('conflict', (array) $row);
            }

            return $this->analytics->deleteBank($id, $this->actor($r));
        });
    }

    public function generateBank(Request $r): JsonResponse
    {
        $v = $r->validate([
            'dari' => 'nullable|string|max:10',
            'sampai' => 'nullable|string|max:10',
            'platform' => 'nullable|string|max:40',
            'jumlah' => 'nullable|integer|min:1|max:50',
            'fokus' => 'nullable|string',
        ]);

        return $this->safe(fn () => $this->analytics->generateBank($v, $this->actor($r)));
    }

    // ─────────────────────────── OAuth ──

    public function tiktokStart(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->tiktokStart($this->actor($r)));
    }

    public function tiktokSync(): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->tiktokSync());
    }

    public function tiktokDisconnect(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->tiktokDisconnect($this->actor($r)));
    }

    public function igStart(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->igStart($this->actor($r)));
    }

    public function igSync(): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->igSync());
    }

    public function igDisconnect(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->igDisconnect($this->actor($r)));
    }

    private static function vueBase(): string
    {
        $base = trim((string) env('OFFICE_VUE_URL', ''));

        return $base !== '' ? rtrim($base, '/') : (string) config('app.url');
    }

    /** Public: the state token is the guard (single-use, 15 minutes), like legacy. */
    public function tiktokCallback(Request $r): RedirectResponse
    {
        try {
            $this->analytics->tiktokCallback((string) $r->query('code', ''), (string) $r->query('state', ''));

            return redirect()->to(self::vueBase().'/konten/insight?tiktok=ok#insight');
        } catch (RuntimeException $e) {
            return redirect()->to(self::vueBase().'/konten/insight?tiktok=gagal&pesan='.urlencode($e->getMessage()).'#insight');
        }
    }

    /** Public: the state token is the guard (single-use, 15 minutes), like legacy. */
    public function igCallback(Request $r): RedirectResponse
    {
        try {
            $this->analytics->igCallback((string) $r->query('code', ''), (string) $r->query('state', ''));

            return redirect()->to(self::vueBase().'/konten/insight?instagram=ok#insight');
        } catch (RuntimeException $e) {
            return redirect()->to(self::vueBase().'/konten/insight?instagram=gagal&pesan='.urlencode($e->getMessage()).'#insight');
        }
    }

    // ─────────────────────────── Google Review ──

    public function googleState(): JsonResponse
    {
        return ApiResponse::ok($this->analytics->googleRead());
    }

    public function googleSync(): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->googleSync());
    }

    public function googleStart(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->googleStart($this->actor($r)));
    }

    public function googleReply(Request $r): JsonResponse
    {
        $v = $r->validate([
            'id' => 'required|string|max:40',
            'teks' => 'nullable|string',
        ]);

        return $this->safe(fn () => $this->analytics->googleReply($v, $this->actor($r)));
    }

    public function googleDeleteReply(Request $r): JsonResponse
    {
        $v = $r->validate(['id' => 'required|string|max:40']);

        return $this->safe(fn () => $this->analytics->googleDeleteReply($v));
    }

    public function googleFlag(Request $r): JsonResponse
    {
        $v = $r->validate([
            'id' => 'required|string|max:40',
            'ditangani' => 'nullable|boolean',
            'catatan' => 'nullable|string',
        ]);

        return $this->safe(fn () => $this->analytics->googleFlag($v, $this->actor($r)));
    }

    public function googleAi(Request $r): JsonResponse
    {
        $v = $r->validate([
            'hari' => 'nullable|integer|min:1|max:1000',
            'tempat' => 'nullable|string|max:190',
        ]);

        return $this->safe(fn () => $this->analytics->googleAi($v, $this->actor($r)));
    }

    public function googleAiDraft(Request $r): JsonResponse
    {
        $v = $r->validate([
            'id' => 'required|string|max:40',
            'arahan' => 'nullable|string',
        ]);

        return $this->safe(fn () => $this->analytics->googleAiDraft($v));
    }

    /** Campaign admin only (route gate): like legacy campaignGoogleCari. */
    public function googleSearch(Request $r): JsonResponse
    {
        $v = $r->validate(['q' => 'nullable|string']);

        return $this->safe(fn () => $this->analytics->googleSearch($v));
    }

    /** Campaign admin only (route gate): tokens cleared, reviews stay. */
    public function googleDisconnect(Request $r): JsonResponse
    {
        return $this->safe(fn () => $this->analytics->googleDisconnect($this->actor($r)));
    }

    /** Public: the state token is the guard (single-use, 15 minutes), like legacy. */
    public function googleCallback(Request $r): RedirectResponse
    {
        try {
            $n = $this->analytics->googleCallback((string) $r->query('code', ''), (string) $r->query('state', ''));
            $pesan = 'Google Business Profile terhubung, '.$n['jumlah'].' review ditarik.'
                .($n['galat'] ? ' Sebagian gagal: '.implode(' | ', $n['galat']) : '');

            return redirect()->to(self::vueBase().'/konten/greview?google=ok&pesan='.urlencode($pesan).'#greview');
        } catch (RuntimeException $e) {
            return redirect()->to(self::vueBase().'/konten/greview?google=gagal&pesan='.urlencode($e->getMessage()).'#greview');
        }
    }

    // ─────────────────────────── settings (campaign admin) ──

    public function saveSettings(Request $r): JsonResponse
    {
        $v = $r->validate([
            'aiKeyMeta' => 'nullable|string',
            'aiModelMeta' => 'nullable|string|max:60',
            'tiktokKey' => 'nullable|string',
            'tiktokSecret' => 'nullable|string',
            'igId' => 'nullable|string',
            'igSecret' => 'nullable|string',
            'googleClientId' => 'nullable|string',
            'googleClientSecret' => 'nullable|string',
            'placesKey' => 'nullable|string',
            'placesIds' => 'nullable|string',
            'kategori' => 'nullable',
            'profil' => 'nullable|string',
        ]);

        return $this->safe(fn () => $this->analytics->saveSettings($v));
    }
}
