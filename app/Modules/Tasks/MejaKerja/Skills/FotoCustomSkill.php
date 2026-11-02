<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto produk custom: produksi OP custom selesai → task memotret, supaya ada referensi saat
 * pesanan mirip datang lagi. Selesai otomatis saat foto ter-unggah & tertaut pesanan, batal
 * bila pesanan batal atau barangnya sudah terkirim (sudah tak bisa difoto).
 */
class FotoCustomSkill implements SkillAdmin
{
    use JadiSkillManajer;

    public function key(): string { return 'foto-custom'; }

    public function label(): string { return 'Foto produk custom'; }

    public function deskripsi(): string
    {
        return 'Produksi OP custom selesai → task memotret produknya sebelum dikirim. Selesai sendiri saat foto diunggah lewat tombol di kartu, dan foto itu bisa dicari dari chat CRM.';
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 7; }

    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return Schema::hasTable('galeri_foto'); }

    public function pindai(Carbon $sejak): iterable
    {
        $rows = app(FotoProdukService::class)->opBelumDifoto()
            ->join('sales_orders as s', 's.id', '=', 'p.sales_order_id')
            ->whereNotIn('s.status', ['void', 'cancelled'])
            ->where('p.finalized_at', '>=', $sejak)
            ->whereNotExists(fn ($q) => $q->from('sales_deliveries as d')->whereColumn('d.sales_order_id', 's.id')->where('d.status', 'posted'))
            ->select('s.id', 's.order_number', 'p.finalized_at')
            ->orderBy('p.finalized_at')
            ->get()->unique('id');

        foreach ($rows as $so) {
            $barang = DB::table('sales_order_items as i')->leftJoin('products as x', 'x.id', '=', 'i.product_id')
                ->where('i.sales_order_id', $so->id)
                ->selectRaw("COALESCE(NULLIF(i.description, ''), x.name) as nama, i.qty")
                ->get()->map(fn ($b) => ($b->qty + 0) . '× ' . $b->nama)->implode(', ');

            yield [
                'kunci'         => "foto-custom:so:{$so->id}",
                'judul'         => "Foto {$so->order_number}",
                'deskripsi'     => "Barang: $barang",
                'taskable_type' => SalesOrder::class,
                'taskable_id'   => (int) $so->id,
                'meta'          => ['order_number' => $so->order_number],
                'dibuat_at'     => Carbon::parse($so->finalized_at),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        if (DB::table('galeri_foto')->where('sales_order_id', $task->taskable_id)->exists()) {
            return ['done', 'Otomatis: foto sudah tertaut pesanan.'];
        }
        $so = DB::table('sales_orders')->where('id', $task->taskable_id)->first(['status']);
        if (!$so || in_array($so->status, ['void', 'cancelled'], true)) return ['cancelled', 'Pesanan dibatalkan.'];
        if (DB::table('sales_deliveries')->where('sales_order_id', $task->taskable_id)->where('status', 'posted')->exists()) {
            return ['cancelled', 'Pesanan sudah dikirim sebelum difoto.'];
        }
        return null;
    }

    public function aksi(Task $task): array
    {
        return [
            ['label' => 'Foto produk', 'url' => route('cs.galeri', ['so' => $task->taskable_id]), 'utama' => true],
            ['label' => 'Lihat SO', 'url' => route('sales.orders.show', $task->taskable_id)],
        ];
    }

    public function selesaiManual(): bool { return false; }
}
