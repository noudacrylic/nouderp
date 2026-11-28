<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Tasks\Models\Task;

/**
 * Alat tempel stiker cuma SATU, dipakai bergantian Admin Desain (langkah "Desain — cetak &
 * tempel") dan Assembling (langkah sesudah "Desain — cetak", lencana "Termasuk tempel stiker").
 *
 * Sedang dipakai =
 *   - kartu Meja Kerja langkah Desain jenis tempel yang sudah di-Mulai (in_progress), atau
 *   - timer langkah Assembling yang menempel stiker cetakan Desain sedang jalan.
 *
 * Hanya penanda (peringatan), tidak memblokir — supaya kerja tak macet karena lupa menekan Selesai.
 * Hasil diingat selama satu permintaan (scoped di AppServiceProvider).
 */
class AlatTempelService
{
    private const OP_AKTIF = ['draft', 'confirmed', 'in_progress', 'partial'];

    private array $ingat = [];

    /** @return array{oleh: string, op: string, desain: bool}|null  null = alat kosong */
    public function dipakai(): ?array
    {
        if (!array_key_exists('dipakai', $this->ingat)) $this->ingat['dipakai'] = $this->cariPemakai();
        return $this->ingat['dipakai'];
    }

    /**
     * Nomor OP yang masih punya langkah Desain jenis TEMPEL belum selesai (antre maupun dikerjakan).
     * Admin hanya boleh memegang satu langkah tempel sekaligus — alatnya satu.
     */
    public function tempelAdminTerbuka(): ?string
    {
        if (!array_key_exists('terbuka', $this->ingat)) {
            $this->ingat['terbuka'] = ProductionOrderStep::where('desain', 'tempel')->where('status', '!=', 'completed')
                ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', self::OP_AKTIF))
                ->with('productionOrder:id,order_number')->orderBy('id')->first()?->productionOrder?->order_number;
        }
        return $this->ingat['terbuka'];
    }

    public function lupakan(): void
    {
        $this->ingat = [];
    }

    private function cariPemakai(): ?array
    {
        $kartu = Task::with('assignee:id,name')->where('skill', 'langkah-desain')->where('status', 'in_progress')->get();
        foreach ($kartu as $t) {
            $step = ProductionOrderStep::with('productionOrder:id,order_number')->find((int) (($t->meta ?? [])['step_id'] ?? 0));
            if ($step && $step->desain === 'tempel' && $step->status !== 'completed') {
                return ['oleh' => $t->assignee?->name ?? 'Admin Desain', 'op' => $step->productionOrder?->order_number ?? '-', 'desain' => true];
            }
        }

        $asm = ProductionOrderStep::with(['productionOrder:id,order_number', 'productionOrder.steps:id,production_order_id,step_number,desain'])
            ->where('status', 'in_progress')->whereNull('desain')->whereNotNull('department_id')
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', self::OP_AKTIF)
                ->whereHas('steps', fn ($s) => $s->where('desain', 'cetak')))
            ->get();
        foreach ($asm as $s) {
            $sebelum = $s->productionOrder->steps->firstWhere('step_number', $s->step_number - 1);
            if ($sebelum?->desain === 'cetak') {
                return ['oleh' => 'Assembling', 'op' => $s->productionOrder->order_number, 'desain' => false];
            }
        }

        return null;
    }
}
