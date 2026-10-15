<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Akademi\Services\AkademiRecords;
use App\Modules\Hris\Models\HrisOnboarding;
use App\Modules\Hris\Models\HrisOnboardingLangkah;
use App\Modules\Hris\Models\HrisOnboardingTemplate;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Support\Carbon;

/**
 * Onboarding (O1): an HRIS checklist per newcomer for the non-learning
 * steps (contract, uniform, accounts, buddy, 30/60/90-day reviews) plus a
 * link to the Akademi program(s) of their Divisi.
 *
 * Learning materials are NEVER copied here: the onboarding stores the
 * program id and reads its title/deadline through AkademiRecords, and the
 * crew completes it in Akademi (progress already feeds People Score).
 * Auto-assignment of program progress rows is deliberately NOT done — no
 * invented row conventions in Akademi's store (see hris.md O1).
 */
final class HrisOnboardingService
{
    /** Fallback steps when the division has no template (O1). */
    public const LANGKAH_BAWAAN = [
        ['kunci' => 'kontrak', 'judul' => 'Tanda tangan kontrak'],
        ['kunci' => 'seragam', 'judul' => 'Serahkan seragam'],
        ['kunci' => 'akun', 'judul' => 'Buatkan akun (Office + HRIS)'],
        ['kunci' => 'buddy', 'judul' => 'Tunjuk buddy'],
        ['kunci' => 'eval-30', 'judul' => 'Review 30 hari', 'tenggat_hari' => 30],
        ['kunci' => 'eval-60', 'judul' => 'Review 60 hari', 'tenggat_hari' => 60],
        ['kunci' => 'eval-90', 'judul' => 'Review 90 hari', 'tenggat_hari' => 90],
    ];

