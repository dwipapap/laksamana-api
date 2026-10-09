<?php

declare(strict_types=1);

namespace App\Erp\Proyek\Imports;

use App\Core\Imports\Importer;
use App\Core\Imports\ReportsIssues;
use App\Core\Models\LogAktivitas;
use App\Core\Models\Notifikasi;
use App\Erp\Master\Imports\OrangImporter;
use App\Erp\Master\Imports\PencocokUser;
use App\Erp\Proyek\Models\Proyek;
use App\Erp\Proyek\Models\TugasTim;
use App\Erp\Proyek\Models\TugasTimPic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ERP v2 Kerja Tim (docs/erp/kerja-tim.md "Pemetaan lama → v2"): BD tasks,
 * routines, coordination requests and agenda, plus the per-module activity
 * logs and notifications into the one shared log_aktivitas / notifikasi.
 * Run after `account`, `erp-orang` and `erp-po-proyek`. Idempotent.
 */
final class KerjaTimImporter implements Importer, ReportsIssues
{
    private const STATUS = ['Backlog' => 'backlog', 'To Do' => 'to_do', 'Doing' => 'doing', 'Waiting' => 'waiting', 'Review' => 'review', 'Done' => 'done'];

    private const KOORDINASI = ['Diminta' => 'diminta', 'Diproses' => 'diproses', 'Review' => 'review', 'Selesai' => 'selesai'];

    /** @var array<string, list<string>> */
    private array $issues = [];

    /** @var array<string, string> modul kunci => id */
    private array $modul = [];

    public function __construct(private readonly BdPeople $people, private readonly PencocokUser $users) {}

    public function module(): string
    {
        return 'erp-kerja-tim';
    }

