<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Services\UsulanService;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepExecutorStatus;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\SDM\Models\Karyawan;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Pelaksana langkah produksi diganti di tengah jalan. Dua jalur:
 *
 * 1. Karyawan dinonaktifkan (keluar) → lepasKaryawan(): langsung, tanpa persetujuan —
 *    menekan Nonaktif sudah keputusannya. Langkah yang dipegangnya dilepas; yang tak
 *    punya pelaksana lagi dijeda jadi "Tanpa pelaksana" dan bisa dilanjutkan siapa saja
 *    lewat lanjutkan().
 * 2. Oper tugas saat pelaksana masih aktif → ajukan(): jadi usulan skill Manager Produksi
 *    "Ganti pelaksana langkah" yang dikirim ke Telegram; setelah disetujui owner, ganti()
 *    yang mengerjakan. Selama menunggu, langkah tetap jalan dengan pelaksana lama.
 *
 * Kedua jalur mencoret LANGKAH itu dari sampel Analisa › Waktu Produksi (kolom
 * sample_excluded_*, atas nama Manager Produksi): waktunya bukan satu orang mengerjakan
 * dari awal. Divisi lain di OP yang sama tetap terpakai.
 */
class PergantianPelaksanaService
{
    public const SKILL = 'produksi.ganti-pelaksana';

    /** Langkah yang boleh diganti pelaksananya — yang belum mulai tak perlu (pilih saat Mulai). */
    private const STATUS_BERJALAN = ['in_progress', 'paused'];

    public function __construct(
        private ProductionOrderService $produksi,
        private KaryawanVirtualService $kv,
    ) {}

    // ── Jalur 1: karyawan keluar ─────────────────────────────────────────────

    /**
     * Nonaktifkan pelaksana milik karyawan ini di semua divisi dan lepas langkah yang
     * masih dipegangnya. Aman dipanggil berulang.
     *
     * @return array<int, array{order_number: string, langkah: string, tanpa_pelaksana: bool}>
     */
    public function lepasKaryawan(Karyawan $karyawan): array
    {
        return DB::transaction(function () use ($karyawan) {
            $execIds = DepartmentExecutor::where('karyawan_id', $karyawan->id)->pluck('id')->all();
            if (!$execIds) return [];

            DepartmentExecutor::whereIn('id', $execIds)->update(['is_active' => false]);

            $steps = ProductionOrderStep::with('productionOrder:id,order_number')
                ->whereIn('status', self::STATUS_BERJALAN)
                ->whereHas('executors', fn ($q) => $q->whereIn('production_department_executors.id', $execIds))
                ->lockForUpdate()
                ->get();

            $hasil = [];
            foreach ($steps as $step) {
                $sisa = $this->lepas($step, $execIds, "Pelaksana keluar: {$karyawan->name}");
                $hasil[] = [
                    'order_number'    => $step->productionOrder->order_number,
                    'langkah'         => $step->name,
                    'tanpa_pelaksana' => $sisa === 0,
                ];
            }

            return $hasil;
        });
    }

    /** Karyawan diaktifkan kembali → pelaksananya ikut aktif. Langkah yang dilepas tidak kembali. */
    public function aktifkanKembali(Karyawan $karyawan): void
    {
        DepartmentExecutor::where('karyawan_id', $karyawan->id)->update(['is_active' => true]);
    }

    /** Langkah berjalan yang tak punya pelaksana lagi. */
    public static function tanpaPelaksana(ProductionOrderStep $step): bool
    {
        return in_array($step->status, self::STATUS_BERJALAN, true) && !$step->is_qc
            && ($step->relationLoaded('executors') ? $step->executors->isEmpty() : !$step->executors()->exists());
    }

    /**
     * Lanjutkan langkah "Tanpa pelaksana" dengan pelaksana baru. Tanpa persetujuan:
     * tidak ada orang yang diambil tugasnya.
     */
    public function lanjutkan(int $stepId, array $executorIds): void
    {
        DB::transaction(function () use ($stepId, $executorIds) {
            $step = ProductionOrderStep::with('productionOrder', 'executors')->lockForUpdate()->findOrFail($stepId);
            if (!self::tanpaPelaksana($step)) {
                throw new Exception('Langkah ini masih punya pelaksana atau sudah tidak berjalan.');
            }
            if (!in_array($step->productionOrder->status, ['confirmed', 'in_progress', 'partial'], true)) {
                throw new Exception('Order tidak dalam status aktif.');
            }

            $ke = $this->pelaksanaBaru($step, $executorIds);
            $this->assertTidakSibuk($ke, $step->id);

            $this->pasang($step, $ke, aktif: true);
            $step->update(['status' => 'in_progress', 'paused_at' => null, 'executor_id' => $ke[0]]);
            ProductionStepTimeLog::create([
                'production_order_step_id' => $step->id,
                'event_type'               => 'resumed',
                'occurred_at'              => now(),
                'notes'                    => 'Dilanjutkan pelaksana baru: ' . $this->nama($ke),
            ]);
        });
    }

