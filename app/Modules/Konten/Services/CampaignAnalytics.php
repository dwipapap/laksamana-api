<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Campaign analytics (the Konten Analytics panel, formerly the standalone
 * Campaign module) for /api/v1/konten/analytics — a v1 port of
 * laksamana-office/konten-mysql/lib_campaign.php (main 5af1277).
 *
 * The old analytics frontend and this API share the SAME physical cg_*
 * tables in the konten database, so a legacy tab and a Vue tab never see
 * different data. Writes run under the same konten_save lock as the rest
 * of the Konten module.
 *
 * Deliberate differences from legacy (all byte-compatible otherwise,
 * including the Indonesian validation messages):
 *   - updates carry a version (diubah_at, falling back to dibuat_at):
 *     missing => 428 version_required, stale => 409 version_conflict with
 *     the current row, identical payload => no-op success. Legacy threw
 *     "…tidak ditemukan." when an edit changed nothing (rowCount 0).
 *   - writes append to the module audit trail (… (API)), like the other
 *     v1 Konten endpoints; legacy campaign writes did not log.
 *   - OAuth callbacks answer on /api/v1 (public, single-use state guarded)
 *     and redirect to the Vue app (OFFICE_VUE_URL) instead of /konten/analytics/.
 */
class CampaignAnalytics
{
    public const TIKTOK_SCOPE = 'user.info.basic,user.info.profile,user.info.stats,video.list';

    public const IG_SCOPE = 'instagram_business_basic,instagram_business_manage_insights';

    public const IG_GRAPH = 'https://graph.instagram.com/v23.0';

    public const GR_SCOPE = 'https://www.googleapis.com/auth/business.manage';

    public const GR_PLACES = 'https://places.googleapis.com/v1';

    public const GR_MAKS_BALAS = 4000;

    public const AI_MODELS = ['muse-spark-1.3-contributor', 'muse-spark-1.3', 'muse-spark-1.2-contributor', 'muse-spark-1.2'];

    public const AI_URL = 'https://api.meta.ai/v1/messages';

    public const KATEGORI_MAKS = 20;

    public const PROFIL_BAWAAN = 'Laksamana Muda: coffee shop, resto, dan venue nongkrong di Pekanbaru, Riau. '
        .'Ramai malam hari dan akhir pekan, punya live music, DJ, nobar, dan event. '
        .'Kanal utama TikTok @laksamanamuda.id dan Instagram.';

    public const KATEGORI_BAWAAN = ['Hook 3 Detik & Retensi', 'Format Konten & Visual', 'Topik & Tren', 'Waktu Tayang',
        'Promo & Ajakan', 'Sentimen & Masukan', 'Kolaborasi & KOL'];

    public const STATUSES = ['DRAF', 'TERJADWAL', 'TAYANG', 'BATAL'];

    public const BANK_STATUSES = ['IDE', 'TERJADWAL', 'ARSIP'];

    private function db(): ConnectionInterface
    {
        return Modules::db('konten');
    }

    public const INSIGHT_MAKS = 1000;

    public const JADWAL_MAKS = 1500;

    public const BANK_MAKS = 1000;

    /** Fresh connection after a 1–2 minute AI call (else "MySQL server has gone away"). */
    private function freshDb(): ConnectionInterface
    {
        DB::reconnect(Modules::connectionName('konten'));

        return $this->db();
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public static function wibToday(): string
    {
        return gmdate('Y-m-d', time() + 7 * 3600);
    }

    private static function uid(string $prefix): string
    {
        return $prefix.base_convert((string) self::nowMs(), 10, 36).bin2hex(random_bytes(3));
    }

    private static function cut(string $s, int $n): string
    {
        $s = trim($s);

        return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
    }

    private static function validDate(mixed $t): ?string
    {
        $t = trim((string) $t);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $t : null;
    }

    private static function validUrl(mixed $s): string|bool|null
    {
        $s = trim((string) $s);
        if ($s === '') {
            return '';
        }

        return preg_match('#^https://[^\s]+$#i', $s) ? $s : null;
    }

    private static function validTime(mixed $j): string
    {
        $j = trim((string) $j);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $j) ? $j : '';
    }

    // ─────────────────────────── tables ──

    /** Bump when createTables() changes: the cross-request flag is keyed by it. */
    private const TABLES_VERSION = '2026-10-20';

    /** Databases whose cg_* tables were ensured in this process. */
    private static array $tablesReady = [];

    /**
     * Seconds a sync may spend on remote calls before it stops cleanly (#304):
     * cPanel's PHP/proxy limits otherwise cut a long sync with a 500 halfway.
     * Every loop it guards resumes on the next sync (cursors / oldest first).
     */
    private const SYNC_BUDGET = 40;

    private float $deadline = 0.0;

    private function startBudget(): void
    {
        $this->deadline = microtime(true) + self::SYNC_BUDGET;
    }

    private function overBudget(): bool
    {
        return $this->deadline > 0 && microtime(true) > $this->deadline;
    }

    /**
     * The runtime DDL (no migrations for module tables: Team B owns those).
     * It used to run all ten CREATE TABLE IF NOT EXISTS on EVERY call — 36
     * call sites, state() included — i.e. ten round-trips plus a metadata
     * lock per screen read (#304). Now once per process per database, and
     * once per TABLES_VERSION across requests (an app-cache flag).
     */
    public function ensureTables(): void
    {
        $key = 'konten:cg_tables:'.$this->db()->getDatabaseName().':'.self::TABLES_VERSION;
        if (isset(self::$tablesReady[$key])) {
            return;
        }
        if (! Cache::get($key)) {
            $this->createTables();
            Cache::forever($key, true);
        }
        self::$tablesReady[$key] = true;
    }

    /** CREATE TABLE IF NOT EXISTS for the cg_* tables (same shape as cg_pastikan). */
    private function createTables(): void
    {
        $db = $this->db();
        $db->statement("CREATE TABLE IF NOT EXISTS cg_insight (id VARCHAR(40) PRIMARY KEY, platform VARCHAR(20), kategori VARCHAR(60),
            temuan TEXT, url VARCHAR(600), tgl_tayang DATE NULL, metrik VARCHAR(255), aksi TEXT, sumber VARCHAR(20) DEFAULT 'manual',
            arsip TINYINT DEFAULT 0, dibuat_oleh VARCHAR(120), dibuat_at BIGINT, diubah_oleh VARCHAR(120), diubah_at BIGINT,
            KEY tgl_tayang (tgl_tayang))");
        $db->statement("CREATE TABLE IF NOT EXISTS cg_jadwal (id VARCHAR(40) PRIMARY KEY, platform VARCHAR(20), judul VARCHAR(200),
            caption TEXT, tanggal DATE NULL, jam VARCHAR(5), status VARCHAR(20) DEFAULT 'DRAF', insight_id VARCHAR(40),
            pic VARCHAR(120), tautan VARCHAR(600), catatan TEXT,
            dibuat_oleh VARCHAR(120), dibuat_at BIGINT, diubah_oleh VARCHAR(120), diubah_at BIGINT,
            KEY tanggal (tanggal))");
        $db->statement("CREATE TABLE IF NOT EXISTS cg_riset (id VARCHAR(40) PRIMARY KEY, jenis VARCHAR(20) DEFAULT 'tren',
            topik VARCHAR(255), platform VARCHAR(40), catatan TEXT, hasil MEDIUMTEXT, model VARCHAR(60), oleh VARCHAR(120), at BIGINT)");
        $db->statement('CREATE TABLE IF NOT EXISTS cg_post (platform VARCHAR(20), post_id VARCHAR(80), judul TEXT, url VARCHAR(600),
            cover VARCHAR(900), tayang_at BIGINT, views BIGINT, likes BIGINT, comments BIGINT, shares BIGINT, durasi INT,
            sync_at BIGINT, hilang TINYINT DEFAULT 0, reach BIGINT NULL, saves BIGINT NULL, insight_at BIGINT,
            PRIMARY KEY (platform, post_id))');
        $db->statement('CREATE TABLE IF NOT EXISTS cg_akun (platform VARCHAR(20) PRIMARY KEY, nama VARCHAR(160), avatar VARCHAR(900),
            follower BIGINT, open_id VARCHAR(120), access_token TEXT, refresh_token TEXT, token_exp BIGINT, refresh_exp BIGINT,
            scope VARCHAR(255), sync_at BIGINT, sync_galat VARCHAR(400), oleh VARCHAR(120), at BIGINT,
            bio TEXT, profil_url VARCHAR(400), terverifikasi TINYINT DEFAULT 0,
            following BIGINT DEFAULT 0, likes_total BIGINT DEFAULT 0, jml_video BIGINT DEFAULT 0, stats_ada TINYINT DEFAULT 0)');
        $db->statement("CREATE TABLE IF NOT EXISTS cg_bank (id VARCHAR(40) PRIMARY KEY, judul VARCHAR(200), platform VARCHAR(20),
            format VARCHAR(80), pilar VARCHAR(80), hook TEXT, caption TEXT, tanggal DATE NULL, jam VARCHAR(5), alasan TEXT,
            sumber VARCHAR(20) DEFAULT 'manual', batch VARCHAR(40), status VARCHAR(20) DEFAULT 'IDE', jadwal_id VARCHAR(40),
            dibuat_oleh VARCHAR(120), dibuat_at BIGINT, diubah_oleh VARCHAR(120), diubah_at BIGINT,
            KEY tanggal (tanggal))");
        $db->statement('CREATE TABLE IF NOT EXISTS cg_setting (k VARCHAR(60) PRIMARY KEY, v TEXT)');
        /* Google Review (lib_greview.php): places the business watches. id is
           the place_id (Places) or "accounts/A/locations/L" (Business
           Profile); place_id is kept apart so two sources for the same place
           are recognised. */
        $db->statement("CREATE TABLE IF NOT EXISTS cg_gtempat (id VARCHAR(190) NOT NULL PRIMARY KEY,
            sumber VARCHAR(10) NOT NULL DEFAULT '', place_id VARCHAR(190) NOT NULL DEFAULT '',
            nama VARCHAR(255) NOT NULL DEFAULT '', alamat VARCHAR(500) NOT NULL DEFAULT '',
            rating DECIMAL(3,2) NULL, total INT NULL, url VARCHAR(600) NOT NULL DEFAULT '',
            aktif TINYINT(1) NOT NULL DEFAULT 1, sync_at BIGINT NOT NULL DEFAULT 0,
            galat VARCHAR(600) NOT NULL DEFAULT '')");
        /* Daily rating & review-count snapshot — the only way to see the
           rating move, since Google keeps no history. */
        $db->statement('CREATE TABLE IF NOT EXISTS cg_gtempat_harian (tempat_id VARCHAR(190) NOT NULL,
            tgl DATE NOT NULL, rating DECIMAL(3,2) NULL, total INT NULL,
            PRIMARY KEY (tempat_id, tgl))');
        /* id = sha1(review resource name): resource names can run past 190
           chars, and a truncated PK would make two reviews overwrite each
           other. */
        $db->statement('CREATE TABLE IF NOT EXISTS cg_greview (id VARCHAR(40) NOT NULL PRIMARY KEY,
            nama_res VARCHAR(600) NOT NULL DEFAULT \'\', sumber VARCHAR(10) NOT NULL DEFAULT \'\',
            tempat_id VARCHAR(190) NOT NULL DEFAULT \'\', penulis VARCHAR(200) NOT NULL DEFAULT \'\',
            penulis_url VARCHAR(600) NOT NULL DEFAULT \'\', foto VARCHAR(900) NOT NULL DEFAULT \'\',
            rating TINYINT NOT NULL DEFAULT 0, teks TEXT NULL, waktu_ms BIGINT NOT NULL DEFAULT 0,
            url VARCHAR(600) NOT NULL DEFAULT \'\', balasan TEXT NULL, balasan_at BIGINT NOT NULL DEFAULT 0,
            balasan_oleh VARCHAR(120) NOT NULL DEFAULT \'\', ditangani TINYINT(1) NOT NULL DEFAULT 0,
            tangani_catatan TEXT NULL, tangani_oleh VARCHAR(120) NOT NULL DEFAULT \'\',
            tangani_at BIGINT NOT NULL DEFAULT 0, pertama_at BIGINT NOT NULL DEFAULT 0,
            sync_at BIGINT NOT NULL DEFAULT 0,
            KEY idx_gr_tempat (tempat_id), KEY idx_gr_waktu (waktu_ms))');
    }

    // ─────────────────────────── settings ──

    private function setting(string $k, string $default = ''): string
    {
        $row = $this->db()->selectOne('SELECT v FROM cg_setting WHERE k = ?', [$k]);
        $v = $row ? (string) $row->v : '';

        return ($v === '') ? $default : $v;
    }