    public function legacyConnections(): array
    {
        return ['legacy_bd', 'legacy_marketing', 'legacy_konten', 'legacy_akademi', 'legacy_reservasi', 'legacy_hr', 'legacy_stock'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function issues(): array
    {
        return $this->issues;
    }

    public function import(): int
    {
        $this->issues = [];
        $this->people->reset();
        $this->users->reset();
        $count = 0;

        DB::connection('core')->transaction(function () use (&$count): void {
            $this->modul = DB::connection('core')->table('modul')->pluck('id', 'kunci')->all();
            $divisi = DB::connection('core')->table('divisi')->pluck('id', 'kode')->all();
            $count += $this->importTugas($divisi);
            $count += $this->importKoordinasiDll($divisi);
            $count += $this->importLog();
            $count += $this->importNotifikasi();
        });

        return $count;
    }

    /** @param array<string, string> $divisi */
    private function importTugas(array $divisi): int
    {
        $n = 0;
        foreach (DB::connection('legacy_bd')->table('tasks')->orderBy('id')->get() as $t) {
            $d = $this->json($t->data);
            $div = trim((string) ($d['div'] ?? ''));
            $kode = OrangImporter::PETA_DIVISI[mb_strtolower($div)] ?? null;
            $divId = $kode && $kode !== '*' ? ($divisi[$kode] ?? null) : null;
            if ($div !== '' && ! $divId) {
                $this->issue('divisi_tidak_dikenal', "tugas {$t->id}: {$div}");
            }
            $status = self::STATUS[(string) ($d['status'] ?? 'To Do')] ?? null;
            if (! $status) {
                $this->issue('status_tugas_tidak_dikenal', "{$t->id}: ".($d['status'] ?? ''));
                $status = 'to_do';
            }
            $prio = strtolower((string) ($d['priority'] ?? 'Medium'));
            $row = $this->upsert(TugasTim::withTrashed()->firstOrNew(['legacy_id' => (string) $t->id]), [
                'judul' => mb_substr(trim((string) ($d['name'] ?? '')) ?: '-', 0, 255),
                'deskripsi' => $this->text($d['desc'] ?? null, 65000),
                'divisi_id' => $divId, 'divisi_impor' => $divId || $div === '' ? null : mb_substr($div, 0, 64),
                'proyek_id' => ($d['project'] ?? '') !== '' ? Proyek::withTrashed()->where('legacy_id', (string) $d['project'])->value('id') : null,
                'jenis' => $this->text($d['type'] ?? null, 40), 'status' => $status,
                'prioritas' => in_array($prio, ['urgent', 'high', 'medium', 'low'], true) ? $prio : 'medium',
                'penting' => ! empty($d['important']), 'mendesak' => ! empty($d['urgent']),
                'tenggat' => $this->tanggal($d['deadline'] ?? null),
                'progres' => max(0, min(100, (int) ($d['progress'] ?? 0))),
            ]);
            $pics = array_values(array_unique(array_filter(array_map('strval', (array) ($d['pics'] ?? [])) ?: [(string) ($d['pic'] ?? '')])));
            $keep = [];
            foreach ($pics as $i => $pid) {
                [$user, $impor] = $this->people->user($pid);
                if (! $user) {
                    $this->issue('pic_tanpa_user', "tugas {$t->id}: {$impor}");

                    continue;
                }
                $keep[] = $user;
                $this->upsert(TugasTimPic::firstOrNew(['tugas_tim_id' => $row->id, 'user_id' => $user]), ['urutan' => $i]);
            }
            TugasTimPic::where('tugas_tim_id', $row->id)->whereNotIn('user_id', $keep ?: ['-'])->delete();
            $n++;
        }

        return $n;
    }

    /** BD routines, coordination requests and agenda: empty in production; counted and reported if any. */
    private function importKoordinasiDll(array $divisi): int
    {
        $bd = DB::connection('legacy_bd');
        $sisa = $bd->table('routines')->count() + $bd->table('coord_requests')->count() + $bd->table('agenda')->count();
        if ($sisa > 0) {
            $this->issue('belum_diimpor', "{$sisa} baris rutinitas/koordinasi/agenda BD: importernya ditulis saat datanya ada");
        }

        return 0;
    }

    private function importLog(): int
    {
        $n = 0;
        $sumber = [
            ['marketing', 'legacy_marketing', 'activities', fn ($r, $d) => [$r->by_user, null, $r->action, $r->ref_type, $r->ref_id, $r->at_time, $d['detail'] ?? null]],
            ['konten', 'legacy_konten', 'logs', fn ($r, $d) => [$r->by_user, null, $r->action, $r->ref_id ? 'konten' : null, $r->ref_id, $r->at_ms, $d['target'] ?? null]],
            ['akademi', 'legacy_akademi', 'activity', fn ($r, $d) => [$r->user_id, $r->user_id, $r->action, null, null, $r->ts, $d['detail'] ?? null]],
            ['reservasi', 'legacy_reservasi', 'audit', fn ($r, $d) => [$d['user'] ?? null, null, $d['action'] ?? '-', null, null, $r->ts, ['detail' => $d['detail'] ?? null, 'role' => $d['role'] ?? null]]],
            ['hr', 'legacy_hr', 'audit', fn ($r, $d) => [$r->user_name, $r->user_id, $r->action, null, null, $r->at, $r->detail]],
            ['purchasing', 'legacy_stock', 'activity_log', fn ($r, $d) => [$r->aktor, null, $r->aksi, 'stock', $d['batchId'] ?? null, $r->waktu ?: $r->tanggal, ['ringkas' => $r->ringkas, 'modul' => $r->modul, 'tim' => $r->tim]]],
        ];
        foreach ($sumber as [$modul, $conn, $table, $peta]) {
            $modulId = $this->modul[$modul] ?? null;
            if (! $modulId) {
                $this->issue('modul_tidak_ada', $modul);

                continue;
            }
            foreach (DB::connection($conn)->table($table)->orderBy('id')->cursor() as $r) {
                $d = isset($r->data) ? $this->json($r->data) : [];
                [$nama, $officeId, $aksi, $jenis, $kunci, $waktu, $detail] = $peta($r, $d);
                $at = $this->waktu($waktu);
                if (! $at) {
                    $this->issue('log_tanpa_waktu', "{$modul}:{$r->id}");

                    continue;
                }
                $user = $this->users->cocok((string) $officeId, (string) $nama);
                $this->upsert(LogAktivitas::firstOrNew(['legacy_id' => mb_substr("{$modul}:{$r->id}", 0, 64)]), [
                    'modul_id' => $modulId, 'user_id' => $user,
                    'user_impor' => $user || trim((string) $nama) === '' ? null : mb_substr(trim((string) $nama), 0, 120),
                    'aksi' => mb_substr(trim((string) $aksi) ?: '-', 0, 80),
                    'objek_jenis' => $jenis ? mb_substr((string) $jenis, 0, 40) : null,
                    'objek_kunci' => $kunci !== null && $kunci !== '' ? mb_substr((string) $kunci, 0, 64) : null,
                    'detail' => $detail === null || $detail === '' ? null : (is_array($detail) ? $detail : ['teks' => (string) $detail]),
                    'terjadi_at' => $at,
                ]);
                $n++;
            }
        }

        return $n;
    }

    private function importNotifikasi(): int
    {
        $n = 0;
        $tanpaPenerima = DB::connection('legacy_marketing')->table('notifs')->count();
        if ($tanpaPenerima > 0) {
            $this->issue('notifikasi_tanpa_penerima', "{$tanpaPenerima} notifikasi Marketing tidak menyimpan penerima; tidak diimpor");
        }
        $modulId = $this->modul['konten'] ?? null;
        $siaran = DB::connection('legacy_konten')->table('notifs')->where('for_user', 'all')->count();
        if ($siaran > 0) {
            // "all" = an announcement to every crew member; notifikasi is per recipient with its own read time
            $this->issue('notifikasi_siaran', "{$siaran} pengumuman Konten untuk semua kru (for_user = all): tidak diimpor");
        }
        foreach (DB::connection('legacy_konten')->table('notifs')->where('for_user', '<>', 'all')->orderBy('id')->get() as $r) {
            $d = $this->json($r->data);
            $user = $this->users->cocok((string) $r->for_user, (string) ($d['to'] ?? ''));
            if (! $user || ! $modulId) {
                $this->issue('notifikasi_penerima_tidak_dikenal', "konten:{$r->id}");

                continue;
            }
            $teks = trim((string) ($d['text'] ?? ''));
            $this->upsert(Notifikasi::firstOrNew(['legacy_id' => mb_substr("konten:{$r->id}", 0, 64)]), [
                'user_id' => $user, 'modul_id' => $modulId, 'jenis' => $this->text($r->kind ?? ($d['type'] ?? null), 40),
                'judul' => mb_substr($teks !== '' ? $teks : '-', 0, 190), 'isi' => mb_strlen($teks) > 190 ? $teks : null,
                'dibaca_at' => ! empty($r->seen) || ! empty($d['read']) ? $this->waktu($r->updated_at ?: $r->at_ms) : null,
                'created_at' => $this->waktu($r->at_ms),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @param  array<string, mixed>  $values
     * @return T
     */
    private function upsert(Model $row, array $values): Model
    {
        $row->fill($values);
        if (! $row->exists || $row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    private function tanggal(mixed $v): ?string
    {
        $v = substr(trim((string) $v), 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    /** Legacy instants: epoch ms / s, or a date or date-time string (WIB when no zone). */
    private function waktu(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            return null;
        }
        if (is_numeric($v)) {
            $x = (float) $v;

            return $x > 1e11 ? Carbon::createFromTimestampMs((int) $x, 'UTC')->format('Y-m-d H:i:s')
                : ($x > 1e8 ? Carbon::createFromTimestamp((int) $x, 'UTC')->format('Y-m-d H:i:s') : null);
        }
        try {
            return Carbon::parse((string) $v, 'Asia/Jakarta')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** @return array<string, mixed> */
    private function json(?string $raw): array
    {
        $d = json_decode((string) $raw, true);

        return is_array($d) ? $d : [];
    }

    private function issue(string $kind, string $line): void
    {
        $this->issues[$kind][] = $line;
    }
}
