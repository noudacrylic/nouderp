<?php

namespace App\Modules\Purchasing\Services;

use App\Core\Inventory\Product;
use App\Models\User;
use App\Modules\Purchasing\Models\LaporStok;
use App\Modules\Tasks\MejaKerja\Skills\PurchasingNonStokSkill;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lapor Stok dari divisi. Laporan untuk barang yang SUDAH punya task Purchasing terbuka
 * langsung ditempel ke task itu (task cek non stok naik jadi Beli) — Purchasing tidak dapat
 * task kembar. Sisanya diambil skill "Purchasing — laporan divisi" jadi task baru.
 */
class LaporStokService
{
    /** Skill Manajer Admin yang task-nya bisa menampung laporan (tasks.skill). */
    public const SKILL_PURCHASING = ['purchasing-ready', 'purchasing-nonstok', 'purchasing-laporan'];

    public function __construct(private StokBahanService $stok) {}

    /**
     * Barang yang bisa dilaporkan: semua barang non stok aktif + bahan ready stok yang dibeli.
     * Yang paling sering dilaporkan divisi ini di atas.
     *
     * @return Collection<int, array{id: int, sku: string, nama: string, satuan: ?string, jenis: string}>
     */
    public function pilihan(string $divisi): Collection
    {
        $sering = LaporStok::where('divisi', $divisi)->whereNotNull('product_id')
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) n')->pluck('n', 'product_id');

        return Product::where('is_active', true)
            ->where(fn ($q) => $q->where('sale_type', 'non_stock')->orWhereIn('id', $this->stok->bahanBeliIds()))
            ->get(['id', 'sku', 'name', 'base_unit', 'sale_type'])
            ->sortBy([fn ($a, $b) => ($sering[$b->id] ?? 0) <=> ($sering[$a->id] ?? 0), fn ($a, $b) => strcasecmp($a->name, $b->name)])
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'nama' => $p->name, 'satuan' => $p->base_unit,
                              'jenis' => $p->sale_type === 'non_stock' ? 'non stok' : 'ready stok'])
            ->values();
    }

    /** @return Collection<int, LaporStok> laporan 14 hari terakhir divisi ini */
    public function terakhir(string $divisi, int $batas = 15): Collection
    {
        return LaporStok::with(['product:id,name,sku', 'task:id,status', 'pelapor:id,name'])
            ->where('divisi', $divisi)->where('created_at', '>=', now()->subDays(14))
            ->latest('id')->limit($batas)->get();
    }

    /** @return string pesan untuk pelapor */
    public function lapor(array $data, ?User $u): string
    {
        return DB::transaction(function () use ($data, $u) {
            $lapor = LaporStok::create([
                'product_id'      => $data['product_id'] ?? null,
                'nama_barang'     => empty($data['product_id']) ? trim((string) $data['nama_barang']) : null,
                'kondisi'         => $data['kondisi'],
                'catatan'         => $data['catatan'] ?? null,
                'divisi'          => $data['divisi'],
                'pelapor_user_id' => $u?->id,
                'pelapor_nama'    => $data['pelapor_nama'] ?? null,
            ]);

            $task = $lapor->product_id ? $this->taskTerbuka($lapor->product_id) : null;
            if (!$task) return 'Laporan terkirim ke Purchasing. Statusnya bisa dilihat di daftar laporan.';

            $lapor->update(['task_id' => $task->id]);
            $catatan = 'Dilaporkan ' . mb_strtolower(LaporStok::KONDISI[$lapor->kondisi]) . " oleh {$lapor->namaPelapor()} ({$lapor->divisi})"
                . ($lapor->catatan ? ": {$lapor->catatan}" : '');
            $skill = app(PurchasingNonStokSkill::class);
            if ($task->skill === $skill->key() && ($task->meta['tahap'] ?? 'cek') === 'cek') {
                $skill->naikkanKeBeli($task, null, $catatan);
            } else {
                TaskHistory::create(['task_id' => $task->id, 'user_id' => $u?->id, 'action' => 'dilaporkan', 'summary' => mb_substr($catatan, 0, 255), 'created_at' => now()]);
            }

            return 'Laporan terkirim — barang ini sudah ditangani Purchasing, laporan Anda ditambahkan ke tugasnya.';
        });
    }

    public function taskTerbuka(int $productId): ?Task
    {
        return Task::whereIn('skill', self::SKILL_PURCHASING)->where('taskable_type', Product::class)
            ->where('taskable_id', $productId)->whereIn('status', ['open', 'in_progress', 'pending'])
            ->latest('id')->first();
    }
}
