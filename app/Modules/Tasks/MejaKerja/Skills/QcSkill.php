<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QC: OP yang seluruh langkah produksinya selesai & tinggal langkah QC → task "Timbang".
 * Selagi produksi berjalan, Assembling bisa meminta QC sebagian → task terpisah yang selesai
 * begitu hasilnya masuk stok. Task penutup selesai saat langkah QC selesai, batal bila OP batal.
 */
class QcSkill implements SkillAdmin
{
    use JadiSkillManajer;

    /** Sama dengan QcService::STATUS_BOLEH_TIMBANG. */
    private const STATUS_OP = ['in_progress', 'partial', 'completed', 'pending'];

    public function key(): string { return 'qc'; }

    public function label(): string { return 'QC timbang'; }

    public function deskripsi(): string
    {
        return 'Produksi selesai, atau Assembling meminta QC sebagian → task dengan tombol Timbang langsung ke halaman QC.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 9; }

    public function slaMenitBawaan(): int { return 60; }

    public function tersedia(): bool
    {
        return Schema::hasColumn('production_order_steps', 'is_qc');
    }

    public function pindai(Carbon $sejak): iterable
    {
        $langkah = DB::table('production_order_steps as st')
            ->join('production_orders as o', 'o.id', '=', 'st.production_order_id')
            ->where('st.is_qc', true)
            ->whereIn('st.status', ['pending', 'in_progress', 'paused'])
            ->whereIn('o.status', self::STATUS_OP)
            ->select('st.id', 'o.id as op_id', 'o.order_number', 'st.updated_at', 'st.qc_diminta_at')
            ->get();

        foreach ($langkah as $l) {
            $selesai = $this->produksiSelesai((int) $l->op_id);
            if (!$selesai && !$l->qc_diminta_at) {
                continue;
            }

            $sku = DB::table('production_order_outputs as x')->join('products as p', 'p.id', '=', 'x.product_id')
                ->where('x.production_order_id', $l->op_id)->where('x.output_type', 'main')->pluck('p.sku')->implode(', ');
            yield [
                // Tiap permintaan sebagian = kartu sendiri; kunci penutup tetap satu per langkah.
                'kunci'         => $selesai ? "qc:step:{$l->id}" : "qc:step:{$l->id}:" . Carbon::parse($l->qc_diminta_at)->timestamp,
                'judul'         => ($selesai ? 'QC ' : 'QC sebagian ') . $l->order_number . ($sku ? " — $sku" : ''),
                'taskable_type' => ProductionOrder::class,
                'taskable_id'   => (int) $l->op_id,
                'meta'          => ['step_id' => (int) $l->id, 'order_number' => $l->order_number, 'sebagian' => !$selesai],
                'dibuat_at'     => Carbon::parse($l->updated_at)->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $step = DB::table('production_order_steps')->where('id', $task->meta['step_id'] ?? 0)->first(['status', 'qc_diminta_at']);
        $op   = DB::table('production_orders')->where('id', $task->taskable_id)->value('status');
        if (!$step || in_array($op, ['cancelled', 'merged'], true)) return ['cancelled', 'OP dibatalkan/digabung.'];
        if ($step->status === 'completed') return ['done', 'Hasil QC tersimpan.'];
        if ($op === 'finalized') return ['done', 'Otomatis: OP sudah difinalisasi.'];
        if (!empty($task->meta['sebagian'])) {
            if (!$step->qc_diminta_at) return ['done', 'Hasil sebagian sudah masuk stok.'];
            if ($this->produksiSelesai((int) $task->taskable_id)) return ['done', 'Produksi selesai, lanjut di kartu QC penutup.'];
        }
        return null;
    }

    private function produksiSelesai(int $opId): bool
    {
        return !DB::table('production_order_steps')->where('production_order_id', $opId)
            ->where('is_qc', false)->where('status', '!=', 'completed')->exists();
    }

    public function aksi(Task $task): array
    {
        return [
            ['label' => 'Timbang', 'url' => route('production.qc.index', ['op' => $task->taskable_id]), 'utama' => true],
            ['label' => 'Lihat OP', 'url' => route('production.orders.show', $task->taskable_id)],
        ];
    }

    public function selesaiManual(): bool { return false; }
}
