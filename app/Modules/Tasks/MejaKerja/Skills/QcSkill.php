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
 * Selesai otomatis begitu langkah QC selesai (hasil timbang tersimpan), batal bila OP batal.
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
        return 'Produksi selesai dan tinggal langkah QC → task dengan tombol Timbang langsung ke halaman QC.';
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
            ->whereNotExists(fn ($q) => $q->from('production_order_steps as lain')
                ->whereColumn('lain.production_order_id', 'o.id')->where('lain.is_qc', false)->where('lain.status', '!=', 'completed'))
            ->select('st.id', 'o.id as op_id', 'o.order_number', 'st.updated_at')
            ->get();

        foreach ($langkah as $l) {
            $sku = DB::table('production_order_outputs as x')->join('products as p', 'p.id', '=', 'x.product_id')
                ->where('x.production_order_id', $l->op_id)->where('x.output_type', 'main')->pluck('p.sku')->implode(', ');
            yield [
                'kunci'         => "qc:step:{$l->id}",
                'judul'         => "QC {$l->order_number}" . ($sku ? " — $sku" : ''),
                'taskable_type' => ProductionOrder::class,
                'taskable_id'   => (int) $l->op_id,
                'meta'          => ['step_id' => (int) $l->id, 'order_number' => $l->order_number],
                'dibuat_at'     => Carbon::parse($l->updated_at)->max($sejak),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $step = DB::table('production_order_steps')->where('id', $task->meta['step_id'] ?? 0)->first(['status']);
        $op   = DB::table('production_orders')->where('id', $task->taskable_id)->value('status');
        if (!$step || in_array($op, ['cancelled', 'merged'], true)) return ['cancelled', 'OP dibatalkan/digabung.'];
        if ($step->status === 'completed') return ['done', 'Hasil QC tersimpan.'];
        return null;
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
