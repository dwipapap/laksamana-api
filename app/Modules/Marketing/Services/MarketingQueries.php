<?php

namespace App\Modules\Marketing\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Narrow reads used by OTHER modules (so they never pull the whole getAll):
 *   eventsHari  -> finance/omset Breakdown Sumber
 *   dpMasuk     -> reservasi Dana Masuk (tab DP Event)
 *   designReqs / designReq / designReqOpsi / designReqSet -> konten production queue
 * plus ping identity & stats. Ports of the matching functions in lib_marketing_mysql.php.
 */
class MarketingQueries
{
    public function __construct(
        private readonly MarketingState $state,
        private readonly MarketingFiles $files,
    ) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('marketing');
    }

    public function identity(): array
    {
        return [
            'env' => Modules::envLabel(),
            'db' => Modules::databaseName('marketing'),
            'versi' => MarketingSchema::LIB_VERSI,
            'aksi' => ['getAll', 'stats', 'ping', 'receipt', 'uploadReceipt', 'uploadChunk', 'saveAll'],
            'maksUnggahMB' => 40,
        ];
    }

    // ─────────────────────────── eventsHari ──

    /** Deal / Event Done events on $tgl (multi-day events included, 60-day window) + VIP of that day. */
    public function eventsOn(string $tgl): array
    {
        if (! RowSync::tanggal($tgl)) {
            throw new RuntimeException('tanggal tidak sah: '.$tgl);
        }
        $batas = date('Y-m-d', strtotime($tgl.' 00:00:00 -60 days'));
        $rows = $this->db()->select(
            "SELECT e.id, e.nama, e.tanggal, e.status, e.pax, e.mkt_pic, e.data, u.name AS pic_name
               FROM events e LEFT JOIN users u ON u.id = e.mkt_pic
              WHERE e.status IN ('Deal', 'Event Done') AND e.tanggal <= ? AND e.tanggal >= ?
              ORDER BY e.nama", [$tgl, $batas]);
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) ($r->data ?? ''), true);
            if (! is_array($d)) {
                $d = [];
            }
            $mulai = (string) $r->tanggal;
            $selesai = isset($d['tanggalSelesai']) ? (string) $d['tanggalSelesai'] : '';
            if ($mulai !== $tgl && ! ($selesai !== '' && $selesai >= $tgl)) {
                continue;
            }
            $hari = 1;
            $totalHari = 1;
            if ($selesai !== '' && $selesai > $mulai) {
                $hb = strtotime($mulai.' 00:00:00');
                $totalHari = (int) floor((strtotime($selesai.' 00:00:00') - $hb) / 86400) + 1;
                $hari = (int) floor((strtotime($tgl.' 00:00:00') - $hb) / 86400) + 1;
            }
            $out[] = [
                'id' => $r->id, 'nama' => $r->nama, 'tanggal' => $r->tanggal, 'status' => $r->status,
                'pax' => (int) $r->pax, 'picId' => $r->mkt_pic, 'picName' => $r->pic_name,
                'menuFix' => isset($d['menuFix']) ? (string) $d['menuFix'] : '',
                'selesai' => $selesai, 'hari' => $hari, 'totalHari' => $totalHari,
                'detail' => isset($d['detail']) && is_array($d['detail']) ? $d['detail'] : [],
            ];
        }
        $set = RowSync::settingsRows($this->db(), 'settings', '');

        return [
            'events' => $out,
            'vip' => $this->vipOn($tgl),
            'settings' => [
                'serviceCharge' => isset($set['serviceCharge']) ? (float) $set['serviceCharge'] : 5,
                'pb1' => isset($set['pb1']) ? (float) $set['pb1'] : 10,
            ],
        ];
    }

    /** Assisted, not-cancelled Reservasi VIP of one day. Nominal as INT (rupiah; see legacy comment). */
    public function vipOn(string $tgl): array
    {
        $pick = [];
        foreach (RowSync::settingsRows($this->db(), 'vip') as $v) {
            if (! is_array($v) || RowSync::tanggal($v['tanggal'] ?? '') !== $tgl || ! empty($v['batalAt'])
                || ($v['jenis'] ?? '') !== 'Assisted') {
                continue;
            }
            $pick[] = $v;
        }
        if (! $pick) {
            return [];
        }
        $userIds = $clientIds = [];
        foreach ($pick as $v) {
            if (! empty($v['mktPIC'])) {
                $userIds[(string) $v['mktPIC']] = 1;
            }
            if (! empty($v['clientId'])) {
                $clientIds[(string) $v['clientId']] = 1;
            }
        }
        $userName = $this->column('users', 'name', array_keys($userIds));
        $clientCo = $this->column('clients', 'perusahaan', array_keys($clientIds));
        $clientName = $this->column('clients', 'nama', array_keys($clientIds));
        $out = [];
        foreach ($pick as $v) {
            $cid = (string) ($v['clientId'] ?? '');
            $pt = ($cid !== '' && isset($clientCo[$cid]) && $clientCo[$cid] !== '') ? $clientCo[$cid]
                : (($cid !== '' && isset($clientName[$cid])) ? $clientName[$cid] : (isset($v['perusahaan']) ? (string) $v['perusahaan'] : ''));
            $pid = (string) ($v['mktPIC'] ?? '');
            $out[] = [
                'id' => $v['id'] ?? '',
                'nama' => $v['nama'] ?? '',
                'perusahaan' => $pt,
                'tanggal' => $tgl,
                'jam' => trim(implode(' - ', array_filter([(string) ($v['jamMulai'] ?? ''), (string) ($v['jamSelesai'] ?? '')]))),
                'pax' => (int) (! empty($v['paxMax']) ? $v['paxMax'] : ($v['paxMin'] ?? 0)),
                'meja' => isset($v['meja']) && is_array($v['meja']) ? array_values($v['meja']) : [],
                'nominal' => (int) round((float) ($v['nominal'] ?? 0)),
                'picId' => $pid,
                'picName' => ($pid !== '' && isset($userName[$pid])) ? $userName[$pid] : null,
                'menuFix' => isset($v['menuFix']) ? (string) $v['menuFix'] : '',
            ];
        }

        return $out;
    }

    private function column(string $table, string $col, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        foreach ($this->db()->select("SELECT id, `$col` AS v FROM `$table` WHERE id IN ($ph)", array_values($ids)) as $r) {
            $map[(string) $r->id] = (string) $r->v;
        }

        return $map;
    }

    // ─────────────────────────── dpMasuk ──

    /** VIP proof stamps: ms epoch (Marketing) or date string (restored from Reservasi); unreadable -> ''. */
    public static function capKeTanggal(mixed $v): string
    {
        if (is_numeric($v)) {
            $ms = (float) $v;
            if ($ms <= 0) {
                return '';
            }
            if ($ms > 100000000000) {
                $ms = $ms / 1000;
            }

            return gmdate('Y-m-d', (int) ($ms + 7 * 3600));
        }
        $s = substr((string) (is_scalar($v) ? $v : ''), 0, 10);

        return RowSync::tanggal($s) === null ? '' : $s;
    }

    /** Payments of events (filtered by EVENT date) + Reservasi VIP proofs, with the "outside range" summary. */
    public function dpIn(string $dari, string $sampai): array
    {
        if (! RowSync::tanggal($dari) || ! RowSync::tanggal($sampai)) {
            throw new RuntimeException('rentang tanggal tidak sah');
        }
        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $db = $this->db();
        $baris = [];
        $tanpaTanggal = 0;
        $luarN = 0;
        $luarRp = 0;
        $luarMin = '';
        $luarMax = '';

        $rows = $db->select(
            'SELECT e.id, e.nama, e.jenis, e.tanggal, e.status, e.data, c.nama AS client_nama, u.name AS pic_name
               FROM events e LEFT JOIN clients c ON c.id = e.client_id LEFT JOIN users u ON u.id = e.mkt_pic
              WHERE e.tanggal BETWEEN ? AND ? ORDER BY e.tanggal DESC', [$dari, $sampai]);
        foreach ($rows as $r) {
            $d = json_decode((string) ($r->data ?? ''), true);
            $pays = is_array($d) && isset($d['payments']) && is_array($d['payments']) ? $d['payments'] : [];
            foreach ($pays as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $at = isset($p['at']) ? substr((string) $p['at'], 0, 10) : '';
                if ($at === '') {
                    $tanpaTanggal++;
                }
                $baris[] = [
                    'sumber' => 'event', 'evId' => (string) $r->id, 'event' => (string) $r->nama,
                    'jenis' => (string) $r->jenis, 'tglEvent' => (string) $r->tanggal, 'status' => (string) $r->status,
                    'client' => (string) ($r->client_nama ?? ''), 'pic' => (string) ($r->pic_name ?? ''), 'resId' => '',
                    'id' => isset($p['id']) ? (string) $p['id'] : '', 'no' => isset($p['no']) ? (string) $p['no'] : '',
                    'type' => isset($p['type']) ? (string) $p['type'] : '',
                    'amount' => isset($p['amount']) ? (float) $p['amount'] : 0,
                    'method' => isset($p['method']) ? (string) $p['method'] : '', 'at' => $at,
                    'receipt' => isset($p['receipt']) ? (string) $p['receipt'] : '',
                    'receiptUrl' => isset($p['receiptUrl']) ? (string) $p['receiptUrl'] : '',
                ];
            }
        }

        $vipLocked = 0;
        $vipLockedRp = 0;
        $allVip = json_decode((string) ($db->selectOne("SELECT v FROM settings WHERE k = 'extra:vip'")->v ?? ''), true);
        if (is_array($allVip)) {
            foreach ($allVip as $v) {
                if (! is_array($v) || ! empty($v['batalAt'])) {
                    continue;
                }
                $tglV = (string) RowSync::tanggal($v['tanggal'] ?? '');
                if ($tglV === '' || $tglV < $dari || $tglV > $sampai) {
                    foreach (isset($v['bukti']) && is_array($v['bukti']) ? $v['bukti'] : [] as $b) {
                        if (! is_array($b)) {
                            continue;
                        }
                        $luarN++;
                        $luarRp += isset($b['nominal']) ? (float) $b['nominal'] : 0;
                        if ($tglV !== '') {
                            if ($luarMin === '' || $tglV < $luarMin) {
                                $luarMin = $tglV;
                            }
                            if ($tglV > $luarMax) {
                                $luarMax = $tglV;
                            }
                        }
                    }

                    continue;
                }
                $bukti = isset($v['bukti']) && is_array($v['bukti']) ? $v['bukti'] : [];
                if (! $bukti) {
                    continue;
                }
                $resId = isset($v['resId']) ? (string) $v['resId'] : '';
                foreach ($bukti as $i => $b) {
                    if (! is_array($b)) {
                        continue;
                    }
                    $key = isset($b['key']) ? (string) $b['key'] : '';
                    $nom = isset($b['nominal']) ? (float) $b['nominal'] : 0;
                    if ($resId !== '') {
                        $vipLocked++;
                        $vipLockedRp += $nom;
                    }
                    $baris[] = [
                        'sumber' => 'vip', 'evId' => 'vip-'.(isset($v['id']) ? (string) $v['id'] : ''),
                        'event' => 'Reservasi VIP · '.(isset($v['nama']) ? (string) $v['nama'] : '(tanpa nama)'),
                        'jenis' => isset($v['jenis']) ? (string) $v['jenis'] : '', 'tglEvent' => $tglV,
                        'status' => $resId !== '' ? 'Terkunci di Reservasi' : 'Belum dikunci',
                        'client' => '', 'pic' => '', 'resId' => $resId,
                        'id' => isset($b['id']) ? (string) $b['id'] : ('b'.$i), 'no' => '', 'type' => 'DP',
                        'amount' => $nom, 'method' => isset($b['metode']) ? (string) $b['metode'] : '',
                        'at' => self::capKeTanggal($b['at'] ?? null),
                        'receipt' => isset($b['name']) ? (string) $b['name'] : '',
                        'receiptUrl' => $key === '' ? '' : ('?action=receipt&key='.rawurlencode($key)),
                    ];
                    if (self::capKeTanggal($b['at'] ?? null) === '') {
                        $tanpaTanggal++;
                    }
                }
            }
        }

        foreach ($db->select('SELECT e.tanggal, e.data FROM events e WHERE e.tanggal NOT BETWEEN ? AND ?', [$dari, $sampai]) as $r) {
            $d = json_decode((string) ($r->data ?? ''), true);
            $pays = is_array($d) && isset($d['payments']) && is_array($d['payments']) ? $d['payments'] : [];
            $tgl = (string) $r->tanggal;
            foreach ($pays as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $luarN++;
                $luarRp += isset($p['amount']) ? (float) $p['amount'] : 0;
                if ($tgl !== '') {
                    if ($luarMin === '' || $tgl < $luarMin) {
                        $luarMin = $tgl;
                    }
                    if ($tgl > $luarMax) {
                        $luarMax = $tgl;
                    }
                }
            }
        }

        usort($baris, fn ($a, $b) => $a['tglEvent'] === $b['tglEvent'] ? strcmp($a['event'], $b['event']) : strcmp($b['tglEvent'], $a['tglEvent']));
        $total = 0;
        foreach ($baris as $b) {
            $total += $b['amount'];
        }

        return [
            'baris' => $baris, 'total' => $total, 'tanpaTanggal' => $tanpaTanggal,
            'vipTerkunci' => ['n' => $vipLocked, 'total' => $vipLockedRp],
            'luar' => ['n' => $luarN, 'total' => $luarRp, 'dari' => $luarMin, 'sampai' => $luarMax],
            'dari' => $dari, 'sampai' => $sampai,
        ];
    }

    // ─────────────────────────── design requests ──

    private function blob(string $k): array
    {
        $v = $this->db()->selectOne('SELECT v FROM settings WHERE k = ?', [$k]);
        $d = $v ? json_decode((string) $v->v, true) : null;

        return is_array($d) ? $d : [];
    }

    /** List without reference images; cancelled (batalAt) never included; $onlyActive hides done. */
    public function designRequests(bool $onlyActive): array
    {
        $prog = $this->blob('extra:designreqprog');
        $out = [];
        foreach ($this->blob('extra:designreqs') as $r) {
            if (! is_array($r) || empty($r['id']) || ! empty($r['batalAt'])) {
                continue;
            }
            $id = (string) $r['id'];
            $p = isset($prog[$id]) && is_array($prog[$id]) ? $prog[$id] : [];
            $st = (($p['status'] ?? '') === 'done') ? 'done' : 'todo';
            if ($onlyActive && $st === 'done') {
                continue;
            }
            $out[] = [
                'id' => $id, 'jenis' => (($r['jenis'] ?? '') === 'edit') ? 'edit' : 'design',
                'judul' => isset($r['judul']) ? (string) $r['judul'] : '', 'brief' => isset($r['brief']) ? (string) $r['brief'] : '',
                'acara' => isset($r['acara']) ? (string) $r['acara'] : '', 'deadline' => RowSync::tanggal($r['deadline'] ?? ''),
                'prioritas' => isset($r['prioritas']) ? (string) $r['prioritas'] : 'medium',
                'pemohon' => isset($r['byNama']) ? (string) $r['byNama'] : '', 'at' => isset($r['at']) ? (string) $r['at'] : '',
                'brand' => isset($r['brand']) ? (string) $r['brand'] : '', 'brandNama' => isset($r['brandNama']) ? (string) $r['brandNama'] : '',
                'platforms' => isset($r['platforms']) && is_array($r['platforms']) ? array_values($r['platforms']) : [],
                'picMinta' => isset($r['picNama']) ? (string) $r['picNama'] : '', 'picId' => isset($r['pic']) ? (string) $r['pic'] : '',
                'nRef' => isset($r['refs']) && is_array($r['refs']) ? count($r['refs']) : 0,
                'status' => $st, 'pic' => isset($p['picNama']) ? (string) $p['picNama'] : '', 'doneAt' => isset($p['at']) ? (string) $p['at'] : '',
            ];
        }

        return ['reqs' => $out, 'opsi' => $this->blob('extra:designreqopsi')];
    }

    /** One request INCLUDING reference images. */
    public function designRequest(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RuntimeException('id permintaan kosong');
        }
        foreach ($this->blob('extra:designreqs') as $r) {
            if (! is_array($r) || (string) ($r['id'] ?? '') !== $id) {
                continue;
            }
            $prog = $this->blob('extra:designreqprog');
            $p = isset($prog[$id]) && is_array($prog[$id]) ? $prog[$id] : [];
            $r['status'] = (($p['status'] ?? '') === 'done') ? 'done' : 'todo';
            $r['picDone'] = isset($p['picNama']) ? (string) $p['picNama'] : '';

            return ['req' => $r];
        }

        return ['req' => null];
    }

    /** Konten mirrors its brand/crew/platform lists here. An empty push never wipes the stored list. */
    public function setDesignOptions(mixed $opsi): array
    {
        return NamedLock::run('marketing', 'mkt_save', function () use ($opsi) {
            if (! is_array($opsi)) {
                throw new RuntimeException('opsi kosong/invalid');
            }
            $clean = ['brands' => [], 'pics' => [], 'platforms' => [], 'at' => gmdate('c')];
            foreach (is_array($opsi['brands'] ?? null) ? $opsi['brands'] : [] as $b) {
                if (is_array($b) && ! empty($b['id'])) {
                    $clean['brands'][] = ['id' => (string) $b['id'], 'name' => isset($b['name']) ? (string) $b['name'] : ''];
                }
            }
            foreach (is_array($opsi['pics'] ?? null) ? $opsi['pics'] : [] as $u) {
                if (is_array($u) && ! empty($u['id'])) {
                    $clean['pics'][] = ['id' => (string) $u['id'], 'name' => isset($u['name']) ? (string) $u['name'] : '',
                        'peran' => isset($u['peran']) ? (string) $u['peran'] : '', 'prod' => ! empty($u['prod']) ? 1 : 0];
                }
            }
            foreach (is_array($opsi['platforms'] ?? null) ? $opsi['platforms'] : [] as $p) {
                if (is_string($p) && $p !== '') {
                    $clean['platforms'][] = $p;
                }
            }
            if (! $clean['brands'] && ! $clean['pics'] && ! $clean['platforms']) {
                return ['disimpan' => false, 'sebab' => 'titipan kosong diabaikan'];
            }
            RowSync::putSetting($this->db(), 'extra:designreqopsi', $clean);

            return ['disimpan' => true, 'brands' => count($clean['brands']), 'pics' => count($clean['pics']), 'platforms' => count($clean['platforms'])];
        });
    }

    /** Konten marks one request done / reopened. Touches ONLY designreqprog. */
    public function setDesignStatus(mixed $id, mixed $status, mixed $picNama): array
    {
        return NamedLock::run('marketing', 'mkt_save', function () use ($id, $status, $picNama) {
            $id = trim((string) $id);
            if ($id === '') {
                throw new RuntimeException('id permintaan kosong');
            }
            $status = $status === 'done' ? 'done' : 'todo';
            $prog = $this->blob('extra:designreqprog');
            $prog[$id] = ['status' => $status, 'picNama' => (string) $picNama, 'at' => gmdate('c')];
            RowSync::putSetting($this->db(), 'extra:designreqprog', $prog);

            return ['id' => $id, 'status' => $status];
        });
    }

    // ─────────────────────────── diagnostics ──

    public function stats(): array
    {
        $out = array_merge(['backend' => 'laravel'], $this->identity());
        foreach (MarketingSchema::statsTables() as $t) {
            $out[$t] = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `$t`")->c;
        }
        $blob = strlen(RowSync::enc($this->state->read()));
        $out['blobChars'] = $blob;
        $out['blobMB'] = round($blob / 1048576, 3);
        $out = array_merge($out, $this->files->diskStats());
        $out['ts'] = gmdate('c');

        return $out;
    }
}