    // ── Jalur 2: oper tugas lewat persetujuan ───────────────────────────────

    /**
     * Ajukan pergantian pelaksana → usulan Manager Produksi + Telegram ke owner.
     *
     * @param  int[]  $dariIds  pelaksana sekarang yang diganti
     * @param  int[]  $keIds    pelaksana pengganti
     */
    public function ajukan(int $stepId, array $dariIds, array $keIds, string $alasan, User $user): Usulan
    {
        $step = ProductionOrderStep::with('productionOrder:id,order_number', 'executors')->findOrFail($stepId);
        [$dari, $ke] = $this->periksa($step, $dariIds, $keIds);

        $kunci = 'langkah:' . $step->id;
        if (Usulan::menunggu()->where('skill', self::SKILL)->where('kunci', $kunci)->exists()) {
            throw new Exception('Pergantian pelaksana langkah ini sudah diajukan dan masih menunggu persetujuan.');
        }

        $alasan = trim($alasan);
        $judul  = "{$step->productionOrder->order_number} · {$step->name}: {$this->nama($dari)} → {$this->nama($ke)}";

        $usulan = app(UsulanService::class)->ajukanSatu(self::SKILL, [
            'kunci'  => $kunci,
            'judul'  => $judul,
            'alasan' => "Alasan: {$alasan} — diajukan {$user->name}.",
            'data'   => [
                'step_id'        => $step->id,
                'dari'           => $dari,
                'ke'             => $ke,
                'alasan'         => $alasan,
                'diajukan_oleh'  => $user->id,
                'diajukan_nama'  => $user->name,
            ],
        ]);

        $this->kv->catat(self::SKILL, [$this->baris($step, $dari, $ke, 'diajukan', "Alasan: {$alasan} — diajukan {$user->name}.")],
            KaryawanVirtualService::PEMICU_PERISTIWA, "Diajukan {$user->name}", $user->id, null,
            [0 => ['id' => $usulan->id, 'baru' => true]]);

        return $usulan;
    }

