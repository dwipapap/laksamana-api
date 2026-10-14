<?php

namespace App\Modules\Konten\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use Illuminate\Database\ConnectionInterface;
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

    /** CREATE TABLE IF NOT EXISTS for the cg_* tables (same shape as cg_pastikan). */
    public function ensureTables(): void
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
        $db->statement("CREATE TABLE IF NOT EXISTS cg_post (platform VARCHAR(20), post_id VARCHAR(80), judul TEXT, url VARCHAR(600),
            cover VARCHAR(900), tayang_at BIGINT, views BIGINT, likes BIGINT, comments BIGINT, shares BIGINT, durasi INT,
            sync_at BIGINT, hilang TINYINT DEFAULT 0, reach BIGINT NULL, saves BIGINT NULL, insight_at BIGINT,
            PRIMARY KEY (platform, post_id))");
        $db->statement("CREATE TABLE IF NOT EXISTS cg_akun (platform VARCHAR(20) PRIMARY KEY, nama VARCHAR(160), avatar VARCHAR(900),
            follower BIGINT, open_id VARCHAR(120), access_token TEXT, refresh_token TEXT, token_exp BIGINT, refresh_exp BIGINT,
            scope VARCHAR(255), sync_at BIGINT, sync_galat VARCHAR(400), oleh VARCHAR(120), at BIGINT,
            bio TEXT, profil_url VARCHAR(400), terverifikasi TINYINT DEFAULT 0,
            following BIGINT DEFAULT 0, likes_total BIGINT DEFAULT 0, jml_video BIGINT DEFAULT 0, stats_ada TINYINT DEFAULT 0)");
        $db->statement("CREATE TABLE IF NOT EXISTS cg_bank (id VARCHAR(40) PRIMARY KEY, judul VARCHAR(200), platform VARCHAR(20),
            format VARCHAR(80), pilar VARCHAR(80), hook TEXT, caption TEXT, tanggal DATE NULL, jam VARCHAR(5), alasan TEXT,
            sumber VARCHAR(20) DEFAULT 'manual', batch VARCHAR(40), status VARCHAR(20) DEFAULT 'IDE', jadwal_id VARCHAR(40),
            dibuat_oleh VARCHAR(120), dibuat_at BIGINT, diubah_oleh VARCHAR(120), diubah_at BIGINT,
            KEY tanggal (tanggal))");
        $db->statement('CREATE TABLE IF NOT EXISTS cg_setting (k VARCHAR(60) PRIMARY KEY, v TEXT)');
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

    /** Full read: insight, jadwal, riset (30, decoded), bank, postInfo, akun (no tokens), setting summary. */
    public function state(bool $isAdmin): array
    {
        $this->ensureTables();
        $db = $this->db();
        $insight = $db->select('SELECT * FROM cg_insight ORDER BY (tgl_tayang IS NULL), tgl_tayang DESC, dibuat_at DESC LIMIT 1000');
        $jadwal = $db->select('SELECT * FROM cg_jadwal ORDER BY tanggal DESC, jam DESC LIMIT 1500');
        $riset = $db->select('SELECT id, jenis, topik, platform, catatan, hasil, model, oleh, at FROM cg_riset ORDER BY at DESC LIMIT 30');
        $bank = $db->select('SELECT * FROM cg_bank ORDER BY (tanggal IS NULL), tanggal ASC, dibuat_at DESC LIMIT 1000');
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
            'setting' => [
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
            ],
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
            $id = trim((string) ($d['id'] ?? ''));
            $db = $this->db();
            if ($id !== '') {
                $cur = $this->findInsight($id);
                if (! $cur) {
                    throw new RuntimeException('not_found:Insight yang disunting tidak ditemukan.');
                }
                if ($baseVersion !== null) {
                    $this->checkVersion($cur, $baseVersion);
                    if ($this->sameInsight($cur, $nilai)) {
                        return ['id' => $id, 'unchanged' => true];
                    }
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
            $id = trim((string) ($d['id'] ?? ''));
            $db = $this->db();
            if ($id !== '') {
                $cur = $this->findSchedule($id);
                if (! $cur) {
                    throw new RuntimeException('not_found:Jadwal yang disunting tidak ditemukan.');
                }
                if ($baseVersion !== null) {
                    $this->checkVersion($cur, $baseVersion);
                    if ($this->sameSchedule($cur, $nilai)) {
                        return ['id' => $id, 'unchanged' => true];
                    }
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
            $nilai = self::bankValues($d);
            if ($nilai['judul'] === '') {
                throw new RuntimeException('Judul ide wajib diisi.');
            }
            $now = self::nowMs();
            $id = trim((string) ($d['id'] ?? ''));
            $db = $this->db();
            if ($id !== '') {
                $cur = $this->findBank($id);
                if (! $cur) {
                    throw new RuntimeException('not_found:Ide yang disunting tidak ditemukan.');
                }
                if ($baseVersion !== null) {
                    $this->checkVersion($cur, $baseVersion);
                    if ($this->sameBank($cur, $nilai)) {
                        return ['id' => $id, 'unchanged' => true];
                    }
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

        return $this->tiktokSync();
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
                for ($h = 0; ! $selesai && $h < 5; $h++) {
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

        return $this->igSync();
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
                for ($h = 0; ! $selesai && $h < 3; $h++) {
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
        foreach (['aiKeyMeta' => 'ai_key_meta', 'tiktokKey' => 'tiktok_key', 'tiktokSecret' => 'tiktok_secret', 'igId' => 'ig_id', 'igSecret' => 'ig_secret'] as $f => $k) {
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

        return ['ok' => true];
    }
}
