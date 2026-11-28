<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\KomponenLaporan;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan komponen kurang/rusak (owner 28 Nov 2026). Dibuat saat Assembling menekan Pending
 * dengan alasan "komponen"; tanpa master komponen — nama diketik, saran dari riwayat produk.
 */
class KomponenLaporanService
{
    /**
     * @param array<int, array{nama: string, jumlah: int|string}> $items
     * @param array<int, UploadedFile|null> $foto  indeks sama dengan $items
     */
    public function catat(ProductionOrderStep $step, string $penyebab, array $items, array $foto, ?int $userId): KomponenLaporan
    {
        return DB::transaction(function () use ($step, $penyebab, $items, $foto, $userId) {
            $log = ProductionStepTimeLog::where('production_order_step_id', $step->id)->where('event_type', 'paused')
                ->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $lap = KomponenLaporan::create([
                'production_order_id'      => $step->production_order_id,
                'production_order_step_id' => $step->id,
                'time_log_id'              => $log?->id,
                'penyebab'                 => $penyebab,
                'status'                   => (KomponenLaporan::PENYEBAB[$penyebab]['susulan'] ?? false) ? 'menunggu' : 'tanpa_susulan',
                'dilaporkan_by'            => $userId,
            ]);
            foreach (array_values($items) as $i => $it) {
                $f = $foto[$i] ?? null;
                $lap->items()->create([
                    'nama'      => mb_substr(trim((string) $it['nama']), 0, 120),
                    'jumlah'    => max(1, (int) $it['jumlah']),
                    'foto_path' => $f instanceof UploadedFile ? $f->store('komponen-laporan', 'public') : null,
                ]);
            }

            return $lap->load('items');
        });
    }

    /** Keterangan Pending ringkas dari laporan — disimpan di catatan log timer. */
    public static function keterangan(string $penyebab, array $items): string
    {
        $isi = collect($items)->map(fn ($i) => trim((string) $i['nama']) . ' ×' . max(1, (int) $i['jumlah']))->implode(', ');

        return mb_substr((KomponenLaporan::PENYEBAB[$penyebab]['pendek'] ?? $penyebab) . ': ' . $isi, 0, 255);
    }

    /**
     * Saran nama komponen per produk output utama, dari laporan sebelumnya (paling sering dulu).
     *
     * @param  array<int>  $orderIds
     * @return array<int, array<int, string>>  [order_id => [nama, …]]
     */
    public function saranUntukOrder(array $orderIds): array
    {
        if (!$orderIds) return [];
        $produkOrder = ProductionOrderOutput::whereIn('production_order_id', $orderIds)->where('output_type', 'main')
            ->get(['production_order_id', 'product_id'])->groupBy('production_order_id')
            ->map(fn ($r) => $r->pluck('product_id')->all());
        $produk = $produkOrder->flatten()->unique()->values()->all();
        if (!$produk) return [];

        $riwayat = DB::table('production_komponen_laporan_items as i')
            ->join('production_komponen_laporan as l', 'l.id', '=', 'i.laporan_id')
            ->join('production_order_outputs as o', fn ($j) => $j->on('o.production_order_id', '=', 'l.production_order_id')->where('o.output_type', 'main'))
            ->whereIn('o.product_id', $produk)
            ->select('o.product_id', 'i.nama', DB::raw('count(*) as n'))
            ->groupBy('o.product_id', 'i.nama')->orderByDesc('n')->get()->groupBy('product_id');

        return $produkOrder->map(fn ($pids) => collect($pids)
            ->flatMap(fn ($p) => ($riwayat[$p] ?? collect())->pluck('nama'))
            ->unique(fn ($n) => mb_strtolower($n))->take(20)->values()->all())->all();
    }

    /** Permintaan potong susulan yang belum dikerjakan. */
    public function menunggu(): Collection
    {
        return KomponenLaporan::with(['items', 'productionOrder:id,order_number', 'step:id,name,department_id', 'pelapor:id,name'])
            ->where('status', 'menunggu')->orderBy('created_at')->get();
    }

    /** Susulan selesai. Sumber 'stok' → pemanggil diarahkan ke form penambahan bahan. */
    public function selesai(KomponenLaporan $lap, string $sumber, ?int $userId): void
    {
        abort_unless(in_array($sumber, ['stok', 'sisa'], true), 422);
        abort_unless($lap->status === 'menunggu', 422, 'Susulan ini sudah ditandai selesai.');
        $lap->update(['status' => 'selesai', 'susulan_selesai_at' => now(), 'susulan_by' => $userId, 'sumber_bahan' => $sumber]);
    }
}