    private function saveSetting(string $k, string $v): void
    {
        $this->db()->update('INSERT INTO cg_setting (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }

    private function secret(string $env, string $key): string
    {
        $v = trim((string) env($env, ''));
        if ($v !== '') {
            return $v;
        }

        return $this->setting($key);
    }

    public function secretFromConfig(string $env): bool
    {
        return trim((string) env($env, '')) !== '';
    }

    public function aiKey(): string
    {
        return $this->secret('META_MODEL_API_KEY', 'ai_key_meta');
    }

    public function aiModel(): string
    {
        $m = $this->setting('ai_model_meta', self::AI_MODELS[0]);

        return in_array($m, self::AI_MODELS, true) ? $m : self::AI_MODELS[0];
    }

    public function aiSummary(): array
    {
        return [[
            'id' => 'meta',
            'label' => 'Meta (Muse Spark)',
            'model' => self::AI_MODELS,
            'modelPakai' => $this->aiModel(),
            'siap' => $this->aiKey() !== '',
            'dariConfig' => $this->secretFromConfig('META_MODEL_API_KEY'),
        ]];
    }

    /** @return string[] */
    public function kategori(): array
    {
        $raw = json_decode($this->setting('kategori', ''), true);
        $out = [];
        $seen = [];
        if (is_array($raw)) {
            foreach ($raw as $k) {
                if (! is_string($k) && ! is_numeric($k)) {
                    continue;
                }
                $k = self::cut(trim(preg_replace('/\s+/', ' ', (string) $k)), 60);
                if ($k === '') {
                    continue;
                }
                $low = mb_strtolower($k);
                if (isset($seen[$low])) {
                    continue;
                }
                $seen[$low] = true;
                $out[] = $k;
                if (count($out) >= self::KATEGORI_MAKS) {
                    break;
                }
            }
        }

        return $out !== [] ? $out : self::KATEGORI_BAWAAN;
    }

    // ─────────────────────────── reads ──

    /** Only the setting summary — GET /analytics/settings must not pull the 1000+1500+1000 lists. */
    public function settingSummary(): array
    {
        $this->ensureTables();

        return [
            'aiSiap' => $this->aiKey() !== '',
            'aiModel' => $this->aiModel(),
            'ai' => $this->aiSummary(),
            'kategori' => $this->kategori(),
            'profil' => $this->setting('profil', self::PROFIL_BAWAAN),
            'tiktokSiap' => $this->secret('TIKTOK_CLIENT_KEY', 'tiktok_key') !== '' && $this->secret('TIKTOK_CLIENT_SECRET', 'tiktok_secret') !== '',
            'tiktokDariConfig' => $this->secretFromConfig('TIKTOK_CLIENT_KEY'),
            'redirectUri' => $this->tiktokRedirectUri(),
            'tiktokScope' => self::TIKTOK_SCOPE,
            'igSiap' => $this->secret('INSTAGRAM_APP_ID', 'ig_id') !== '' && $this->secret('INSTAGRAM_APP_SECRET', 'ig_secret') !== '',
            'igDariConfig' => $this->secretFromConfig('INSTAGRAM_APP_ID'),
            'igRedirectUri' => $this->igRedirectUri(),
        ] + $this->googleSettingSummary();
    }

    /**
     * Full read: insight, jadwal, riset (30, decoded), bank, postInfo, akun (no tokens), setting summary, `boleh.admin`.
     * Optional per-section caps keep the documented maxima by default, so a
     * bare call answers exactly like before; a panel that only shows the
     * head of a section asks for less instead of the whole payload.
     *
     * @param  array{insightLimit?:int,jadwalLimit?:int,bankLimit?:int}  $opt
     */
    public function state(bool $isAdmin, array $opt = []): array
    {
        $this->ensureTables();
        $db = $this->db();
        $insightLim = min(max((int) ($opt['insightLimit'] ?? self::INSIGHT_MAKS), 1), self::INSIGHT_MAKS);
        $jadwalLim = min(max((int) ($opt['jadwalLimit'] ?? self::JADWAL_MAKS), 1), self::JADWAL_MAKS);
        $bankLim = min(max((int) ($opt['bankLimit'] ?? self::BANK_MAKS), 1), self::BANK_MAKS);
        $insight = $db->select('SELECT * FROM cg_insight ORDER BY (tgl_tayang IS NULL), tgl_tayang DESC, dibuat_at DESC LIMIT '.$insightLim);
        $jadwal = $db->select('SELECT * FROM cg_jadwal ORDER BY tanggal DESC, jam DESC LIMIT '.$jadwalLim);
        $riset = $db->select('SELECT id, jenis, topik, platform, catatan, hasil, model, oleh, at FROM cg_riset ORDER BY at DESC LIMIT 30');
        $bank = $db->select('SELECT * FROM cg_bank ORDER BY (tanggal IS NULL), tanggal ASC, dibuat_at DESC LIMIT '.$bankLim);
        foreach ($riset as $r) {
            $h = json_decode((string) $r->hasil, true);
            $r->hasil = is_array($h) ? $h : null;
        }
        $akun = $db->select('SELECT platform, nama, avatar, follower, bio, profil_url, terverifikasi, following, likes_total, jml_video, stats_ada, scope, token_exp, refresh_exp, sync_at, sync_galat, oleh, at FROM cg_akun');
        $now = self::nowMs();
        foreach ($akun as $a) {
            $a->terhubung = (int) $a->refresh_exp > $now || (int) $a->token_exp > $now;
        }

        return [
            'insight' => $insight,
            'jadwal' => $jadwal,
            'riset' => $riset,
            'bank' => $bank,
            'postInfo' => [
                'total' => (int) $db->selectOne("SELECT COUNT(*) c FROM cg_post WHERE platform='tiktok' AND hilang=0")->c,
                'riwayatSelesai' => $this->setting('tiktok_bf_selesai') === '1',
                'igTotal' => (int) $db->selectOne("SELECT COUNT(*) c FROM cg_post WHERE platform='instagram' AND hilang=0")->c,
                'igRiwayatSelesai' => $this->setting('ig_bf_selesai') === '1',
            ],
            'akun' => $akun,
            'setting' => $this->settingSummary(),
            'boleh' => ['admin' => $isAdmin],
        ];
    }

    public function accounts(): array
    {
        $this->ensureTables();
        $akun = $this->db()->select('SELECT platform, nama, avatar, follower, bio, profil_url, terverifikasi, following, likes_total, jml_video, stats_ada, scope, token_exp, refresh_exp, sync_at, sync_galat, oleh, at FROM cg_akun');
        $now = self::nowMs();
        foreach ($akun as $a) {
            $a->terhubung = (int) $a->refresh_exp > $now || (int) $a->token_exp > $now;
        }

        return $akun;
    }

    public static function platNorm(mixed $p): string
    {
        $k = mb_strtolower((string) $p);
        if (str_contains($k, 'tiktok')) {
            return 'TikTok';
        }
        if (str_contains($k, 'insta') || $k === 'ig' || str_contains($k, 'reels')) {
            return 'Instagram';
        }
        if (str_contains($k, 'you') || $k === 'yt') {
            return 'YouTube';
        }

        return $p !== '' ? (string) $p : 'Lainnya';
    }

    /** @return array{reach:array,impr:array,views:array,likes:array,comments:array,shares:array,saves:array,cta:array,follow:array} */
    public static function rinci(?array $api, ?array $manual): array
    {
        $out = [];
        foreach (['reach', 'impr', 'views', 'likes', 'comments', 'shares', 'saves', 'cta', 'follow'] as $k) {
            if (is_array($api) && array_key_exists($k, $api)) {
                $out[$k] = ['v' => (float) $api[$k], 's' => 'api'];

                continue;
            }
            if (is_array($manual) && isset($manual[$k]) && $manual[$k] !== '' && is_numeric($manual[$k])) {
                $out[$k] = ['v' => (float) $manual[$k], 's' => 'manual'];

                continue;
            }
            $out[$k] = ['v' => null, 's' => ''];
        }

        return $out;
    }

    /** Manual figures typed in Konten Input Performa (read, never copied). */
    private function kontenPerf(): array
    {
        $out = [];
        try {
            $table = KontenSchema::collections()['content']['table'] ?? 'content';
            $rows = $this->db()->select("SELECT data FROM `{$table}`");
        } catch (\Throwable) {
            return $out;
        }
        foreach ($rows as $row) {
            $c = json_decode((string) $row->data, true);
            if (! is_array($c) || empty($c['perf']) || ! is_array($c['perf'])) {
                continue;
            }
            $url = (isset($c['publishRecord']['url']) && is_string($c['publishRecord']['url'])) ? $c['publishRecord']['url'] : '';
            $tgl = isset($c['publishDate']) ? (string) $c['publishDate'] : '';
            foreach ($c['perf'] as $plat => $m) {
                if (! is_array($m)) {
                    continue;
                }
                $views = (float) ($m['views'] ?? 0);
                if ($views <= 0) {
                    $views = (float) ($m['reach'] ?? 0);
                }
                $out[] = [
                    'platform' => (string) $plat,
                    'id' => (string) ($c['id'] ?? ''),
                    'judul' => (string) ($c['title'] ?? ''),
                    'pillar' => (string) ($c['pillar'] ?? ''),
                    'url' => $url, 'tanggal' => $tgl, 'views' => $views,
                    'likes' => (float) ($m['likes'] ?? 0), 'comments' => (float) ($m['comments'] ?? 0),
                    'shares' => (float) ($m['shares'] ?? 0), 'saves' => (float) ($m['saves'] ?? 0), 'm' => $m,
                ];
            }
        }

        return $out;
    }

    /** One page of the most-running posts: API pulls + Input Performa, deduped by url. */
    public function top(string $platform, string $order, ?string $dari, ?string $sampai, int $page, int $per): array
    {
        $this->ensureTables();
        $order = $order === 'er' ? 'er' : 'views';
        $per = max(5, min(50, $per));
        $page = max(1, $page);

        $semua = [];
        $seenUrl = [];
        $kp = $this->kontenPerf();
        $kpUrl = [];
        foreach ($kp as $k) {
            if ($k['url'] !== '') {
                $kpUrl[self::platNorm($k['platform']).'|'.$k['url']] = $k;
            }
        }
        foreach ($this->db()->select('SELECT platform, judul, url, tayang_at, views, likes, comments, shares, reach, saves FROM cg_post WHERE hilang=0') as $p) {
            $man = ((string) $p->url !== '' && isset($kpUrl[self::platNorm($p->platform).'|'.(string) $p->url])) ? $kpUrl[self::platNorm($p->platform).'|'.(string) $p->url] : null;
            $api = ['views' => $p->views, 'likes' => $p->likes, 'comments' => $p->comments, 'shares' => $p->shares];
            if ($p->reach !== null) {
                $api['reach'] = $p->reach;
            }
            if ($p->saves !== null) {
                $api['saves'] = $p->saves;
            }
            $semua[] = [
                'rinci' => self::rinci($api, $man ? $man['m'] : null),
                'tercatat' => $man !== null,
                'platform' => self::platNorm($p->platform),
                'judul' => (string) $p->judul !== '' ? (string) $p->judul : '(tanpa keterangan)',
                'url' => (string) $p->url,
                'tanggal' => (int) $p->tayang_at > 0 ? gmdate('Y-m-d', (int) floor((int) $p->tayang_at / 1000) + 7 * 3600) : '',
                'views' => (float) $p->views, 'likes' => (float) $p->likes,
                'comments' => (float) $p->comments, 'shares' => (float) $p->shares,
                'sumber' => (string) $p->platform === 'instagram' ? 'instagram' : 'tiktok',
            ];
            if ((string) $p->url !== '') {
                $seenUrl[(string) $p->url] = true;
            }
        }
        foreach ($kp as $k) {
            if ($k['url'] !== '' && isset($seenUrl[$k['url']])) {
                continue;
            }
            $semua[] = [
                'rinci' => self::rinci(null, $k['m']), 'tercatat' => true,
                'platform' => self::platNorm($k['platform']),
                'judul' => $k['judul'] !== '' ? $k['judul'] : '(tanpa judul)',
                'url' => $k['url'], 'tanggal' => substr($k['tanggal'], 0, 10),
                'views' => $k['views'], 'likes' => $k['likes'], 'comments' => $k['comments'], 'shares' => $k['shares'],
                'sumber' => 'konten',
            ];
        }

        $lolos = [];
        foreach ($semua as $e) {
            if ($platform !== '' && $e['platform'] !== $platform) {
                continue;
            }
            if ($dari !== null || $sampai !== null) {
                if ($e['tanggal'] === '') {
                    continue;
                }
                if ($dari !== null && $e['tanggal'] < $dari) {
                    continue;
                }
                if ($sampai !== null && $e['tanggal'] > $sampai) {
                    continue;
                }
            }
            $e['er'] = $e['views'] > 0 ? ($e['likes'] + $e['comments'] + $e['shares']) / $e['views'] : null;
            $lolos[] = $e;
        }
        usort($lolos, function ($a, $b) use ($order) {
            if ($order === 'er') {
                if ($a['er'] === null && $b['er'] === null) {
                    // fall through to views
                } elseif ($a['er'] === null) {
                    return 1;
                } elseif ($b['er'] === null) {
                    return -1;
                } elseif ($a['er'] != $b['er']) {
                    return $a['er'] < $b['er'] ? 1 : -1;
                }
            }
            if ($a['views'] != $b['views']) {
                return $a['views'] < $b['views'] ? 1 : -1;
            }

            return strcmp($b['tanggal'], $a['tanggal']);
        });
        $total = count($lolos);
        $halMaks = max(1, (int) ceil($total / $per));
        if ($page > $halMaks) {
            $page = $halMaks;
        }

        return ['baris' => array_slice($lolos, ($page - 1) * $per, $per), 'total' => $total,
            'hal' => $page, 'per' => $per, 'halMaks' => $halMaks];
    }

    // ─────────────────────────── insight ──

    private static function insightRow(object $r): array
    {
        return (array) $r;
    }

    public static function versionOfRow(?object $row): int
    {
        if (! $row) {
            return 0;
        }

        return (int) ($row->diubah_at ?? $row->dibuat_at ?? 0);
    }

    public function findInsight(string $id): ?object
    {
        $this->ensureTables();

        return $this->db()->selectOne('SELECT * FROM cg_insight WHERE id = ?', [$id]);
    }

    /** @throws RuntimeException validation (422) */
    public function saveInsight(array $d, string $by, ?int $baseVersion = null): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($d, $by, $baseVersion) {
            $this->ensureTables();
            $id = trim((string) ($d['id'] ?? ''));
            $cur = null;
            if ($id !== '') {
                $cur = $this->findInsight($id);
                if (! $cur) {
                    throw new RuntimeException('not_found:Insight yang disunting tidak ditemukan.');
                }
                if ($baseVersion !== null) {
                    $this->checkVersion($cur, $baseVersion);
                }
                /* PATCH: absent keys keep the stored value, so a partial
                   payload validates as the whole it becomes. */
                foreach (['platform' => 'platform', 'kategori' => 'kategori', 'temuan' => 'temuan', 'url' => 'url',
                    'tglTayang' => 'tgl_tayang', 'metrik' => 'metrik', 'aksi' => 'aksi'] as $k => $col) {
                    if (! array_key_exists($k, $d)) {
                        $d[$k] = $cur->$col;
                    }
                }
            }
            $temuan = self::cut($d['temuan'] ?? '', 2000);
            if ($temuan === '') {
                throw new RuntimeException('Temuan wajib diisi — itulah inti insight-nya.');
            }
            $url = self::validUrl($d['url'] ?? '');
            if ($url === null) {
                throw new RuntimeException('Tautan konten harus diawali https:// .');
            }
            if ($url === '') {
                throw new RuntimeException('Tautan konten wajib diisi, supaya insight bisa ditelusuri ke konten aslinya.');
            }
            $tgl = self::validDate($d['tglTayang'] ?? '');
            if ($tgl === null) {
                throw new RuntimeException('Tanggal konten tayang wajib diisi.');
            }
            $now = self::nowMs();
            $nilai = [
                'platform' => self::cut($d['platform'] ?? '', 20),
                'kategori' => self::cut($d['kategori'] ?? '', 60),
                'temuan' => $temuan,
                'url' => self::cut($url, 600),
                'tgl_tayang' => $tgl,
                'metrik' => self::cut($d['metrik'] ?? '', 255),
                'aksi' => self::cut($d['aksi'] ?? '', 2000),
            ];
            $db = $this->db();
            if ($id !== '') {
                if ($baseVersion !== null && $this->sameInsight($cur, $nilai)) {
                    return ['id' => $id, 'unchanged' => true];
                }
                $db->update('UPDATE cg_insight SET platform=?, kategori=?, temuan=?, url=?, tgl_tayang=?, metrik=?, aksi=?, diubah_oleh=?, diubah_at=? WHERE id=?',
                    [$nilai['platform'], $nilai['kategori'], $nilai['temuan'], $nilai['url'], $nilai['tgl_tayang'], $nilai['metrik'], $nilai['aksi'], $by, $now, $id]);
                $this->log('Insight', $temuan, 'diubah', $by);

                return ['id' => $id];
            }
            $id = self::uid('ins');
            $sumber = in_array($d['sumber'] ?? '', ['manual', 'tiktok', 'instagram', 'konten', 'ai'], true) ? $d['sumber'] : 'manual';
            $db->insert('INSERT INTO cg_insight (id, platform, kategori, temuan, url, tgl_tayang, metrik, aksi, sumber, dibuat_oleh, dibuat_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $nilai['platform'], $nilai['kategori'], $nilai['temuan'], $nilai['url'], $nilai['tgl_tayang'], $nilai['metrik'], $nilai['aksi'], $sumber, $by, $now]);
            $this->log('Insight', $temuan, 'ditambah', $by);

            return ['id' => $id];
        });
    }

    private function sameInsight(object $cur, array $nilai): bool
    {
        foreach ($nilai as $k => $v) {
            if ((string) ($cur->$k ?? '') !== (string) $v) {
                return false;
            }
        }

        return true;
    }

    /** @throws RuntimeException */
    private function checkVersion(object $cur, int $baseVersion): void
    {
        if (self::versionOfRow($cur) !== $baseVersion) {
            throw new RecordConflict('conflict', (array) $cur);
        }
    }

    public function archiveInsight(string $id, bool $arsip, string $by): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($id, $arsip, $by) {
            $this->ensureTables();
            $now = self::nowMs();
            $n = $this->db()->update('UPDATE cg_insight SET arsip=?, diubah_oleh=?, diubah_at=? WHERE id=?', [$arsip ? 1 : 0, $by, $now, $id]);
            if ($n === 0) {
                throw new RuntimeException('not_found:Insight tidak ditemukan.');
            }
            $this->log('Insight', $id, $arsip ? 'diarsipkan' : 'dipulihkan', $by);

            return ['id' => $id];
        });
    }

    private function log(string $label, string $name, string $verb, string $by): void
    {
        app(KontenState::class)->appendLogs($this->db(), [[
            'id' => 'lg_'.strtolower(Str::random(10)),
            'by' => $by,
            'action' => $label.' '.$verb.' (API)',
            'target' => $name !== '' ? mb_substr($name, 0, 200) : '',
            'at' => self::nowMs(),
        ]]);
    }

    // ─────────────────────────── jadwal ──

    public function findSchedule(string $id): ?object
    {
        $this->ensureTables();

        return $this->db()->selectOne('SELECT * FROM cg_jadwal WHERE id = ?', [$id]);
    }

    /** @throws RuntimeException validation (422) */
    public function saveSchedule(array $d, string $by, ?int $baseVersion = null): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($d, $by, $baseVersion) {
            $this->ensureTables();
            $id = trim((string) ($d['id'] ?? ''));
            $cur = null;
            if ($id !== '') {
                $cur = $this->findSchedule($id);
                if (! $cur) {
                    throw new RuntimeException('not_found:Jadwal yang disunting tidak ditemukan.');
                }
                if ($baseVersion !== null) {
                    $this->checkVersion($cur, $baseVersion);
                }
                /* PATCH: absent keys keep the stored value (bankId stays
                   create-only: it marks an idea TERJADWAL on insert). */
                foreach (['platform' => 'platform', 'judul' => 'judul', 'caption' => 'caption', 'tanggal' => 'tanggal',
                    'jam' => 'jam', 'status' => 'status', 'insightId' => 'insight_id', 'pic' => 'pic',
                    'tautan' => 'tautan', 'catatan' => 'catatan'] as $k => $col) {
                    if (! array_key_exists($k, $d)) {
                        $d[$k] = $cur->$col;
                    }
                }
            }
            $judul = self::cut($d['judul'] ?? '', 200);
            if ($judul === '') {
                throw new RuntimeException('Judul konten wajib diisi.');
            }
            $tgl = self::validDate($d['tanggal'] ?? '');
            if ($tgl === null) {
                throw new RuntimeException('Tanggal tayang wajib diisi.');
            }
            $jam = trim((string) ($d['jam'] ?? ''));
            if ($jam !== '' && self::validTime($jam) === '') {
                throw new RuntimeException('Jam tayang harus berbentuk JJ:MM (24 jam).');
            }
            $status = strtoupper(trim((string) ($d['status'] ?? 'DRAF')));
            if (! in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Status tidak dikenal.');
            }
            $tautan = self::validUrl($d['tautan'] ?? '');
            if ($tautan === null) {
                throw new RuntimeException('Tautan hasil tayang harus diawali https:// .');
            }
            if ($status === 'TAYANG' && $tautan === '') {
                throw new RuntimeException('Tandai Tayang butuh tautan postingannya.');
            }
            $now = self::nowMs();
            $nilai = [
                'platform' => self::cut($d['platform'] ?? '', 20),
                'judul' => $judul,
                'caption' => self::cut($d['caption'] ?? '', 5000),
                'tanggal' => $tgl,
                'jam' => $jam,
                'status' => $status,
                'insight_id' => self::cut($d['insightId'] ?? '', 40),
                'pic' => self::cut($d['pic'] ?? '', 120),
                'tautan' => self::cut($tautan, 600),
                'catatan' => self::cut($d['catatan'] ?? '', 2000),
            ];
            $db = $this->db();
            if ($id !== '') {
                if ($baseVersion !== null && $this->sameSchedule($cur, $nilai)) {
                    return ['id' => $id, 'unchanged' => true];
                }
                $db->update('UPDATE cg_jadwal SET platform=?, judul=?, caption=?, tanggal=?, jam=?, status=?, insight_id=?, pic=?, tautan=?, catatan=?, diubah_oleh=?, diubah_at=? WHERE id=?',
                    [$nilai['platform'], $nilai['judul'], $nilai['caption'], $nilai['tanggal'], $nilai['jam'], $nilai['status'], $nilai['insight_id'], $nilai['pic'], $nilai['tautan'], $nilai['catatan'], $by, $now, $id]);
                $this->log('Jadwal', $judul, 'diubah', $by);

                return ['id' => $id];
            }
            $id = self::uid('jdw');
            $db->insert('INSERT INTO cg_jadwal (id, platform, judul, caption, tanggal, jam, status, insight_id, pic, tautan, catatan, dibuat_oleh, dibuat_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $nilai['platform'], $nilai['judul'], $nilai['caption'], $nilai['tanggal'], $nilai['jam'], $nilai['status'], $nilai['insight_id'], $nilai['pic'], $nilai['tautan'], $nilai['catatan'], $by, $now]);
            $bankId = self::cut($d['bankId'] ?? '', 40);
            if ($bankId !== '') {
                $db->update("UPDATE cg_bank SET status='TERJADWAL', jadwal_id=?, diubah_oleh=?, diubah_at=? WHERE id=?", [$id, $by, $now, $bankId]);
            }
            $this->log('Jadwal', $judul, 'ditambah', $by);

            return ['id' => $id];
        });
    }

    private function sameSchedule(object $cur, array $nilai): bool
    {
        foreach ($nilai as $k => $v) {
            if ((string) ($cur->$k ?? '') !== (string) $v) {
                return false;
            }
        }

        return true;
    }

    // ─────────────────────────── bank ──

    public function listBank(string $status, string $platform): array
    {
        $this->ensureTables();
        $where = [];
        $args = [];
        if (in_array($status, self::BANK_STATUSES, true)) {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        if ($platform !== '') {
            $where[] = 'platform = ?';
            $args[] = $platform;
        }
        $sql = 'SELECT * FROM cg_bank'.($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY (tanggal IS NULL), tanggal ASC, dibuat_at DESC LIMIT 1000';

        return $this->db()->select($sql, $args);
    }

    public function findBank(string $id): ?object
    {
        $this->ensureTables();

        return $this->db()->selectOne('SELECT * FROM cg_bank WHERE id = ?', [$id]);
    }

    public static function platBank(mixed $p, string $fallback): string
    {
        $p = mb_strtolower(trim((string) $p));
        if (str_contains($p, 'tik')) {
            return 'TikTok';
        }
        if (str_contains($p, 'insta') || $p === 'ig') {
            return 'Instagram';
        }
        if (str_contains($p, 'you')) {
            return 'YouTube';
        }

        return $fallback;
    }

    private static function bankValues(array $d): array
    {
        $tgl = self::validDate($d['tanggal'] ?? '');

        return [
            'judul' => self::cut($d['judul'] ?? '', 200),
            'platform' => self::platBank($d['platform'] ?? '', 'TikTok'),
            'format' => self::cut($d['format'] ?? '', 80),
            'pilar' => self::cut($d['pilar'] ?? '', 80),
            'hook' => self::cut($d['hook'] ?? '', 1000),
            'caption' => self::cut($d['caption'] ?? '', 5000),
            'tanggal' => $tgl,
            'jam' => self::validTime($d['jam'] ?? ''),
            'alasan' => self::cut($d['alasan'] ?? '', 2000),
        ];
    }

    /** @throws RuntimeException validation (422) */
    public function saveBank(array $d, string $by, ?int $baseVersion = null): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($d, $by, $baseVersion) {
            $this->ensureTables();
            $id = trim((string) ($d['id'] ?? ''));
            $cur = null;
            if ($id !== '') {
                $cur = $this->findBank($id);
                if (! $cur) {
                    throw new RuntimeException('not_found:Ide yang disunting tidak ditemukan.');
                }
                if ($baseVersion !== null) {
                    $this->checkVersion($cur, $baseVersion);
                }
                /* PATCH: absent keys keep the stored value. */
                foreach (['judul', 'platform', 'format', 'pilar', 'hook', 'caption', 'tanggal', 'jam', 'alasan'] as $k) {
                    if (! array_key_exists($k, $d)) {
                        $d[$k] = $cur->$k;
                    }
                }
            }
            $nilai = self::bankValues($d);
            if ($nilai['judul'] === '') {
                throw new RuntimeException('Judul ide wajib diisi.');
            }
            $now = self::nowMs();
            $db = $this->db();
            if ($id !== '') {
                if ($baseVersion !== null && $this->sameBank($cur, $nilai)) {
                    return ['id' => $id, 'unchanged' => true];
                }
                $db->update('UPDATE cg_bank SET judul=?, platform=?, format=?, pilar=?, hook=?, caption=?, tanggal=?, jam=?, alasan=?, diubah_oleh=?, diubah_at=? WHERE id=?',
                    [$nilai['judul'], $nilai['platform'], $nilai['format'], $nilai['pilar'], $nilai['hook'], $nilai['caption'], $nilai['tanggal'], $nilai['jam'], $nilai['alasan'], $by, $now, $id]);
                $this->log('Ide bank', $nilai['judul'], 'diubah', $by);

                return ['id' => $id];
            }
            $sumber = ($d['sumber'] ?? '') === 'riset' ? 'riset' : 'manual';
            $id = $this->addBank($nilai, $sumber, self::cut($d['batch'] ?? '', 40), $by, $now);
            $this->log('Ide bank', $nilai['judul'], 'ditambah', $by);

            return ['id' => $id];
        });
    }

    private function addBank(array $nilai, string $sumber, string $batch, string $by, int $now): string
    {
        if ($nilai['judul'] === '') {
            return '';
        }
        $id = self::uid('bnk');
        $this->db()->insert("INSERT INTO cg_bank (id, judul, platform, format, pilar, hook, caption, tanggal, jam, alasan, sumber, batch, status, dibuat_oleh, dibuat_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'IDE', ?, ?)",
            [$id, $nilai['judul'], $nilai['platform'], $nilai['format'], $nilai['pilar'], $nilai['hook'], $nilai['caption'], $nilai['tanggal'], $nilai['jam'], $nilai['alasan'], $sumber, $batch, $by, $now]);

        return $id;
    }

    private function sameBank(object $cur, array $nilai): bool
    {
        foreach ($nilai as $k => $v) {
            if ((string) ($cur->$k ?? '') !== (string) ($v ?? '')) {
                return false;
            }
        }

        return true;
    }

    /** @throws RuntimeException */
    public function archiveBank(string $id, bool $arsip, string $by): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($id, $arsip, $by) {
            $this->ensureTables();
            $cur = $this->findBank($id);
            if (! $cur) {
                throw new RuntimeException('not_found:Ide tidak ditemukan.');
            }
            $this->db()->update("UPDATE cg_bank SET status = CASE WHEN ? = 1 THEN 'ARSIP' WHEN jadwal_id <> '' THEN 'TERJADWAL' ELSE 'IDE' END, diubah_oleh=?, diubah_at=? WHERE id=?",
                [$arsip ? 1 : 0, $by, self::nowMs(), $id]);
            $this->log('Ide bank', (string) ($cur->judul ?? $id), $arsip ? 'diarsipkan' : 'dipulihkan', $by);

            return ['id' => $id];
        });
    }

    /** Permanent delete, ARSIP only — enforced in SQL, like legacy. */
    public function deleteBank(string $id, string $by): array
    {
        return NamedLock::run('konten', 'konten_save', function () use ($id, $by) {
            $this->ensureTables();
            $cur = $this->findBank($id);
            if (! $cur) {
                throw new RuntimeException('not_found:Ide tidak ditemukan.');
            }
            if (($cur->status ?? '') !== 'ARSIP') {
                throw new RuntimeException('Hanya ide yang sudah diarsipkan yang bisa dihapus.');
            }
            $this->db()->delete("DELETE FROM cg_bank WHERE id=? AND status='ARSIP'", [$id]);
            $this->log('Ide bank', (string) ($cur->judul ?? $id), 'dihapus permanen', $by);

            return ['id' => $id];
        });
    }

    // ─────────────────────────── outbound HTTP ──

    /** @return array{kode:int,data:?array,mentah:string} @throws RuntimeException */
    private function http(string $method, string $url, array $headers, mixed $body, int $timeout): array
    {
        try {
            $req = Http::timeout($timeout)->connectTimeout(10)->withHeaders($headers);
            $res = match (strtoupper($method)) {
                'GET' => $req->get($url),
                'PUT' => $req->withBody(is_string($body) ? $body : '', $headers['Content-Type'] ?? 'application/json')->put($url),
                'DELETE' => $req->send('DELETE', $url),
                default => $req->withBody(is_string($body) ? $body : '', $headers['Content-Type'] ?? 'application/json')->post($url),
            };
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal menghubungi '.((string) parse_url($url, PHP_URL_HOST)).': '.$e->getMessage());
        }
        $raw = (string) $res->body();
        $data = json_decode($raw, true);

        return ['kode' => $res->status(), 'data' => is_array($data) ? $data : null, 'mentah' => mb_substr($raw, 0, 400)];
    }

    // ─────────────────────────── AI (Meta Muse Spark) ──

    /** @return array{hasil:array,model:string} @throws RuntimeException */
    public function aiAsk(string $system, string $prompt, int $maks = 6000): array
    {
        $key = $this->aiKey();
        if ($key === '') {
            throw new RuntimeException('API key Meta (Muse Spark) belum diisi. Admin modul bisa mengisinya di Pengaturan.');
        }
        $model = $this->aiModel();
        $maks += 8000;
        $r = $this->http('POST', self::AI_URL,
            ['content-type' => 'application/json', 'Authorization' => 'Bearer '.$key],
            json_encode(['model' => $model, 'max_tokens' => $maks, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_UNESCAPED_UNICODE), 160);
        if ($r['kode'] !== 200 || ! $r['data']) {
            $pesan = $r['data']['error']['message'] ?? $r['mentah'];

            throw new RuntimeException('Meta (Muse Spark) menolak ('.$r['kode'].'): '.(is_string($pesan) ? $pesan : json_encode($pesan)));
        }
        $teks = '';
        $isi = $r['data']['content'] ?? null;
        if (is_string($isi)) {
            $teks = $isi;
        } elseif (is_array($isi)) {
            foreach ($isi as $b) {
                if (is_array($b) && ($b['type'] ?? '') === 'text' && isset($b['text']) && is_string($b['text'])) {
                    $teks .= $b['text'];
                }
            }
        }
        if ($teks === '' && isset($r['data']['choices'][0]['message']['content']) && is_string($r['data']['choices'][0]['message']['content'])) {
            $teks = $r['data']['choices'][0]['message']['content'];
        }
        $henti = (string) ($r['data']['stop_reason'] ?? '');
        if ($henti === 'max_tokens') {
            throw new RuntimeException('Jawaban AI terpotong karena terlalu panjang (batas token). Coba topik yang lebih sempit, atau jalankan lagi.');
        }
        if (trim($teks) === '') {
            throw new RuntimeException('AI tidak memberi jawaban teks'.($henti !== '' ? ' (berhenti: '.$henti.')' : '').'. Coba jalankan lagi.');
        }
        $a = strpos($teks, '{');
        $z = strrpos($teks, '}');
        if ($a === false || $z === false || $z <= $a) {
            throw new RuntimeException('Jawaban AI bukan JSON ('.$model.'): "'.self::cut(preg_replace('/\s+/', ' ', $teks), 160).'". Coba jalankan lagi.');
        }
        $j = json_decode(substr($teks, $a, $z - $a + 1), true);
        if (! is_array($j)) {
            throw new RuntimeException('Jawaban AI tidak bisa dibaca. Coba jalankan lagi.');
        }

        return ['hasil' => $j, 'model' => $model];
    }

    private function buktiPosts(int $n = 25): array
    {
        $baris = [];
        foreach ($this->db()->select('SELECT platform, judul, url, tayang_at, views, likes, comments, shares FROM cg_post WHERE hilang=0 ORDER BY views DESC LIMIT '.(int) $n) as $p) {
            $baris[] = ['platform' => $p->platform, 'judul' => self::cut((string) $p->judul, 220), 'url' => (string) $p->url,
                'tanggal' => (int) $p->tayang_at > 0 ? gmdate('Y-m-d', (int) ($p->tayang_at / 1000) + 7 * 3600) : '',
                'views' => (int) $p->views, 'likes' => (int) $p->likes, 'comments' => (int) $p->comments, 'shares' => (int) $p->shares];
        }
        foreach ($this->kontenPerf() as $k) {
            if (count($baris) >= $n * 2) {
                break;
            }
            $baris[] = ['platform' => $k['platform'], 'judul' => self::cut($k['judul'], 220), 'url' => $k['url'], 'tanggal' => $k['tanggal'],
                'views' => (int) $k['views'], 'likes' => (int) $k['likes'], 'comments' => (int) $k['comments'], 'shares' => (int) $k['shares']];
        }

        return $baris;
    }

    /** @throws RuntimeException */
    public function runResearch(array $d, string $by): array
    {
        $this->ensureTables();
        $topik = self::cut($d['topik'] ?? '', 255);
        if ($topik === '') {
            throw new RuntimeException('Topik riset wajib diisi.');
        }
        $platform = self::cut($d['platform'] ?? 'TikTok, Instagram', 40);
        $catatan = self::cut($d['catatan'] ?? '', 1500);
        $profil = $this->setting('profil', self::PROFIL_BAWAAN);
        $bukti = $this->buktiPosts(20);
        $hariIni = self::wibToday();

        $system = 'Kamu ahli strategi konten media sosial untuk bisnis F&B di Indonesia. Jawab dalam Bahasa Indonesia. '
            .'Kamu TIDAK punya akses internet: yang kamu sebut tren adalah perkiraan dari pengetahuanmu dan dari data postingan brand yang diberikan, '
            .'jadi jangan mengarang angka statistik, nama akun, atau tautan. Kalau tidak yakin, katakan. '
            .'Balas HANYA dengan satu objek JSON, tanpa teks lain.';
        $prompt = "Tanggal hari ini: $hariIni.\nProfil brand: $profil\nTopik riset: $topik\nPlatform: $platform\n"
            .($catatan !== '' ? "Catatan tambahan: $catatan\n" : '')
            ."\nData postingan brand ini (diurut dari views terbanyak):\n".json_encode($bukti, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nBuat riset dengan bentuk JSON persis berikut:\n"
            .'{"ringkasan":"2-3 kalimat","pola":[{"temuan":"pola dari data postingan brand di atas","bukti":"judul/tautan postingan yang jadi buktinya","aksi":"yang sebaiknya dilakukan"}],'
            .'"tren":[{"judul":"","penjelasan":"","platform":"","keyakinan":"tinggi|sedang|rendah"}],'
            .'"ide":[{"judul":"","platform":"TikTok|Instagram|YouTube","format":"","hook":"kalimat 3 detik pertama","caption":"caption siap pakai dengan hashtag","hari":"hari & jam tayang yang disarankan","alasan":""}],'
            .'"waktuTayang":["..."],"hashtag":["#..."],"peringatan":["hal yang perlu dicek manual"]}'
            ."\nBerikan 3-5 pola, 4-6 tren, dan 8 ide konten. Pola WAJIB bersumber dari data postingan di atas; kalau datanya kosong, kosongkan array pola.";

        $r = $this->aiAsk($system, $prompt, 7000);
        $db = $this->freshDb();
        $id = self::uid('rst');
        $db->insert('INSERT INTO cg_riset (id, jenis, topik, platform, catatan, hasil, model, oleh, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'tren', $topik, $platform, $catatan, json_encode($r['hasil'], JSON_UNESCAPED_UNICODE), $r['model'], $by, self::nowMs()]);
        $this->log('Riset tren', $topik, 'dijalankan', $by);

        return ['id' => $id, 'hasil' => $r['hasil'], 'model' => $r['model']];
    }

    /** Insight suggestions (never stored — the saver picks from a form). */
    public function suggestInsights(): array
    {
        $this->ensureTables();
        $bukti = $this->buktiPosts(30);
        if (! $bukti) {
            throw new RuntimeException('Belum ada postingan yang bisa dianalisa. Hubungkan & sinkronkan akun TikTok, atau isi Input Performa di modul Konten.');
        }
        $profil = $this->setting('profil', self::PROFIL_BAWAAN);
        $kategori = $this->kategori();
        $system = 'Kamu analis konten media sosial. Jawab dalam Bahasa Indonesia. Analisa HANYA dari data yang diberikan; '
            .'jangan mengarang angka atau tautan. Balas HANYA dengan satu objek JSON.';
        $prompt = "Profil brand: $profil\nPostingan (diurut views):\n".json_encode($bukti, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nTemukan 4-6 pola yang membuat postingan teratas lebih berhasil daripada yang bawah (hook, topik, format, waktu tayang, ajakan). Bentuk JSON:\n"
            .'{"insight":[{"platform":"TikTok|Instagram|YouTube","kategori":"'.implode('|', $kategori).'",'
            .'"temuan":"","url":"tautan PERSIS dari data di atas yang paling mewakili","tglTayang":"YYYY-MM-DD dari data","metrik":"angka dari data","aksi":""}]}';
        $r = $this->aiAsk($system, $prompt, 4000);
        $daftar = isset($r['hasil']['insight']) && is_array($r['hasil']['insight']) ? $r['hasil']['insight'] : [];
        $sah = [];
        foreach ($bukti as $b) {
            if ($b['url'] !== '') {
                $sah[$b['url']] = true;
            }
        }
        $out = [];
        foreach ($daftar as $i) {
            if (! is_array($i)) {
                continue;
            }
            $url = (string) ($i['url'] ?? '');
            $i['url'] = isset($sah[$url]) ? $url : '';
            if (! isset($i['kategori']) || ! in_array((string) $i['kategori'], $kategori, true)) {
                $i['kategori'] = '';
            }
            $out[] = $i;
        }

        return ['usulan' => $out, 'model' => $r['model']];
    }

    /** @throws RuntimeException */
    public function generateBank(array $d, string $by): array
    {
        $this->ensureTables();
        $dari = self::validDate($d['dari'] ?? '');
        $sampai = self::validDate($d['sampai'] ?? '');
        if ($dari === null || $sampai === null) {
            throw new RuntimeException('Rentang tanggal wajib diisi.');
        }
        if ($sampai < $dari) {
            throw new RuntimeException('Tanggal akhir tidak boleh sebelum tanggal awal.');
        }
        if ((strtotime($sampai) - strtotime($dari)) / 86400 > 62) {
            throw new RuntimeException('Rentang maksimal 2 bulan.');
        }
        $jumlah = max(3, min(20, (int) ($d['jumlah'] ?? 10)));
        $platform = self::cut($d['platform'] ?? 'TikTok, Instagram', 40);
        $fokus = self::cut($d['fokus'] ?? '', 1500);
        $profil = $this->setting('profil', self::PROFIL_BAWAAN);
        $kategori = $this->kategori();
        $insight = [];
        foreach ($this->db()->select('SELECT platform, kategori, temuan, aksi FROM cg_insight WHERE arsip=0 ORDER BY dibuat_at DESC LIMIT 30') as $i) {
            $insight[] = ['platform' => $i->platform, 'kategori' => $i->kategori,
                'temuan' => self::cut((string) $i->temuan, 300), 'aksi' => self::cut((string) $i->aksi, 300)];
        }
        $bukti = $this->buktiPosts(20);
        $platPilih = self::platBank($platform, 'TikTok');

        $system = 'Kamu ahli strategi konten media sosial untuk bisnis F&B di Indonesia. Jawab dalam Bahasa Indonesia. '
            .'Susun rencana konten yang realistis dikerjakan tim kecil. Jangan mengarang angka statistik, nama akun, atau tautan. '
            .'Balas HANYA dengan satu objek JSON, tanpa teks lain.';
        $prompt = "Profil brand: $profil\nRentang tanggal: $dari sampai $sampai\nPlatform: $platform\nJumlah konten: $jumlah\n"
            .($fokus !== '' ? "Fokus / catatan: $fokus\n" : '')
            .'Pilar konten yang dipakai tim: '.implode(', ', $kategori)."\n"
            ."\nInsight tim yang masih berlaku (pakai sebagai dasar):\n".json_encode($insight, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nPostingan brand yang paling jalan (diurut views):\n".json_encode($bukti, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nBuat content planning berbentuk JSON persis:\n"
            .'{"ringkasan":"1-2 kalimat strategi","ide":[{"judul":"","platform":"TikTok|Instagram|YouTube","format":"misal Reels/Video pendek/Carousel/Story",'
            .'"pilar":"salah satu pilar di atas","tanggal":"YYYY-MM-DD di dalam rentang","jam":"HH:MM WIB","hook":"kalimat 3 detik pertama",'
            .'"caption":"caption siap pakai dengan hashtag","alasan":"kenapa konten ini, sebut insight/postingan yang jadi dasarnya"}]}'
            ."\nBuat tepat $jumlah ide, sebar merata di rentang tanggal, variasikan pilar dan format.";

        $r = $this->aiAsk($system, $prompt, 7000);
        $db = $this->freshDb();
        $daftar = isset($r['hasil']['ide']) && is_array($r['hasil']['ide']) ? $r['hasil']['ide'] : [];
        $batch = self::uid('gen');
        $now = self::nowMs();
        $ids = [];
        $svc = $this;
        NamedLock::run('konten', 'konten_save', function () use ($daftar, $dari, $sampai, $kategori, $platPilih, $batch, $by, $now, &$ids, $svc) {
            foreach ($daftar as $ide) {
                if (! is_array($ide)) {
                    continue;
                }
                $tg = self::validDate($ide['tanggal'] ?? '');
                $ide['tanggal'] = ($tg !== null && $tg >= $dari && $tg <= $sampai) ? $tg : '';
                if (! isset($ide['pilar']) || ! in_array((string) $ide['pilar'], $kategori, true)) {
                    $ide['pilar'] = '';
                }
                $ide['platform'] = self::platBank($ide['platform'] ?? '', $platPilih);
                $nilai = self::bankValues($ide);
                $id = $nilai['judul'] === '' ? '' : $svc->insertBankRow($nilai, 'ai', $batch, $by, $now);
                if ($id !== '') {
                    $ids[] = $id;
                }
                if (count($ids) >= 20) {
                    break;
                }
            }
        });
        if (! $ids) {
            throw new RuntimeException('AI tidak menghasilkan satu pun ide yang bisa disimpan. Coba jalankan lagi.');
        }
        $this->log('Content planning', "$dari s.d. $sampai", 'dibuat ('.count($ids).' ide)', $by);

        return ['batch' => $batch, 'jumlah' => count($ids),
            'ringkasan' => isset($r['hasil']['ringkasan']) ? self::cut((string) $r['hasil']['ringkasan'], 600) : '', 'model' => $r['model']];
    }

    /** @internal used inside the generate lock */
    public function insertBankRow(array $nilai, string $sumber, string $batch, string $by, int $now): string
    {
        $id = self::uid('bnk');
        $this->db()->insert("INSERT INTO cg_bank (id, judul, platform, format, pilar, hook, caption, tanggal, jam, alasan, sumber, batch, status, dibuat_oleh, dibuat_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'IDE', ?, ?)",
            [$id, $nilai['judul'], $nilai['platform'], $nilai['format'], $nilai['pilar'], $nilai['hook'], $nilai['caption'], $nilai['tanggal'], $nilai['jam'], $nilai['alasan'], $sumber, $batch, $by, $now]);

        return $id;
    }

    // ─────────────────────────── TikTok OAuth ──

    private function tiktokRedirectUri(): string
    {
        $base = rtrim((string) env('API_PUBLIC_URL', ''), '/');
        if ($base === '') {
            $base = 'https://'.((string) ($_SERVER['SERVER_NAME'] ?? 'api.laksamanamuda.id'));
        }

        return $base.'/api/v1/konten/analytics/oauth/tiktok/callback';
    }

    private function igRedirectUri(): string
    {
        $base = rtrim((string) env('API_PUBLIC_URL', ''), '/');
        if ($base === '') {
            $base = 'https://'.((string) ($_SERVER['SERVER_NAME'] ?? 'api.laksamanamuda.id'));
        }

        return $base.'/api/v1/konten/analytics/oauth/instagram/callback';
    }

    /** @throws RuntimeException */
    public function tiktokStart(string $by): array
    {
        $this->ensureTables();
        $key = $this->secret('TIKTOK_CLIENT_KEY', 'tiktok_key');
        if ($key === '' || $this->secret('TIKTOK_CLIENT_SECRET', 'tiktok_secret') === '') {
            throw new RuntimeException('Client key & secret TikTok belum diisi di Pengaturan.');
        }
        $state = bin2hex(random_bytes(16));
        $this->saveSetting('tiktok_state', json_encode(['s' => $state, 'exp' => self::nowMs() + 15 * 60000, 'by' => $by]));
        $q = http_build_query([
            'client_key' => $key,
            'scope' => self::TIKTOK_SCOPE,
            'response_type' => 'code',
            'redirect_uri' => $this->tiktokRedirectUri(),
            'state' => $state,
        ]);

        return ['url' => 'https://www.tiktok.com/v2/auth/authorize/?'.$q];
    }

    /** @throws RuntimeException */
    private function tiktokRequestToken(array $form): array
    {
        $r = $this->http('POST', 'https://open.tiktokapis.com/v2/oauth/token/',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cache-Control' => 'no-cache'],
            http_build_query($form + ['client_key' => $this->secret('TIKTOK_CLIENT_KEY', 'tiktok_key'),
                'client_secret' => $this->secret('TIKTOK_CLIENT_SECRET', 'tiktok_secret')]), 30);
        if (! $r['data'] || empty($r['data']['access_token'])) {
            $p = $r['data']['error_description'] ?? $r['data']['error'] ?? $r['mentah'];

            throw new RuntimeException('TikTok menolak token: '.$p);
        }

        return $r['data'];
    }

    private function tiktokSaveToken(array $t, string $by): void
    {
        $now = self::nowMs();
        $baru = (string) ($t['open_id'] ?? '');
        $lama = (string) ($this->db()->selectOne('SELECT open_id FROM cg_akun WHERE platform = ?', ['tiktok'])->open_id ?? '');
        if ($baru !== '' && $lama !== '' && $lama !== $baru) {
            $this->saveSetting('tiktok_bf_cursor', '');
            $this->saveSetting('tiktok_bf_selesai', '');
        }
        $this->db()->update('INSERT INTO cg_akun (platform, open_id, access_token, refresh_token, token_exp, refresh_exp, scope, oleh, at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE open_id=VALUES(open_id), access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
              token_exp=VALUES(token_exp), refresh_exp=VALUES(refresh_exp), scope=VALUES(scope), oleh=VALUES(oleh), at=VALUES(at)',
            ['tiktok', $baru, (string) $t['access_token'], (string) ($t['refresh_token'] ?? ''),
                $now + (int) ($t['expires_in'] ?? 0) * 1000, $now + (int) ($t['refresh_expires_in'] ?? 0) * 1000,
                (string) ($t['scope'] ?? ''), $by, $now]);
    }

    /** @throws RuntimeException */
    public function tiktokCallback(string $code, string $state): array
    {
        $this->ensureTables();
        $st = json_decode($this->setting('tiktok_state'), true);
        if (! is_array($st) || ! hash_equals((string) ($st['s'] ?? ''), $state)) {
            throw new RuntimeException('Tautan otorisasi tidak dikenal. Ulangi dari tombol Hubungkan.');
        }
        if ((int) ($st['exp'] ?? 0) < self::nowMs()) {
            throw new RuntimeException('Tautan otorisasi sudah kedaluwarsa (15 menit). Ulangi dari tombol Hubungkan.');
        }
        $this->saveSetting('tiktok_state', '');
        $t = $this->tiktokRequestToken(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $this->tiktokRedirectUri()]);
        $this->tiktokSaveToken($t, (string) ($st['by'] ?? ''));

        // No full sync inside the OAuth redirect (#304): it ran up to the PHP/proxy
        // limit and the user landed on a 500. The screen's Sinkronkan pulls it.
        return ['terhubung' => true];
    }

    /** @throws RuntimeException */
    private function tiktokToken(): string
    {
        $a = $this->db()->selectOne('SELECT * FROM cg_akun WHERE platform = ?', ['tiktok']);
        if (! $a || (string) $a->access_token === '') {
            throw new RuntimeException('Akun TikTok belum terhubung.');
        }
        if ((int) $a->token_exp - 60000 < self::nowMs()) {
            if ((string) $a->refresh_token === '' || (int) $a->refresh_exp < self::nowMs()) {
                throw new RuntimeException('Izin TikTok sudah habis masa berlakunya. Tekan Hubungkan lagi.');
            }
            $t = $this->tiktokRequestToken(['grant_type' => 'refresh_token', 'refresh_token' => (string) $a->refresh_token]);
            $this->tiktokSaveToken($t, (string) $a->oleh);

            return (string) $t['access_token'];
        }

        return (string) $a->access_token;
    }

    /** @throws RuntimeException */
    public function tiktokSync(): array
    {
        $this->ensureTables();
        $this->startBudget();
        $now = self::nowMs();
        try {
            $tok = $this->tiktokToken();
            $auth = ['Authorization' => 'Bearer '.$tok];

            $granted = array_map('trim', explode(',', (string) ($this->db()->selectOne('SELECT scope FROM cg_akun WHERE platform = ?', ['tiktok'])->scope ?? '')));
            $fu = 'open_id,display_name,avatar_url';
            if (in_array('user.info.profile', $granted, true)) {
                $fu .= ',bio_description,profile_web_link,is_verified';
            }
            if (in_array('user.info.stats', $granted, true)) {
                $fu .= ',follower_count,following_count,likes_count,video_count';
            }
            $u = $this->http('GET', 'https://open.tiktokapis.com/v2/user/info/?fields='.$fu, $auth, null, 30);
            $user = $u['data']['data']['user'] ?? [];
            if ($user) {
                $this->db()->update('UPDATE cg_akun SET nama=?, avatar=?, bio=?, profil_url=?, terverifikasi=?,
                    follower=?, following=?, likes_total=?, jml_video=?, stats_ada=? WHERE platform=?',
                    [(string) ($user['display_name'] ?? ''), (string) ($user['avatar_url'] ?? ''),
                        self::cut((string) ($user['bio_description'] ?? ''), 1000), self::cut((string) ($user['profile_web_link'] ?? ''), 400),
                        ! empty($user['is_verified']) ? 1 : 0,
                        (int) ($user['follower_count'] ?? 0), (int) ($user['following_count'] ?? 0),
                        (int) ($user['likes_count'] ?? 0), (int) ($user['video_count'] ?? 0),
                        in_array('user.info.stats', $granted, true) ? 1 : 0, 'tiktok']);
            }

            $fields = 'id,title,video_description,create_time,cover_image_url,share_url,view_count,like_count,comment_count,share_count,duration';
            $n = 0;
            $svc = $this;
            $simpan = function (array $v) use ($svc, $now, &$n): void {
                $judul = trim((string) ($v['video_description'] ?? ''));
                if ($judul === '') {
                    $judul = (string) ($v['title'] ?? '');
                }
                $svc->db()->update('INSERT INTO cg_post (platform, post_id, judul, url, cover, tayang_at, views, likes, comments, shares, durasi, sync_at, hilang)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE judul=VALUES(judul), url=VALUES(url), cover=VALUES(cover), tayang_at=VALUES(tayang_at),
                      views=VALUES(views), likes=VALUES(likes), comments=VALUES(comments), shares=VALUES(shares), durasi=VALUES(durasi),
                      sync_at=VALUES(sync_at), hilang=0',
                    ['tiktok', (string) $v['id'], $judul, (string) ($v['share_url'] ?? ''),
                        self::cut((string) ($v['cover_image_url'] ?? ''), 900),
                        (int) ($v['create_time'] ?? 0) * 1000, (int) ($v['view_count'] ?? 0), (int) ($v['like_count'] ?? 0),
                        (int) ($v['comment_count'] ?? 0), (int) ($v['share_count'] ?? 0), (int) ($v['duration'] ?? 0), $now]);
                $n++;
            };
            $halaman = function ($cursor) use ($svc, $auth, $fields): array {
                $isi = ['max_count' => 20];
                if ($cursor !== null && $cursor !== '') {
                    $isi['cursor'] = (int) $cursor;
                }
                $r = $svc->http('POST', 'https://open.tiktokapis.com/v2/video/list/?fields='.$fields,
                    $auth + ['Content-Type' => 'application/json'], json_encode($isi), 30);
                $data = $r['data']['data'] ?? null;
                if (! $data) {
                    $p = $r['data']['error']['message'] ?? $r['mentah'];

                    throw new RuntimeException('Daftar video TikTok tidak terbaca: '.$p);
                }

                return $data;
            };

            $lanjut = null;
            $habis = false;
            for ($h = 0; $h < 2; $h++) {
                $data = $halaman($lanjut);
                foreach ($data['videos'] ?? [] as $v) {
                    $simpan($v);
                }
                if (empty($data['has_more'])) {
                    $habis = true;
                    break;
                }
                $lanjut = isset($data['cursor']) ? (string) $data['cursor'] : null;
            }

            $selesai = $this->setting('tiktok_bf_selesai') === '1';
            if (! $selesai) {
                $c = $this->setting('tiktok_bf_cursor');
                if ($c === '') {
                    $c = $habis ? null : $lanjut;
                }
                if ($c === null) {
                    $selesai = true;
                }
                for ($h = 0; ! $selesai && $h < 5 && ! $this->overBudget(); $h++) {
                    $data = $halaman($c);
                    foreach ($data['videos'] ?? [] as $v) {
                        $simpan($v);
                    }
                    if (empty($data['has_more'])) {
                        $selesai = true;
                        break;
                    }
                    $c = isset($data['cursor']) ? (string) $data['cursor'] : '';
                    if ($c === '') {
                        $selesai = true;
                        break;
                    }
                    $this->saveSetting('tiktok_bf_cursor', $c);
                }
                if ($selesai) {
                    $this->saveSetting('tiktok_bf_selesai', '1');
                    $this->saveSetting('tiktok_bf_cursor', '');
                }
            }

            $ids = [];
            foreach ($this->db()->select("SELECT post_id FROM cg_post WHERE platform='tiktok' AND hilang=0 AND sync_at < ? ORDER BY sync_at ASC LIMIT 40", [$now]) as $row) {
                $ids[] = (string) $row->post_id;
            }
            foreach (array_chunk($ids, 20) as $kelompok) {
                if ($this->overBudget()) {
                    break; // the rest are the oldest-synced: first in line next time
                }
                $r = $this->http('POST', 'https://open.tiktokapis.com/v2/video/query/?fields='.$fields,
                    $auth + ['Content-Type' => 'application/json'], json_encode(['filters' => ['video_ids' => $kelompok]]), 30);
                $data = $r['data']['data'] ?? null;
                if (! $data) {
                    break;
                }
                $ada = [];
                foreach ($data['videos'] ?? [] as $v) {
                    $simpan($v);
                    $ada[(string) $v['id']] = true;
                }
                foreach ($kelompok as $id) {
                    if (! isset($ada[$id])) {
                        $this->db()->update("UPDATE cg_post SET hilang=1, sync_at=? WHERE platform='tiktok' AND post_id=?", [$now, $id]);
                    }
                }
            }

            $this->db()->update('UPDATE cg_akun SET sync_at=?, sync_galat=? WHERE platform=?', [$now, '', 'tiktok']);
            $total = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM cg_post WHERE platform='tiktok' AND hilang=0")->c;

            return ['jumlah' => $n, 'total' => $total, 'riwayatSelesai' => $selesai];
        } catch (\Throwable $e) {
            try {
                $this->db()->update('UPDATE cg_akun SET sync_galat=? WHERE platform=?', [self::cut($e->getMessage(), 400), 'tiktok']);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function tiktokDisconnect(string $by): array
    {
        $this->ensureTables();
        $now = self::nowMs();
        $this->db()->update("UPDATE cg_akun SET access_token='', refresh_token='', token_exp=0, refresh_exp=0, oleh=?, at=? WHERE platform=?", [$by, $now, 'tiktok']);
        $this->log('Akun TikTok', '', 'diputus', $by);

        return ['ok' => true];
    }

    // ─────────────────────────── Instagram OAuth ──

    /** @throws RuntimeException */
    public function igStart(string $by): array
    {
        $this->ensureTables();
        $id = $this->secret('INSTAGRAM_APP_ID', 'ig_id');
        if ($id === '' || $this->secret('INSTAGRAM_APP_SECRET', 'ig_secret') === '') {
            throw new RuntimeException('Instagram App ID & App Secret belum diisi di Pengaturan.');
        }
        $state = bin2hex(random_bytes(16));
        $this->saveSetting('ig_state', json_encode(['s' => $state, 'exp' => self::nowMs() + 15 * 60000, 'by' => $by]));
        $q = http_build_query([
            'client_id' => $id,
            'redirect_uri' => $this->igRedirectUri(),
            'response_type' => 'code',
            'scope' => self::IG_SCOPE,
            'state' => $state,
            'enable_fb_login' => '0',
            'force_authentication' => '1',
        ]);

        return ['url' => 'https://www.instagram.com/oauth/authorize?'.$q];
    }

    private static function igError(array $r, string $awal): string
    {
        $p = $r['data']['error']['message'] ?? $r['data']['error_message'] ?? $r['mentah'];

        return $awal.': '.(is_string($p) ? $p : json_encode($p));
    }

    private function igSaveToken(string $tok, int $exp, string $userId, string $scope, string $by): void
    {
        $now = self::nowMs();
        $lama = (string) ($this->db()->selectOne('SELECT open_id FROM cg_akun WHERE platform = ?', ['instagram'])->open_id ?? '');
        if ($userId !== '' && $lama !== '' && $lama !== $userId) {
            $this->saveSetting('ig_bf_after', '');
            $this->saveSetting('ig_bf_selesai', '');
        }
        $this->db()->update('INSERT INTO cg_akun (platform, open_id, access_token, refresh_token, token_exp, refresh_exp, scope, oleh, at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE open_id=VALUES(open_id), access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
              token_exp=VALUES(token_exp), refresh_exp=VALUES(refresh_exp), scope=VALUES(scope), oleh=VALUES(oleh), at=VALUES(at)',
            ['instagram', $userId, $tok, '', $exp, $exp, $scope, $by, $now]);
    }

    /** @throws RuntimeException */
    public function igCallback(string $code, string $state): array
    {
        $this->ensureTables();
        $st = json_decode($this->setting('ig_state'), true);
        if (! is_array($st) || ! hash_equals((string) ($st['s'] ?? ''), $state)) {
            throw new RuntimeException('Tautan otorisasi tidak dikenal. Ulangi dari tombol Hubungkan.');
        }
        if ((int) ($st['exp'] ?? 0) < self::nowMs()) {
            throw new RuntimeException('Tautan otorisasi sudah kedaluwarsa (15 menit). Ulangi dari tombol Hubungkan.');
        }
        $this->saveSetting('ig_state', '');
        $code = (string) preg_replace('/#_$/', '', $code);
        $r = $this->http('POST', 'https://api.instagram.com/oauth/access_token', ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $this->secret('INSTAGRAM_APP_ID', 'ig_id'),
                'client_secret' => $this->secret('INSTAGRAM_APP_SECRET', 'ig_secret'), 'grant_type' => 'authorization_code',
                'redirect_uri' => $this->igRedirectUri(), 'code' => $code]), 30);
        $d = $r['data'];
        if (isset($d['data'][0]) && is_array($d['data'][0])) {
            $d = $d['data'][0];
        }
        if (! $d || empty($d['access_token'])) {
            throw new RuntimeException(self::igError($r, 'Instagram menolak token'));
        }
        $userId = (string) ($d['user_id'] ?? '');
        $izin = isset($d['permissions']) ? (is_array($d['permissions']) ? implode(',', $d['permissions']) : (string) $d['permissions']) : self::IG_SCOPE;
        $r2 = $this->http('GET', 'https://graph.instagram.com/access_token?'.http_build_query(['grant_type' => 'ig_exchange_token',
            'client_secret' => $this->secret('INSTAGRAM_APP_SECRET', 'ig_secret'), 'access_token' => (string) $d['access_token']]), [], null, 30);
        if (! $r2['data'] || empty($r2['data']['access_token'])) {
            throw new RuntimeException(self::igError($r2, 'Instagram menolak token jangka panjang'));
        }
        $exp = self::nowMs() + (int) ($r2['data']['expires_in'] ?? 5184000) * 1000;
        $this->igSaveToken((string) $r2['data']['access_token'], $exp, $userId, $izin, (string) ($st['by'] ?? ''));

        // No full sync inside the OAuth redirect (#304), see tiktokCallback().
        return ['terhubung' => true];
    }

    /** @throws RuntimeException */
    private function igToken(): string
    {
        $a = $this->db()->selectOne('SELECT * FROM cg_akun WHERE platform = ?', ['instagram']);
        if (! $a || (string) $a->access_token === '') {
            throw new RuntimeException('Akun Instagram belum terhubung.');
        }
        $now = self::nowMs();
        if ((int) $a->token_exp < $now) {
            throw new RuntimeException('Izin Instagram sudah habis masa berlakunya (60 hari). Tekan Hubungkan lagi.');
        }
        $tok = (string) $a->access_token;
        if ((int) $a->token_exp - $now < 30 * 86400000 && $now - (int) $a->at > 86400000) {
            $r = $this->http('GET', 'https://graph.instagram.com/refresh_access_token?'.http_build_query(['grant_type' => 'ig_refresh_token', 'access_token' => $tok]), [], null, 30);
            if ($r['data'] && ! empty($r['data']['access_token'])) {
                $tok = (string) $r['data']['access_token'];
                $this->igSaveToken($tok, $now + (int) ($r['data']['expires_in'] ?? 5184000) * 1000,
                    (string) $a->open_id, (string) $a->scope, (string) $a->oleh);
            }
        }

        return $tok;
    }

    private function igInsightMedia(string $tok, string $id): ?array
    {
        foreach (['views,reach,saved,shares', 'reach,saved', 'reach'] as $m) {
            $r = $this->http('GET', self::IG_GRAPH.'/'.rawurlencode($id).'/insights?'.http_build_query(['metric' => $m, 'access_token' => $tok]), [], null, 20);
            if (! $r['data'] || ! isset($r['data']['data']) || ! is_array($r['data']['data'])) {
                continue;
            }
            $out = [];
            foreach ($r['data']['data'] as $x) {
                if (! isset($x['name'])) {
                    continue;
                }
                $v = $x['values'][0]['value'] ?? $x['total_value']['value'] ?? null;
                if (is_numeric($v)) {
                    $out[$x['name']] = (int) $v;
                }
            }

            return $out;
        }

        return null;
    }

    /** @throws RuntimeException */
    public function igSync(): array
    {
        $this->ensureTables();
        $this->startBudget();
        $now = self::nowMs();
        try {
            $tok = $this->igToken();

            $u = $this->http('GET', self::IG_GRAPH.'/me?'.http_build_query(['fields' => 'user_id,username,name,profile_picture_url,followers_count,follows_count,media_count,biography', 'access_token' => $tok]), [], null, 30);
            if (! $u['data'] || isset($u['data']['error'])) {
                throw new RuntimeException(self::igError($u, 'Profil Instagram tidak terbaca'));
            }
            $p = $u['data'];
            $uname = (string) ($p['username'] ?? '');
            $this->db()->update('UPDATE cg_akun SET nama=?, avatar=?, bio=?, profil_url=?, terverifikasi=0,
                follower=?, following=?, likes_total=0, jml_video=?, stats_ada=1 WHERE platform=?',
                [(string) (($p['name'] ?? '') !== '' ? $p['name'] : $uname), self::cut((string) ($p['profile_picture_url'] ?? ''), 900),
                    self::cut((string) ($p['biography'] ?? ''), 1000),
                    $uname !== '' ? 'https://www.instagram.com/'.rawurlencode($uname).'/' : '',
                    (int) ($p['followers_count'] ?? 0), (int) ($p['follows_count'] ?? 0), (int) ($p['media_count'] ?? 0), 'instagram']);

            $fields = 'id,caption,media_type,media_product_type,permalink,timestamp,thumbnail_url,media_url,like_count,comments_count';
            $n = 0;
            $svc = $this;
            $simpan = function (array $m) use ($svc, $now, &$n): void {
                $ts = isset($m['timestamp']) ? strtotime((string) $m['timestamp']) : 0;
                $cover = $m['thumbnail_url'] ?? ((isset($m['media_url']) && (! isset($m['media_type']) || $m['media_type'] !== 'VIDEO')) ? $m['media_url'] : '');
                $svc->db()->update('INSERT INTO cg_post (platform, post_id, judul, url, cover, tayang_at, views, likes, comments, shares, durasi, sync_at, hilang)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, 0, ?, 0)
                    ON DUPLICATE KEY UPDATE judul=VALUES(judul), url=VALUES(url), cover=VALUES(cover), tayang_at=VALUES(tayang_at),
                      likes=VALUES(likes), comments=VALUES(comments), sync_at=VALUES(sync_at), hilang=0',
                    ['instagram', (string) $m['id'], (string) ($m['caption'] ?? ''),
                        self::cut((string) ($m['permalink'] ?? ''), 600), self::cut((string) $cover, 900),
                        $ts ? $ts * 1000 : 0, (int) ($m['like_count'] ?? 0), (int) ($m['comments_count'] ?? 0), $now]);
                $n++;
            };
            $halaman = function ($after) use ($svc, $tok, $fields): array {
                $qs = ['fields' => $fields, 'limit' => 50, 'access_token' => $tok];
                if ($after !== null && $after !== '') {
                    $qs['after'] = $after;
                }
                $r = $svc->http('GET', CampaignAnalytics::IG_GRAPH.'/me/media?'.http_build_query($qs), [], null, 30);
                if (! $r['data'] || ! isset($r['data']['data'])) {
                    throw new RuntimeException(CampaignAnalytics::igError($r, 'Daftar postingan Instagram tidak terbaca'));
                }

                return $r['data'];
            };
            $lanjut = fn (array $data): string => (! empty($data['paging']['next']) && isset($data['paging']['cursors']['after'])) ? (string) $data['paging']['cursors']['after'] : '';

            $data = $halaman(null);
            foreach ($data['data'] as $m) {
                $simpan($m);
            }
            $after = $lanjut($data);

            $selesai = $this->setting('ig_bf_selesai') === '1';
            if (! $selesai) {
                $c = $this->setting('ig_bf_after');
                if ($c === '') {
                    $c = $after;
                }
                if ($c === '') {
                    $selesai = true;
                }
                for ($h = 0; ! $selesai && $h < 3 && ! $this->overBudget(); $h++) {
                    $data = $halaman($c);
                    foreach ($data['data'] as $m) {
                        $simpan($m);
                    }
                    $c = $lanjut($data);
                    if ($c === '') {
                        $selesai = true;
                        break;
                    }
                    $this->saveSetting('ig_bf_after', $c);
                }
                if ($selesai) {
                    $this->saveSetting('ig_bf_selesai', '1');
                    $this->saveSetting('ig_bf_after', '');
                }
            }

            $tua = [];
            foreach ($this->db()->select("SELECT post_id FROM cg_post WHERE platform='instagram' AND hilang=0 ORDER BY insight_at ASC, tayang_at DESC LIMIT 25") as $row) {
                $tua[] = (string) $row->post_id;
            }
            foreach ($tua as $id) {
                if ($this->overBudget()) {
                    break; // ordered by insight_at: the rest go first next time
                }
                $ix = $this->igInsightMedia($tok, $id);
                if ($ix === null) {
                    $this->db()->update("UPDATE cg_post SET insight_at=? WHERE platform='instagram' AND post_id=?", [$now, $id]);

                    continue;
                }
                $this->db()->update("UPDATE cg_post SET views=?, reach=?, saves=?, shares=?, insight_at=? WHERE platform='instagram' AND post_id=?",
                    [$ix['views'] ?? ($ix['reach'] ?? 0), $ix['reach'] ?? null, $ix['saved'] ?? null, $ix['shares'] ?? 0, $now, $id]);
            }

            $this->db()->update('UPDATE cg_akun SET sync_at=?, sync_galat=? WHERE platform=?', [$now, '', 'instagram']);
            $total = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM cg_post WHERE platform='instagram' AND hilang=0")->c;

            return ['jumlah' => $n, 'total' => $total, 'riwayatSelesai' => $selesai];
        } catch (\Throwable $e) {
            try {
                $this->db()->update('UPDATE cg_akun SET sync_galat=? WHERE platform=?', [self::cut($e->getMessage(), 400), 'instagram']);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function igDisconnect(string $by): array
    {
        $this->ensureTables();
        $now = self::nowMs();
        $this->db()->update("UPDATE cg_akun SET access_token='', refresh_token='', token_exp=0, refresh_exp=0, oleh=?, at=? WHERE platform=?", [$by, $now, 'instagram']);
        $this->log('Akun Instagram', '', 'diputus', $by);

        return ['ok' => true];
    }

    // ─────────────────────────── settings (module admin) ──

    /** @throws RuntimeException */
    public function saveSettings(array $d): array
    {
        $this->ensureTables();
        foreach (['aiKeyMeta' => 'ai_key_meta', 'tiktokKey' => 'tiktok_key', 'tiktokSecret' => 'tiktok_secret', 'igId' => 'ig_id', 'igSecret' => 'ig_secret',
            'googleClientId' => 'google_client_id', 'googleClientSecret' => 'google_client_secret', 'placesKey' => 'google_places_key'] as $f => $k) {
            if (! isset($d[$f])) {
                continue;
            }
            $v = trim((string) $d[$f]);
            if ($v === '') {
                continue;
            }
            $this->saveSetting($k, $v === 'HAPUS' ? '' : $v);
        }
        if (isset($d['aiModelMeta']) && in_array((string) $d['aiModelMeta'], self::AI_MODELS, true)) {
            $this->saveSetting('ai_model_meta', (string) $d['aiModelMeta']);
        }
        if (isset($d['kategori'])) {
            $raw = is_string($d['kategori']) ? preg_split('/\r?\n/', $d['kategori']) : $d['kategori'];
            $rapi = [];
            $seen = [];
            if (is_array($raw)) {
                foreach ($raw as $k) {
                    if (! is_string($k) && ! is_numeric($k)) {
                        continue;
                    }
                    $k = self::cut(trim(preg_replace('/\s+/', ' ', (string) $k)), 60);
                    if ($k === '') {
                        continue;
                    }
                    $low = mb_strtolower($k);
                    if (isset($seen[$low])) {
                        continue;
                    }
                    $seen[$low] = true;
                    $rapi[] = $k;
                    if (count($rapi) >= self::KATEGORI_MAKS) {
                        break;
                    }
                }
            }
            if (! $rapi) {
                throw new RuntimeException('Kategori pola minimal satu.');
            }
            $this->saveSetting('kategori', json_encode($rapi, JSON_UNESCAPED_UNICODE));
        }
        if (isset($d['profil'])) {
            $this->saveSetting('profil', self::cut((string) $d['profil'], 1500));
        }
        /* Place IDs are not secret. Anything that is not Place-ID shaped is
           REJECTED — a pasted Maps link must not sit there silently pulling
           nothing. */
        if (isset($d['placesIds'])) {
            $ids = [];
            foreach (preg_split('/[\s,;]+/', (string) $d['placesIds']) as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                if (! preg_match('/^[A-Za-z0-9_\-]{10,190}$/', $p)) {
                    throw new RuntimeException('Place ID tidak sah: "'.self::cut($p, 60).'". Pakai tombol Cari tempat untuk mendapatkannya.');
                }
                $ids[] = $p;
            }
            $this->saveSetting('google_places', implode(',', array_unique($ids)));
        }

        return ['ok' => true];
    }

    // ─────────────────────────── Google Review ──
    //
    // v1 port of laksamana-office/konten-mysql/lib_greview.php (develop
    // 78424a5, 6e578df): the Konten Analytics "Google Review" menu. Two
    // sources, and the difference decides what can be done:
    //
    //   Business Profile (gbp) — OAuth of the profile owner/manager: ALL
    //     reviews + their replies, and replies can be posted from here.
    //   Places API (places) — API key: 5 reviews per pull, read-only.
    //
    // Places covered by Business Profile (same metadata.placeId) are never
    // pulled again — one review would stand twice. There is NO DELETE: a
    // review Google no longer returns is left alone; "ditangani" is a flag,
    // not a deletion. The only DELETE sent is to Google (removing our own
    // reply), and only on request. Reviews are never sent to the AI unless
    // asked (gr_ai / gr_ai_draf below).
    //
    // Secrets precedence: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and
    // GOOGLE_PLACES_KEY from the environment win over the settings table,
    // like the TikTok/Instagram/Meta keys.

    private function googleClientId(): string
    {
        return $this->secret('GOOGLE_CLIENT_ID', 'google_client_id');
    }

    private function googleClientSecret(): string
    {
        return $this->secret('GOOGLE_CLIENT_SECRET', 'google_client_secret');
    }

    private function googlePlacesKey(): string
    {
        return $this->secret('GOOGLE_PLACES_KEY', 'google_places_key');
    }

    /** Place IDs watched through the Places API. */
    public function googlePlacesIds(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $this->setting('google_places')) as $p) {
            $p = trim($p);
            if ($p !== '' && preg_match('/^[A-Za-z0-9_\-]{10,190}$/', $p)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    public function googleRedirectUri(): string
    {
        $base = rtrim((string) env('API_PUBLIC_URL', ''), '/');
        if ($base === '') {
            $base = 'https://'.((string) ($_SERVER['SERVER_NAME'] ?? 'api.laksamanamuda.id'));
        }

        return $base.'/api/v1/konten/analytics/oauth/google/callback';
    }

    /** Setting summary for the screen — secrets never ride along. */
    public function googleSettingSummary(): array
    {
        return [
            'googleOauthSiap' => $this->googleClientId() !== '' && $this->googleClientSecret() !== '',
            'googleDariConfig' => $this->secretFromConfig('GOOGLE_CLIENT_ID'),
            'googleRedirectUri' => $this->googleRedirectUri(),
            'placesKeyAda' => $this->googlePlacesKey() !== '',
            'placesDariConfig' => $this->secretFromConfig('GOOGLE_PLACES_KEY'),
            'placesIds' => implode(', ', $this->googlePlacesIds()),
        ];
    }

    private static function wibDate(int $offset = 0): string
    {
        return gmdate('Y-m-d', time() + 7 * 3600 + $offset * 86400);
    }

    private static function isoMs(mixed $t): int
    {
        $x = strtotime((string) $t);

        return $x ? $x * 1000 : 0;
    }

    private static function googleError(array $r, string $awal): string
    {
        $p = $r['data']['error']['message'] ?? $r['data']['error_description']
            ?? (isset($r['data']['error']) && is_string($r['data']['error']) ? $r['data']['error'] : $r['mentah']);
        $p = is_string($p) ? $p : json_encode($p);
        /* Quota 0 = the Business Profile API access has not been approved
           yet. The raw message ("Quota exceeded ... 0") reads like
           overuse. */
        if (stripos($p, 'quota') !== false && ($r['kode'] ?? null) == 429) {
            $p .= ' — kemungkinan besar akses Business Profile API untuk project Google Cloud ini BELUM disetujui Google (kuotanya 0 sampai pengajuan disetujui).';
        }
        if (($r['kode'] ?? null) == 403 && stripos($p, 'has not been used') !== false) {
            $p .= ' — aktifkan API itu di Google Cloud Console → APIs & Services → Library.';
        }

        return $awal.' ('.($r['kode'] ?? '?').'): '.$p;
    }

    /** @throws RuntimeException */
    public function googleStart(string $by): array
    {
        $this->ensureTables();
        if ($this->googleClientId() === '' || $this->googleClientSecret() === '') {
            throw new RuntimeException('Client ID & Client secret Google belum diisi di Pengaturan.');
        }
        $state = bin2hex(random_bytes(16));
        $this->saveSetting('google_state', json_encode(['s' => $state, 'exp' => self::nowMs() + 15 * 60000, 'by' => $by]));
        /* access_type=offline + prompt=consent: WITHOUT both, Google gives
           no refresh token on the second grant, and the connection silently
           dies an hour later with no way to renew it. */

        return ['url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->googleClientId(), 'redirect_uri' => $this->googleRedirectUri(),
            'response_type' => 'code', 'scope' => self::GR_SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent',
            'include_granted_scopes' => 'true', 'state' => $state,
        ])];
    }

    private function googleSaveToken(string $tok, int $exp, string $refresh, string $scope, string $by, string $email): void
    {
        $now = self::nowMs();
        /* Google's refresh token has no expiry (until revoked, and unless
           the app is still in Testing) — recorded far in the future; if
           Google rejects it, refresh_exp is zeroed in googleToken(). */
        $this->db()->update('INSERT INTO cg_akun (platform, nama, open_id, access_token, refresh_token, token_exp, refresh_exp, scope, sync_galat, oleh, at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE nama=VALUES(nama), open_id=VALUES(open_id), access_token=VALUES(access_token),
              refresh_token=VALUES(refresh_token), token_exp=VALUES(token_exp), refresh_exp=VALUES(refresh_exp),
              scope=VALUES(scope), sync_galat=VALUES(sync_galat), oleh=VALUES(oleh), at=VALUES(at)',
            ['google', $email, $email, $tok, $refresh, $exp, $now + 3650 * 86400000, $scope, '', $by, $now]);
    }

    /** @throws RuntimeException */
    public function googleCallback(string $code, string $state): array
    {
        $this->ensureTables();
        $st = json_decode($this->setting('google_state'), true);
        if (! is_array($st) || ! hash_equals((string) ($st['s'] ?? ''), $state)) {
            throw new RuntimeException('Tautan otorisasi tidak dikenal. Ulangi dari tombol Hubungkan.');
        }
        if ((int) ($st['exp'] ?? 0) < self::nowMs()) {
            throw new RuntimeException('Tautan otorisasi sudah kedaluwarsa (15 menit). Ulangi dari tombol Hubungkan.');
        }
        $this->saveSetting('google_state', '');
        $r = $this->http('POST', 'https://oauth2.googleapis.com/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['code' => $code, 'client_id' => $this->googleClientId(),
                'client_secret' => $this->googleClientSecret(),
                'redirect_uri' => $this->googleRedirectUri(), 'grant_type' => 'authorization_code']), 30);
        $d = $r['data'];
        if (! $d || empty($d['access_token'])) {
            throw new RuntimeException(self::googleError($r, 'Google menolak token'));
        }
        $lamaRefresh = '';
        if (empty($d['refresh_token'])) {
            $lamaRefresh = (string) ($this->db()->selectOne("SELECT refresh_token FROM cg_akun WHERE platform = 'google'")->refresh_token ?? '');
            if ($lamaRefresh === '') {
                throw new RuntimeException('Google tidak memberi refresh token. Cabut akses app ini di myaccount.google.com/permissions lalu Hubungkan lagi.');
            }
        }
        /* The consenting account's email — so the screen can name WHO is
           connected; id_token is not requested, so it is read from
           tokeninfo. */
        $email = '';
        $ti = $this->http('GET', 'https://oauth2.googleapis.com/tokeninfo?access_token='.rawurlencode((string) $d['access_token']), [], null, 20);
        if (($ti['data'] ?? null) && ! empty($ti['data']['email'])) {
            $email = (string) $ti['data']['email'];
        }
        $this->googleSaveToken((string) $d['access_token'],
            self::nowMs() + (int) ($d['expires_in'] ?? 3600) * 1000,
            ! empty($d['refresh_token']) ? (string) $d['refresh_token'] : $lamaRefresh,
            (string) ($d['scope'] ?? self::GR_SCOPE), (string) ($st['by'] ?? ''), $email);
        $this->googleLocations();

        // No full sync inside the OAuth redirect (#304), see tiktokCallback().
        return ['terhubung' => true, 'lokasi' => (int) $this->db()->selectOne("SELECT COUNT(*) c FROM cg_gtempat WHERE sumber='gbp' AND aktif=1")->c];
    }

    /** A still-valid access token; renewed via the refresh token under 2 min left. @throws RuntimeException */
    private function googleToken(): string
    {
        $a = $this->db()->selectOne("SELECT * FROM cg_akun WHERE platform = 'google'");
        if (! $a || (string) $a->refresh_token === '') {
            throw new RuntimeException('Akun Google Business Profile belum terhubung.');
        }
        if ((int) $a->refresh_exp < self::nowMs()) {
            throw new RuntimeException('Izin Google sudah dicabut atau kedaluwarsa. Tekan Hubungkan lagi.');
        }
        if ((int) $a->token_exp - self::nowMs() > 120000) {
            return (string) $a->access_token;
        }
        $r = $this->http('POST', 'https://oauth2.googleapis.com/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $this->googleClientId(), 'client_secret' => $this->googleClientSecret(),
                'refresh_token' => (string) $a->refresh_token, 'grant_type' => 'refresh_token']), 30);
        if (! $r['data'] || empty($r['data']['access_token'])) {
            /* invalid_grant = refresh token revoked / app still in Testing
               (Testing refresh tokens live 7 days). Marked disconnected so
               the screen says to reconnect instead of repeating the same
               error every sync. */
            if (($r['data']['error'] ?? null) === 'invalid_grant') {
                $this->db()->update("UPDATE cg_akun SET refresh_exp = 0, sync_galat = ? WHERE platform = 'google'",
                    ['Izin Google dicabut/kedaluwarsa. Kalau app OAuth masih berstatus Testing, izinnya cuma bertahan 7 hari — terbitkan (Publish) app-nya.']);

                throw new RuntimeException('Izin Google dicabut atau kedaluwarsa (invalid_grant). Tekan Hubungkan lagi. Kalau terulang tiap ±7 hari, app OAuth-nya masih berstatus Testing.');
            }

            throw new RuntimeException(self::googleError($r, 'Google menolak memperbarui token'));
        }
        $tok = (string) $r['data']['access_token'];
        $this->db()->update("UPDATE cg_akun SET access_token = ?, token_exp = ? WHERE platform = 'google'",
            [$tok, self::nowMs() + (int) ($r['data']['expires_in'] ?? 3600) * 1000]);

        return $tok;
    }

    /** @throws RuntimeException */
    private function googleGet(string $tok, string $url): array
    {
        $r = $this->http('GET', $url, ['Authorization' => 'Bearer '.$tok], null, 40);
        if ($r['kode'] !== 200 || ! is_array($r['data'])) {
            throw new RuntimeException(self::googleError($r, 'Google Business Profile menolak'));
        }

        return $r['data'];
    }

    /**
     * Business locations from every account the person can reach. Old
     * locations no longer returned are DEACTIVATED, not deleted — their
     * reviews stay stored.
     *
     * @throws RuntimeException
     */
    public function googleLocations(): int
    {
        $this->ensureTables();
        $tok = $this->googleToken();
        $akun = [];
        $url = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20';
        for ($h = 0; $url !== '' && $h < 5; $h++) {
            $d = $this->googleGet($tok, $url);
            foreach ($d['accounts'] ?? [] as $a) {
                $akun[] = (string) $a['name'];
            }
            $url = ! empty($d['nextPageToken'])
                ? 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20&pageToken='.rawurlencode($d['nextPageToken']) : '';
        }
        $ada = [];
        foreach ($akun as $acc) {
            $base = 'https://mybusinessbusinessinformation.googleapis.com/v1/'.$acc.'/locations?pageSize=100&readMask=name,title,storefrontAddress,metadata';
            $url = $base;
            for ($h = 0; $url !== '' && $h < 10; $h++) {
                $d = $this->googleGet($tok, $url);
                foreach ($d['locations'] ?? [] as $l) {
                    /* Profiles Google flags as DUPLICATE are skipped: they
                       never face the public and take no reviews; pulled,
                       they only birth twin cards full of errors. Skipped,
                       their old rows join the deactivation below. */
                    if (! empty($l['metadata']['duplicateLocation'])) {
                        continue;
                    }
                    /* The v4 name for reviews = accounts/A/locations/L. */
                    $locId = preg_replace('#^locations/#', '', (string) $l['name']);
                    $id = $acc.'/locations/'.$locId;
                    $alamat = '';
                    if (isset($l['storefrontAddress']['addressLines'])) {
                        $alamat = implode(', ', (array) $l['storefrontAddress']['addressLines']);
                    }
                    if (isset($l['storefrontAddress']['locality'])) {
                        $alamat .= ($alamat !== '' ? ', ' : '').$l['storefrontAddress']['locality'];
                    }
                    $this->db()->update('INSERT INTO cg_gtempat (id, sumber, place_id, nama, alamat, url, aktif)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE sumber=VALUES(sumber), place_id=VALUES(place_id),
                          nama=VALUES(nama), alamat=VALUES(alamat), url=VALUES(url), aktif=1',
                        [$id, 'gbp', isset($l['metadata']['placeId']) ? (string) $l['metadata']['placeId'] : '',
                            self::cut($l['title'] ?? '', 250), self::cut($alamat, 490),
                            isset($l['metadata']['mapsUri']) ? self::cut($l['metadata']['mapsUri'], 590) : '']);
                    $ada[$id] = true;
                }
                $url = ! empty($d['nextPageToken']) ? $base.'&pageToken='.rawurlencode($d['nextPageToken']) : '';
            }
        }
        foreach ($this->db()->select("SELECT id FROM cg_gtempat WHERE sumber = 'gbp'") as $t) {
            if (! isset($ada[$t->id])) {
                $this->db()->update('UPDATE cg_gtempat SET aktif = 0 WHERE id = ?', [$t->id]);
            }
        }

        return count($ada);
    }

    /** @param array<string,mixed> $r */
    private function googleSaveReview(array $r, int $now): void
    {
        /* Our own columns (in-system replies, ditangani flags) are NOT
           overwritten by sync — except replies genuinely from Google
           (answered in Maps directly), and only for the gbp source. */
        $this->db()->update("INSERT INTO cg_greview (id, nama_res, sumber, tempat_id, penulis, penulis_url, foto, rating, teks, waktu_ms, url, balasan, balasan_at, pertama_at, sync_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE nama_res=VALUES(nama_res), tempat_id=VALUES(tempat_id), penulis=VALUES(penulis),
              penulis_url=VALUES(penulis_url), foto=VALUES(foto), rating=VALUES(rating), teks=VALUES(teks),
              waktu_ms=VALUES(waktu_ms), url=VALUES(url),
              balasan=IF(VALUES(sumber)='gbp', VALUES(balasan), balasan),
              balasan_at=IF(VALUES(sumber)='gbp', VALUES(balasan_at), balasan_at), sync_at=VALUES(sync_at)",
            [sha1($r['nama_res']), self::cut($r['nama_res'], 590), $r['sumber'], $r['tempat_id'],
                self::cut($r['penulis'], 190), self::cut($r['penulis_url'], 590), self::cut($r['foto'], 890),
                max(0, min(5, (int) $r['rating'])), self::cut($r['teks'], 6000), (int) $r['waktu_ms'],
                self::cut($r['url'], 590), $r['balasan'], (int) $r['balasan_at'], $now, $now]);
    }

    private function googleDaily(string $tempatId, float|int|null $rating, ?int $total): void
    {
        $this->db()->update('INSERT INTO cg_gtempat_harian (tempat_id, tgl, rating, total) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rating=VALUES(rating), total=VALUES(total)',
            [$tempatId, self::wibDate(0), $rating, $total]);
    }

    /** Business Profile reviews + Places top-ups. @throws RuntimeException */
    public function googleSync(): array
    {
        $this->ensureTables();
        $this->startBudget();
        $now = self::nowMs();
        $jumlah = 0;
        $galat = [];
        $dicakup = [];
        /* 1. Business Profile — every review of every location. */
        $q = $this->db()->selectOne("SELECT refresh_token FROM cg_akun WHERE platform = 'google' AND refresh_exp > ?", [$now]);
        if ($q && (string) $q->refresh_token !== '') {
            try {
                $tok = $this->googleToken();
                if (! (int) $this->db()->selectOne("SELECT COUNT(*) c FROM cg_gtempat WHERE sumber='gbp'")->c) {
                    $this->googleLocations();
                }
                foreach ($this->db()->select("SELECT * FROM cg_gtempat WHERE sumber='gbp' AND aktif=1") as $t) {
                    if ((string) $t->place_id !== '') {
                        $dicakup[(string) $t->place_id] = true;
                    }
                    if ($this->overBudget()) {
                        continue; // not synced this time (still counted as covered, so Places does not pull it)
                    }
                    $kunciKursor = 'gbp_bfc_'.sha1($t->id);
                    $dariKursor = false;
                    try {
                        $base = 'https://mybusiness.googleapis.com/v4/'.$t->id.'/reviews?pageSize=50&orderBy='.rawurlencode('updateTime desc');
                        $url = $base;
                        $rating = null;
                        $total = null;
                        /* Full history once, then the first page only — new
                           and newly edited reviews always sort first on
                           updateTime. The history used to be "up to 40 pages,
                           done only when the last page is reached": a place
                           with more than 2,000 reviews never got there and
                           re-pulled the same newest 2,000 on every sync
                           (#304). Now the page after the last one read is
                           kept as a cursor (gbp_bfc_*): each sync reads page
                           1 (the new reviews), then continues from the cursor. */
                        $penuh = $this->setting('gbp_bf_'.sha1($t->id)) !== '1';
                        $kursor = $penuh ? $this->setting($kunciKursor) : '';
                        $token = '';
                        for ($h = 0; $url !== '' && $h < ($penuh ? 40 : 2) && ! ($h > 0 && $this->overBudget()); $h++) {
                            $dariKursor = $h > 0 && $kursor !== '' && $token === $kursor;
                            $d = $this->googleGet($tok, $url);
                            if (isset($d['averageRating'])) {
                                $rating = (float) $d['averageRating'];
                            }
                            if (isset($d['totalReviewCount'])) {
                                $total = (int) $d['totalReviewCount'];
                            }
                            $bintang = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
                            foreach ($d['reviews'] ?? [] as $rv) {
                                $rep = $rv['reviewReply'] ?? null;
                                $this->googleSaveReview(['nama_res' => (string) $rv['name'], 'sumber' => 'gbp',
                                    'tempat_id' => (string) $t->id,
                                    'penulis' => isset($rv['reviewer']['displayName']) ? (string) $rv['reviewer']['displayName'] : 'Pengguna Google',
                                    'penulis_url' => '',
                                    'foto' => isset($rv['reviewer']['profilePhotoUrl']) ? (string) $rv['reviewer']['profilePhotoUrl'] : '',
                                    'rating' => isset($rv['starRating'], $bintang[$rv['starRating']]) ? $bintang[$rv['starRating']] : 0,
                                    'teks' => isset($rv['comment']) ? self::googleStripTranslation((string) $rv['comment']) : '',
                                    'waktu_ms' => self::isoMs($rv['createTime'] ?? ''),
                                    'url' => (string) $t->url,
                                    'balasan' => $rep && isset($rep['comment']) ? (string) $rep['comment'] : null,
                                    'balasan_at' => $rep && isset($rep['updateTime']) ? self::isoMs($rep['updateTime']) : 0], $now);
                                $jumlah++;
                            }
                            $token = ! empty($d['nextPageToken']) ? (string) $d['nextPageToken'] : '';
                            if ($h === 0 && $kursor !== '') {
                                $token = $kursor; // page 1 read: jump to where the history stopped
                            }
                            $url = $token !== '' ? $base.'&pageToken='.rawurlencode($token) : '';
                        }
                        if ($penuh) {
                            if ($url === '') {
                                $this->saveSetting('gbp_bf_'.sha1($t->id), '1');
                                $this->saveSetting($kunciKursor, '');
                            } elseif ($token !== '') {
                                $this->saveSetting($kunciKursor, $token);
                            }
                        }
                        $this->db()->update('UPDATE cg_gtempat SET rating = ?, total = ?, sync_at = ?, galat = ? WHERE id = ?',
                            [$rating, $total, $now, '', $t->id]);
                        $this->googleDaily((string) $t->id, $rating, $total);
                    } catch (\Throwable $e) {
                        if ($dariKursor) {
                            $this->saveSetting($kunciKursor, ''); // an expired cursor restarts the history instead of failing forever
                        }
                        $galat[] = $t->nama.': '.$e->getMessage();
                        $this->db()->update('UPDATE cg_gtempat SET galat = ? WHERE id = ?',
                            [self::cut($e->getMessage(), 590), $t->id]);
                    }
                }
                $this->db()->update("UPDATE cg_akun SET sync_at = ?, sync_galat = ? WHERE platform = 'google'",
                    [$now, self::cut(implode(' | ', $galat), 390)]);
            } catch (\Throwable $e) {
                $galat[] = $e->getMessage();
                $this->db()->update("UPDATE cg_akun SET sync_at = ?, sync_galat = ? WHERE platform = 'google'",
                    [$now, self::cut($e->getMessage(), 390)]);
            }
        }
        /* 2. Places API — places Business Profile does not cover. */
        $key = $this->googlePlacesKey();
        if ($key !== '') {
            foreach ($this->googlePlacesIds() as $pid) {
                if (isset($dicakup[$pid]) || $this->overBudget()) {
                    continue;
                }
                try {
                    $jumlah += $this->googlePlacesPull($key, $pid, $now);
                } catch (\Throwable $e) {
                    $galat[] = $pid.': '.$e->getMessage();
                    $this->db()->update("INSERT INTO cg_gtempat (id, sumber, place_id, galat) VALUES (?, 'places', ?, ?)
                        ON DUPLICATE KEY UPDATE galat = VALUES(galat)",
                        [$pid, $pid, self::cut($e->getMessage(), 590)]);
                }
            }
            /* Place IDs removed from Pengaturan are deactivated (their
               reviews stay stored). */
            $aktif = array_flip($this->googlePlacesIds());
            foreach ($this->db()->select("SELECT id FROM cg_gtempat WHERE sumber = 'places'") as $t) {
                $this->db()->update('UPDATE cg_gtempat SET aktif = ? WHERE id = ?',
                    [isset($aktif[$t->id]) ? 1 : 0, $t->id]);
            }
        }
        $this->saveSetting('google_sync_at', (string) $now);
        $this->saveSetting('google_galat', self::cut(implode(' | ', $galat), 900));

        return ['jumlah' => $jumlah, 'galat' => $galat];
    }

    /**
     * Business Profile glues on a machine translation: "original text\n\n
     * (Translated by Google)\ntranslation" or the reverse "(Original)".
     * What is stored is the guest's ORIGINAL text.
     */
    public static function googleStripTranslation(string $s): string
    {
        if (preg_match('/\(Original\)\s*(.+)$/s', $s, $m)) {
            return trim($m[1]);
        }
        $p = strpos($s, '(Translated by Google)');
        if ($p !== false) {
            return trim(substr($s, 0, $p));
        }

        return $s;
    }

    /** @throws RuntimeException */
    private function googlePlacesPull(string $key, string $pid, int $now): int
    {
        $r = $this->http('GET', self::GR_PLACES.'/places/'.rawurlencode($pid).'?languageCode=id',
            ['X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'id,displayName,formattedAddress,rating,userRatingCount,googleMapsUri,reviews'], null, 30);
        if ($r['kode'] !== 200 || ! is_array($r['data'])) {
            throw new RuntimeException(self::googleError($r, 'Places API menolak'));
        }
        $d = $r['data'];
        $rating = isset($d['rating']) ? (float) $d['rating'] : null;
        $total = isset($d['userRatingCount']) ? (int) $d['userRatingCount'] : null;
        $this->db()->update("INSERT INTO cg_gtempat (id, sumber, place_id, nama, alamat, rating, total, url, aktif, sync_at, galat)
            VALUES (?, 'places', ?, ?, ?, ?, ?, ?, 1, ?, '')
            ON DUPLICATE KEY UPDATE nama=VALUES(nama), alamat=VALUES(alamat), rating=VALUES(rating),
              total=VALUES(total), url=VALUES(url), aktif=1, sync_at=VALUES(sync_at), galat=''",
            [$pid, $pid, self::cut($d['displayName']['text'] ?? $pid, 250),
                self::cut($d['formattedAddress'] ?? '', 490), $rating, $total,
                self::cut($d['googleMapsUri'] ?? '', 590), $now]);
        $this->googleDaily($pid, $rating, $total);
        $n = 0;
        foreach ($d['reviews'] ?? [] as $rv) {
            if (empty($rv['name'])) {
                continue;
            }
            $teks = isset($rv['originalText']['text']) ? (string) $rv['originalText']['text']
                : (isset($rv['text']['text']) ? (string) $rv['text']['text'] : '');
            $this->googleSaveReview(['nama_res' => (string) $rv['name'], 'sumber' => 'places', 'tempat_id' => $pid,
                'penulis' => isset($rv['authorAttribution']['displayName']) ? (string) $rv['authorAttribution']['displayName'] : 'Pengguna Google',
                'penulis_url' => isset($rv['authorAttribution']['uri']) ? (string) $rv['authorAttribution']['uri'] : '',
                'foto' => isset($rv['authorAttribution']['photoUri']) ? (string) $rv['authorAttribution']['photoUri'] : '',
                'rating' => isset($rv['rating']) ? (int) $rv['rating'] : 0, 'teks' => $teks,
                'waktu_ms' => self::isoMs($rv['publishTime'] ?? ''),
                'url' => isset($rv['googleMapsUri']) ? (string) $rv['googleMapsUri']
                    : (isset($d['googleMapsUri']) ? (string) $d['googleMapsUri'] : ''),
                'balasan' => null, 'balasan_at' => 0], $now);
            $n++;
        }

        return $n;
    }

    /**
     * Find a Place ID from a place name — nobody memorises Place IDs.
     *
     * @throws RuntimeException
     */
    public function googleSearch(array $d): array
    {
        $this->ensureTables();
        $key = $this->googlePlacesKey();
        if ($key === '') {
            throw new RuntimeException('API key Places belum diisi (simpan dulu pengaturannya).');
        }
        $q = self::cut($d['q'] ?? '', 200);
        if ($q === '') {
            throw new RuntimeException('Ketik nama tempatnya.');
        }
        $r = $this->http('POST', self::GR_PLACES.'/places:searchText',
            ['Content-Type' => 'application/json', 'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount'],
            json_encode(['textQuery' => $q, 'languageCode' => 'id', 'pageSize' => 8]), 30);
        if ($r['kode'] !== 200 || ! is_array($r['data'])) {
            throw new RuntimeException(self::googleError($r, 'Places API menolak'));
        }
        $out = [];
        foreach ($r['data']['places'] ?? [] as $p) {
            $out[] = ['id' => (string) $p['id'], 'nama' => $p['displayName']['text'] ?? '',
                'alamat' => $p['formattedAddress'] ?? '',
                'rating' => isset($p['rating']) ? (float) $p['rating'] : null,
                'total' => isset($p['userRatingCount']) ? (int) $p['userRatingCount'] : null];
        }

        return ['hasil' => $out];
    }

    /** Full read: places, 120 days of daily snapshots, reviews, account (no tokens), stored AI. */
    public function googleRead(): array
    {
        $this->ensureTables();
        $db = $this->db();
        $tempat = $db->select('SELECT * FROM cg_gtempat ORDER BY aktif DESC, nama ASC');
        $harian = $db->select('SELECT * FROM cg_gtempat_harian WHERE tgl >= ? ORDER BY tgl ASC', [self::wibDate(-120)]);
        $review = $db->select('SELECT id, sumber, tempat_id, penulis, penulis_url, foto, rating, teks, waktu_ms, url,
            balasan, balasan_at, balasan_oleh, ditangani, tangani_catatan, tangani_oleh, tangani_at, pertama_at
            FROM cg_greview ORDER BY waktu_ms DESC LIMIT 3000');
        $totalTersimpan = (int) $db->selectOne('SELECT COUNT(*) c FROM cg_greview')->c;
        $akun = $db->selectOne("SELECT nama, refresh_exp, sync_at, sync_galat, oleh, at FROM cg_akun WHERE platform = 'google'");
        if ($akun) {
            $akun->terhubung = (int) $akun->refresh_exp > self::nowMs();
        }
        $ai = json_decode($this->setting('gr_ai'), true);

        return ['tempat' => $tempat, 'harian' => $harian, 'review' => $review,
            'totalTersimpan' => $totalTersimpan, 'akun' => $akun ?: null,
            'ai' => is_array($ai) ? $ai : null,
            'syncAt' => (int) $this->setting('google_sync_at', '0'), 'galat' => $this->setting('google_galat')];
    }

    /** @throws RuntimeException */
    public function googleFindReview(string $id): object
    {
        $this->ensureTables();
        $r = $this->db()->selectOne('SELECT * FROM cg_greview WHERE id = ?', [(string) $id]);
        if (! $r) {
            throw new RuntimeException('not_found:Review tidak ditemukan. Muat ulang halamannya.');
        }

        return $r;
    }

    /**
     * The reply is PUBLISHED ON GOOGLE MAPS under the business name — seen
     * in public. The writer is recorded (balasan_oleh) because Google only
     * records the business name itself.
     *
     * @throws RuntimeException
     */
    public function googleReply(array $d, string $by): array
    {
        $r = $this->googleFindReview((string) ($d['id'] ?? ''));
        if ($r->sumber !== 'gbp') {
            throw new RuntimeException('Review ini ditarik lewat Places API — Google tidak mengizinkan membalasnya dari luar. Hubungkan Google Business Profile di Pengaturan supaya review bisa dibalas dari sini.');
        }
        $teks = trim((string) ($d['teks'] ?? ''));
        if ($teks === '') {
            throw new RuntimeException('Isi balasannya dulu.');
        }
        if (mb_strlen($teks) > self::GR_MAKS_BALAS) {
            throw new RuntimeException('Balasan terlalu panjang (maks '.self::GR_MAKS_BALAS.' huruf).');
        }
        $tok = $this->googleToken();
        $res = $this->http('PUT', 'https://mybusiness.googleapis.com/v4/'.$r->nama_res.'/reply',
            ['Authorization' => 'Bearer '.$tok, 'Content-Type' => 'application/json'],
            json_encode(['comment' => $teks], JSON_UNESCAPED_UNICODE), 30);
        if ($res['kode'] !== 200) {
            throw new RuntimeException(self::googleError($res, 'Google menolak balasan'));
        }
        $now = self::nowMs();
        $this->db()->update('UPDATE cg_greview SET balasan = ?, balasan_at = ?, balasan_oleh = ? WHERE id = ?',
            [$teks, $now, $by, $r->id]);

        return ['ok' => true, 'at' => $now];
    }

    /** @throws RuntimeException */
    public function googleDeleteReply(array $d): array
    {
        $r = $this->googleFindReview((string) ($d['id'] ?? ''));
        if ($r->sumber !== 'gbp') {
            throw new RuntimeException('Review ini tidak bisa dikelola dari sini (sumber Places API).');
        }
        $tok = $this->googleToken();
        $res = $this->http('DELETE', 'https://mybusiness.googleapis.com/v4/'.$r->nama_res.'/reply',
            ['Authorization' => 'Bearer '.$tok], null, 30);
        if ($res['kode'] !== 200 && $res['kode'] !== 204 && $res['kode'] !== 404) {
            throw new RuntimeException(self::googleError($res, 'Google menolak menghapus balasan'));
        }
        $this->db()->update("UPDATE cg_greview SET balasan = NULL, balasan_at = 0, balasan_oleh = '' WHERE id = ?", [$r->id]);

        return ['ok' => true];
    }

    /**
     * The "ditangani" flag — for internal follow-up (guest contacted,
     * kitchen issue fixed), separate from the public reply.
     *
     * @throws RuntimeException
     */
    public function googleFlag(array $d, string $by): array
    {
        $r = $this->googleFindReview((string) ($d['id'] ?? ''));
        $ya = ! empty($d['ditangani']);
        $this->db()->update('UPDATE cg_greview SET ditangani = ?, tangani_catatan = ?, tangani_oleh = ?, tangani_at = ? WHERE id = ?',
            [$ya ? 1 : 0, $ya ? self::cut($d['catatan'] ?? '', 1500) : null,
                $ya ? $by : '', $ya ? self::nowMs() : 0, $r->id]);

        return ['ok' => true];
    }

    /** @throws RuntimeException */
    public function googleAi(array $d, string $by): array
    {
        $this->ensureTables();
        $hari = max(7, min(365, (int) ($d['hari'] ?? 90)));
        $dari = (time() - $hari * 86400) * 1000;
        $tempat = isset($d['tempat']) ? (string) $d['tempat'] : '';
        $sql = 'SELECT g.id, g.penulis, g.rating, g.teks, g.waktu_ms, g.balasan, g.ditangani, t.nama tnama
            FROM cg_greview g LEFT JOIN cg_gtempat t ON t.id = g.tempat_id
            WHERE g.waktu_ms >= ?'.($tempat !== '' ? ' AND g.tempat_id = ?' : '').' ORDER BY g.waktu_ms DESC LIMIT 250';
        $baris = $this->db()->select($sql, $tempat !== '' ? [$dari, $tempat] : [$dari]);
        if (! $baris) {
            throw new RuntimeException('Belum ada review dalam '.$hari.' hari terakhir untuk dianalisa. Sinkronkan dulu, atau pilih rentang yang lebih panjang.');
        }
        /* Short ids (r1, r2, …) for the AI: cheaper on tokens, and invented
           ids are recognisable and dropped. */
        $peta = [];
        $data = [];
        foreach ($baris as $i => $b) {
            $k = 'r'.($i + 1);
            $peta[$k] = $b->id;
            $data[] = ['id' => $k, 'bintang' => (int) $b->rating,
                'tanggal' => $b->waktu_ms ? gmdate('Y-m-d', (int) ($b->waktu_ms / 1000) + 7 * 3600) : '',
                'tempat' => (string) $b->tnama, 'teks' => self::cut((string) $b->teks, 700),
                'sudahDibalas' => $b->balasan !== null && $b->balasan !== '',
                'sudahDitangani' => (int) $b->ditangani === 1];
        }
        $profil = $this->setting('profil', self::PROFIL_BAWAAN);
        $system = 'Kamu manajer pengalaman tamu (guest experience) untuk bisnis F&B di Indonesia. Jawab dalam Bahasa Indonesia. '
            .'Analisa HANYA dari review yang diberikan; jangan mengarang review, angka, atau kejadian. Setiap temuan wajib menyebut id review buktinya. '
            .'Balas HANYA dengan satu objek JSON, tanpa teks lain.';
        $prompt = "Profil bisnis: $profil\nReview Google ($hari hari terakhir, terbaru dulu):\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nBuat analisa dengan bentuk JSON persis berikut:\n"
            .'{"ringkasan":"3-4 kalimat kondisi umum","sentimen":{"positif":0,"netral":0,"negatif":0},'
            .'"kekuatan":[{"tema":"","penjelasan":"","bukti":["r1"]}],'
            .'"perbaikan":[{"masalah":"","area":"Makanan|Minuman|Pelayanan|Kecepatan|Kebersihan|Harga|Suasana|Fasilitas|Lainnya","jumlah":0,"prioritas":"tinggi|sedang|rendah","saran":"langkah konkret yang bisa dikerjakan tim","bukti":["r2"]}],'
            .'"tangani":[{"review":"r3","urgensi":"segera|tinggi|sedang","alasan":"kenapa perlu ditangani","tindakan":"tindak lanjut internal yang disarankan","usulanBalasan":"draf balasan publik yang sopan, minta maaf bila perlu, tanpa janji yang tidak bisa ditepati, maks 600 huruf"}]}'
            ."\nAturan: sentimen berisi JUMLAH review. 3-6 kekuatan, 3-8 perbaikan diurut prioritas. 'tangani' berisi review yang mengeluh/kecewa dan BELUM dibalas atau BELUM ditangani, "
            .'diurut paling mendesak (bintang rendah, keluhan berat seperti keracunan/kebersihan/perlakuan kasar/uang, review terbaru), maksimal 15. Jangan masukkan review yang sudah dibalas DAN sudah ditangani.';
        $r = $this->aiAsk($system, $prompt, 9000);
        /* Fresh connection after the AI wait (else "MySQL server has gone away"). */
        $this->freshDb();
        $h = $r['hasil'];
        $bersihBukti = function ($arr) use ($peta) {
            $o = [];
            if (is_array($arr)) {
                foreach ($arr as $x) {
                    if (isset($peta[(string) $x])) {
                        $o[] = $peta[(string) $x];
                    }
                }
            }

            return array_values(array_unique($o));
        };
        $out = ['ringkasan' => isset($h['ringkasan']) ? self::cut($h['ringkasan'], 1500) : '',
            'sentimen' => isset($h['sentimen']) && is_array($h['sentimen']) ? $h['sentimen'] : null,
            'kekuatan' => [], 'perbaikan' => [], 'tangani' => []];
        foreach ((isset($h['kekuatan']) && is_array($h['kekuatan']) ? $h['kekuatan'] : []) as $x) {
            if (! is_array($x)) {
                continue;
            }
            $out['kekuatan'][] = ['tema' => self::cut($x['tema'] ?? '', 200),
                'penjelasan' => self::cut($x['penjelasan'] ?? '', 600),
                'bukti' => $bersihBukti($x['bukti'] ?? [])];
        }
        foreach ((isset($h['perbaikan']) && is_array($h['perbaikan']) ? $h['perbaikan'] : []) as $x) {
            if (! is_array($x)) {
                continue;
            }
            $out['perbaikan'][] = ['masalah' => self::cut($x['masalah'] ?? '', 250),
                'area' => self::cut($x['area'] ?? '', 40),
                'jumlah' => (int) ($x['jumlah'] ?? 0),
                'prioritas' => in_array($x['prioritas'] ?? '', ['tinggi', 'sedang', 'rendah'], true) ? $x['prioritas'] : 'sedang',
                'saran' => self::cut($x['saran'] ?? '', 800), 'bukti' => $bersihBukti($x['bukti'] ?? [])];
        }
        /* "tangani" entries pointing at invented reviews are DROPPED — a
           draft for a review that does not exist is usable by nobody. */
        foreach ((isset($h['tangani']) && is_array($h['tangani']) ? $h['tangani'] : []) as $x) {
            if (! is_array($x) || ! isset($x['review'], $peta[(string) $x['review']])) {
                continue;
            }
            $out['tangani'][] = ['id' => $peta[(string) $x['review']],
                'urgensi' => in_array($x['urgensi'] ?? '', ['segera', 'tinggi', 'sedang'], true) ? $x['urgensi'] : 'sedang',
                'alasan' => self::cut($x['alasan'] ?? '', 500), 'tindakan' => self::cut($x['tindakan'] ?? '', 600),
                'usulanBalasan' => self::cut($x['usulanBalasan'] ?? '', 1200)];
        }
        $out['meta'] = ['at' => self::nowMs(), 'oleh' => $by, 'model' => $r['model'],
            'hari' => $hari, 'jumlah' => count($baris), 'tempat' => $tempat];
        $this->saveSetting('gr_ai', json_encode($out, JSON_UNESCAPED_UNICODE));

        return $out;
    }

    /**
     * A reply draft for ONE review (the "Buat draf AI" button in the reply
     * box). Never stored; a person still sends it.
     *
     * @throws RuntimeException
     */
    public function googleAiDraft(array $d): array
    {
        $this->ensureTables();
        $r = $this->googleFindReview((string) ($d['id'] ?? ''));
        $profil = $this->setting('profil', self::PROFIL_BAWAAN);
        $system = 'Kamu menulis balasan review Google atas nama bisnis F&B di Indonesia. Bahasa mengikuti bahasa review (default Indonesia), sopan, hangat, personal, '
            .'tidak defensif, tidak menjanjikan kompensasi yang tidak disebut, maksimal 600 huruf. Balas HANYA dengan JSON {"balasan":"..."}.';
        $prompt = "Profil bisnis: $profil\nNama tamu: ".$r->penulis."\nBintang: ".(int) $r->rating."\nReview: ".self::cut((string) $r->teks, 2000)
            .((isset($d['arahan']) && trim($d['arahan']) !== '') ? "\nArahan dari tim: ".self::cut($d['arahan'], 500) : '');
        $x = $this->aiAsk($system, $prompt, 1500);

        return ['balasan' => self::cut($x['hasil']['balasan'] ?? '', self::GR_MAKS_BALAS)];
    }

    public function googleDisconnect(string $by): array
    {
        $this->ensureTables();
        $now = self::nowMs();
        /* Tokens are emptied; pulled reviews & places STAY stored. */
        $this->db()->update("UPDATE cg_akun SET access_token='', refresh_token='', token_exp=0, refresh_exp=0, oleh=?, at=? WHERE platform=?",
            [$by, $now, 'google']);
        $this->log('Akun Google', '', 'diputus', $by);

        return ['ok' => true];
    }
}