    /**
     * Kerjakan usulan yang disetujui. Keadaan diperiksa ulang — pelaksana lama bisa sudah
     * selesai, atau penggantinya sedang mengerjakan langkah lain.
     *
     * @return array satu baris hasil (format baris())
     */
    public function ganti(array $data): array
    {
        $step = ProductionOrderStep::with('productionOrder:id,order_number', 'executors')->find($data['step_id'] ?? 0);
        if (!$step) {
            return ['jenis' => 'dilewati', 'step_id' => null, 'order_number' => null, 'langkah' => 'langkah #' . ($data['step_id'] ?? '?'),
                'dari' => '', 'ke' => '', 'alasan' => 'Langkah sudah dihapus.'];
        }

        try {
            return DB::transaction(function () use ($step, $data) {
                $step = ProductionOrderStep::with('productionOrder:id,order_number', 'executors')->lockForUpdate()->findOrFail($step->id);
                [$dari, $ke] = $this->periksa($step, (array) ($data['dari'] ?? []), (array) ($data['ke'] ?? []));
                if ($step->status === 'in_progress') {
                    $this->assertTidakSibuk($ke, $step->id);
                }

                $namaDari = $this->nama($dari);
                $namaKe   = $this->nama($ke);
                $alasan   = "Pelaksana diganti: {$namaDari} → {$namaKe} (disetujui owner). Alasan: " . ($data['alasan'] ?? '-');

                // Langkah jalan → pengganti langsung aktif (tidak dijeda walau semua pelaksana
                // lama dicopot). Langkah dijeda → tetap dijeda sampai ditekan Resume.
                $jalan = $step->status === 'in_progress';
                $this->lepas($step, $dari, $alasan, jedaBilaKosong: false);
                $this->pasang($step, $ke, aktif: $jalan);
                $step->update(['executor_id' => $step->executors()->pluck('production_department_executors.id')->first()]);

                return $this->baris($step, $dari, $ke, 'diganti', $alasan);
            });
        } catch (Exception $e) {
            return $this->baris($step, (array) ($data['dari'] ?? []), (array) ($data['ke'] ?? []), 'dilewati', $e->getMessage());
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Validasi pergantian terhadap keadaan langkah SEKARANG.
     *
     * @return array{0: int[], 1: int[]} [dari, ke]
     */
    private function periksa(ProductionOrderStep $step, array $dariIds, array $keIds): array
    {
        if (!in_array($step->status, self::STATUS_BERJALAN, true)) {
            throw new Exception('Langkah ini sudah tidak berjalan (status: ' . $step->status_label . ').');
        }
        if ($step->is_qc) {
            throw new Exception('Penimbang QC tercatat dari penimbangan — tidak perlu diganti.');
        }

        $sekarang = $step->executors->pluck('id')->map(fn ($id) => (int) $id)->all();
        $dari = array_values(array_intersect(array_map('intval', $dariIds), $sekarang));
        if (!$dari) {
            throw new Exception('Pelaksana yang diganti sudah tidak mengerjakan langkah ini.');
        }

        $ke = $this->pelaksanaBaru($step, $keIds);
        if (array_intersect($ke, $sekarang)) {
            throw new Exception('Pengganti sudah ikut mengerjakan langkah ini.');
        }

        return [$dari, $ke];
    }

    /** Pelaksana pengganti: aktif, divisi langkah, bukan operator penaung, siap kerja. */
    private function pelaksanaBaru(ProductionOrderStep $step, array $keIds): array
    {
        $keIds = array_values(array_unique(array_map('intval', $keIds)));
        if (!$keIds) {
            throw new Exception('Pilih minimal 1 pelaksana pengganti.');
        }

        $sah = DepartmentExecutor::selectable()->where('department_id', $step->department_id)
            ->whereIn('id', $keIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($sah) !== count($keIds)) {
            throw new Exception('Pengganti harus pelaksana aktif di divisi langkah ini (bukan operator penaung).');
        }

        $this->produksi->assertExecutorsReady($keIds, strict: false,
            bypassReady: \App\Models\ProductionSetting::isTestingMode());

        return $keIds;
    }

    /** Satu orang tidak boleh tercatat di dua langkah yang sedang berjalan. */
    private function assertTidakSibuk(array $execIds, int $kecualiStepId): void
    {
        $sibuk = DB::table('production_order_step_executors as x')
            ->join('production_order_steps as s', 's.id', '=', 'x.step_id')
            ->join('production_orders as o', 'o.id', '=', 's.production_order_id')
            ->join('production_department_executors as e', 'e.id', '=', 'x.executor_id')
            ->where('s.status', 'in_progress')->where('s.id', '!=', $kecualiStepId)
            ->whereIn('x.executor_id', $execIds)
            ->get(['e.name', 'o.order_number']);
        if ($sibuk->isNotEmpty()) {
            throw new Exception($sibuk->map(fn ($r) => "{$r->name} sedang mengerjakan {$r->order_number}")->join(', ')
                . '. Pause atau selesaikan dulu.');
        }
    }

    /**
     * Copot pelaksana dari langkah + coret langkah dari sampel. Tak ada pelaksana tersisa
     * pada langkah yang jalan → dijeda, supaya timer tidak berjalan tanpa orang.
     *
     * @return int jumlah pelaksana yang tersisa
     */
    private function lepas(ProductionOrderStep $step, array $execIds, string $alasan, bool $jedaBilaKosong = true): int
    {
        $step->executors()->detach($execIds);
        ProductionStepExecutorStatus::where('step_id', $step->id)->whereIn('executor_id', $execIds)->delete();

        $sisa = $step->executors()->pluck('production_department_executors.id');
        $ubah = [
            'executor_id'            => $sisa->first(),
            'sample_excluded_reason' => mb_substr($alasan, 0, 255),
            'sample_excluded_by'     => $this->kv->akun($this->kv->pemilik(self::SKILL))->id,
        ];
        if ($jedaBilaKosong && $sisa->isEmpty() && $step->status === 'in_progress') {
            $ubah += ['status' => 'paused', 'paused_at' => now()];
            ProductionStepTimeLog::create([
                'production_order_step_id' => $step->id,
                'event_type'               => 'paused',
                'occurred_at'              => now(),
                'notes'                    => mb_substr($alasan, 0, 255),
            ]);
        }
        $step->update($ubah);
        $step->unsetRelation('executors');

        return $sisa->count();
    }

    private function pasang(ProductionOrderStep $step, array $execIds, bool $aktif): void
    {
        foreach ($execIds as $eid) {
            $step->executors()->attach($eid, ['joined_at' => now()->toDateTimeString()]);
            ProductionStepExecutorStatus::updateOrCreate(
                ['step_id' => $step->id, 'executor_id' => $eid],
                [
                    'karyawan_id'   => DepartmentExecutor::find($eid)?->effectiveKaryawanId(),
                    'is_active'     => $aktif,
                    'paused_at'     => $aktif ? null : now(),
                    'paused_reason' => $aktif ? null : 'manual',
                ]
            );
        }
        $step->unsetRelation('executors');
    }

    private function nama(array $execIds): string
    {
        return DepartmentExecutor::whereIn('id', $execIds)->orderBy('name')->pluck('name')->join(', ') ?: '—';
    }

    /** Baris hasil untuk jurnal (lihat Skills\Produksi\GantiPelaksana::rincian). */
    private function baris(ProductionOrderStep $step, array $dari, array $ke, string $jenis, string $alasan): array
    {
        return [
            'jenis'        => $jenis,
            'step_id'      => $step->id,
            'order_id'     => $step->production_order_id,
            'order_number' => $step->productionOrder?->order_number,
            'langkah'      => $step->name,
            'dari'         => $this->nama($dari),
            'ke'           => $this->nama($ke),
            'alasan'       => $alasan,
        ];
    }
}