    public function __construct(
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
        private readonly AkademiRecords $akademi,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ---------------------------------------------------------------- templates

    /** @return array<int,array{divisi:string,langkah:array,program_akademi_id:string|null}> */
    public function daftarTemplate(): array
    {
        $out = [];
        foreach (HrisOnboardingTemplate::query()->orderBy('divisi')->get() as $t) {
            $out[] = ['id' => $t->id, 'divisi' => $t->divisi, 'langkah' => $t->langkah ?? [],
                'program_akademi_id' => $t->program_akademi_id, 'berubah' => (int) $t->version];
        }

        return $out;
    }

    public function simpanTemplate(array $in, string $by): HrisOnboardingTemplate
    {
        $divisi = self::s($in['divisi'] ?? '');
        if ($divisi === '') {
            throw new HrisError('validation_failed', 'Template butuh divisi.', 422);
        }
        $langkah = self::bersihLangkah($in['langkah'] ?? null);
        $program = self::s($in['program_akademi_id'] ?? '');
        if ($program !== '') {
            $this->programAtauGagal($program);
        }
        $t = HrisOnboardingTemplate::query()->updateOrCreate(['divisi' => mb_substr($divisi, 0, 64)],
            ['langkah' => $langkah, 'program_akademi_id' => $program === '' ? null : $program]);
        $this->audit->record($by, 'onboarding_template_disimpan', 'hris_onboarding_template', $t->id,
            ['divisi' => $divisi]);

        return $t->fresh();
    }

    /** @return array<int,array{kunci:string,judul:string,tenggat_hari:int|null}> */
    public static function bersihLangkah(mixed $langkah): array
    {
        if (! is_array($langkah) || $langkah === []) {
            throw new HrisError('validation_failed', 'Template butuh 1–20 langkah.', 422);
        }
        if (count($langkah) > 20) {
            throw new HrisError('validation_failed', 'Template maksimal 20 langkah.', 422);
        }
        $out = [];
        $kunci = [];
        foreach ($langkah as $l) {
            $l = is_array($l) ? $l : [];
            $k = mb_substr(self::s($l['kunci'] ?? ''), 0, 64);
            $judul = mb_substr(self::s($l['judul'] ?? ''), 0, 190);
            if ($k === '' || $judul === '' || isset($kunci[$k])) {
                throw new HrisError('validation_failed', 'Setiap langkah butuh kunci + judul yang unik.', 422);
            }
            $kunci[$k] = true;
            $tenggat = $l['tenggat_hari'] ?? null;
            $out[] = ['kunci' => $k, 'judul' => $judul,
                'tenggat_hari' => $tenggat === null ? null : max(1, min(365, (int) $tenggat))];
        }

        return $out;
    }

    // ---------------------------------------------------------------- start

    /**
     * Start onboarding for one user (HR): steps from the division template
     * (or the O1 fallback), deadlines from mulai, Akademi program linked.
     */
    public function mulai(array $me, string $userId, string $mulai, string $divisi): HrisOnboarding
    {
        $userId = self::s($userId);
        $mulai = PunchRules::tglValid($mulai);
        $divisi = mb_substr(self::s($divisi), 0, 64);
        if ($userId === '' || $mulai === '') {
            throw new HrisError('validation_failed', 'Onboarding butuh user dan tanggal mulai YYYY-MM-DD.', 422);
        }
        if (HrisOnboarding::query()->where('user_id', $userId)->exists()) {
            throw new HrisError('sudah_ada', 'User ini sudah punya onboarding.', 409);
        }
        $t = $divisi !== '' ? HrisOnboardingTemplate::query()->where('divisi', $divisi)->first() : null;
        $langkah = $t ? ($t->langkah ?? []) : self::LANGKAH_BAWAAN;
        if ($langkah === []) {
            $langkah = self::LANGKAH_BAWAAN;
        }
        $program = $t?->program_akademi_id;
        $row = HrisOnboarding::query()->create([
            'user_id' => $userId, 'user_ulid' => null, 'divisi' => $divisi, 'mulai' => $mulai,
            'status' => 'AKTIF', 'program_akademi_id' => $program,
            'dibuat_oleh' => mb_substr(self::s($me['name'] ?? ''), 0, 120),
        ]);
        foreach ($langkah as $l) {
            $tenggat = null;
            if (isset($l['tenggat_hari']) && $l['tenggat_hari'] !== null) {
                $tenggat = Carbon::parse($mulai, 'Asia/Jakarta')->addDays((int) $l['tenggat_hari'])->format('Y-m-d');
            }
            HrisOnboardingLangkah::query()->create(['onboarding_id' => $row->id,
                'kunci' => $l['kunci'], 'judul' => $l['judul'], 'tenggat' => $tenggat]);
        }
        $this->audit->record(self::s($me['name'] ?? ''), 'onboarding_dimulai', 'hris_onboarding', $row->id,
            ['user_id' => $userId, 'divisi' => $divisi]);

        return $row->fresh();
    }

    // ---------------------------------------------------------------- read

    /** @param  array<int,string>|null  $userIds */
    public function daftar(?array $userIds): array
    {
        $q = HrisOnboarding::query()->orderByDesc('created_at');
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        $out = [];
        foreach ($q->limit(200)->get() as $r) {
            $out[] = $this->bentuk($r);
        }

        return $out;
    }

    public function bentuk(HrisOnboarding $r): array
    {
        $langkah = [];
        foreach (HrisOnboardingLangkah::query()->where('onboarding_id', $r->id)->orderBy('created_at')->get() as $l) {
            $langkah[] = ['kunci' => $l->kunci, 'judul' => $l->judul,
                'tenggat' => $l->tenggat?->format('Y-m-d'), 'selesai' => (bool) $l->selesai,
                'selesai_oleh' => $l->selesai_oleh, 'selesai_at' => (int) $l->selesai_at,
                'catatan' => $l->catatan];
        }
        $selesai = count(array_filter($langkah, fn ($l) => $l['selesai']));

        return [
            'id' => $r->id, 'user_id' => $r->user_id, 'divisi' => $r->divisi,
            'mulai' => $r->mulai->format('Y-m-d'), 'status' => $r->status,
            'program' => $this->program($r->program_akademi_id),
            'langkah' => $langkah, 'maju' => [$selesai, count($langkah)],
            'berubah' => (int) $r->version,
        ];
    }

    /** Akademi program snapshot (read-only link target), or null. */
    public function program(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        try {
            $found = $this->akademi->find('programs', $id);
        } catch (\Throwable) {
            return null;
        }
        if (! $found) {
            return null;
        }
        $row = $found['row'];

        return ['id' => (string) ($row['id'] ?? $id), 'judul' => (string) ($row['title'] ?? $row['judul'] ?? ''),
            'bulan' => (string) ($row['bulan'] ?? ''),
            'tenggat' => (string) ($row['deadline'] ?? '')];
    }

    private function programAtauGagal(string $id): void
    {
        try {
            $found = $this->akademi->find('programs', $id);
        } catch (\Throwable) {
            $found = null;
        }
        if (! $found) {
            throw new HrisError('validation_failed', 'Program Akademi tidak dikenal.', 422);
        }
    }

    // ---------------------------------------------------------------- steps

    /**
     * Tick a step: the newcomer or HR for regular steps; eval-* (reviews)
     * take HR or the head of the division.
     */
    public function selesaikanLangkah(array $me, HrisOnboardingLangkah $l, bool $selesai, string $catatan): HrisOnboardingLangkah
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $ob = HrisOnboarding::query()->find($l->onboarding_id);
        if (! $ob || $ob->status !== 'AKTIF') {
            throw new HrisError('validation_failed', 'Onboarding ini tidak aktif.', 422);
        }
        $eval = str_starts_with($l->kunci, 'eval-');
        if ($eval) {
            $ok = $this->lingkup->isHr($me);
            if (! $ok && $this->lingkup->isHead($me)) {
                try {
                    $div = $this->jadwal->divisiUser($ob->user_id);
                } catch (\Throwable) {
                    $div = '';
                }
                $ok = in_array($div, $this->lingkup->headDivisi($me), true);
            }
            if (! $ok) {
                throw new HrisError('forbidden', 'Review onboarding oleh HR atau Kepala Divisi.', 403);
            }
        } elseif ($ob->user_id !== $uid && ! $this->lingkup->isHr($me)) {
            throw new HrisError('forbidden', 'Langkah ini oleh yang bersangkutan atau HR.', 403);
        }
        $l->fill(['selesai' => $selesai,
            'selesai_oleh' => $selesai ? mb_substr($nama, 0, 120) : '',
            'selesai_at' => $selesai ? PunchRules::ms() : 0,
            'catatan' => mb_substr(self::s($catatan), 0, 255)])->save();
        $this->audit->record($nama, 'onboarding_langkah', 'hris_onboarding', $ob->id,
            ['kunci' => $l->kunci, 'selesai' => $selesai]);

        return $l->fresh();
    }

    /** Overdue steps for the reminder run (eval + dated steps past due). */
    public function langkahJatuhTempo(string $today): array
    {
        $out = [];
        foreach (HrisOnboardingLangkah::query()->where('selesai', false)
            ->whereNotNull('tenggat')->where('tenggat', '<', $today)->get() as $l) {
            $ob = HrisOnboarding::query()->find($l->onboarding_id);
            if ($ob && $ob->status === 'AKTIF') {
                $out[] = ['user_id' => $ob->user_id, 'onboarding_id' => $ob->id,
                    'kunci' => $l->kunci, 'judul' => $l->judul,
                    'tenggat' => $l->tenggat->format('Y-m-d')];
            }
        }

        return $out;
    }
}
