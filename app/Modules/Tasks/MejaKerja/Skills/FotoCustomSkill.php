<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Galeri\Services\FotoProdukService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto produk custom yang TIDAK difoto di kartu Cetak & tempel (OP tanpa langkah Desain "tempel"
 * — stiker ditempel Assembling, atau custom tanpa gambar): begitu semua langkah OP selesai → task
 * memotret, sebelum barang dikirim. Ditutup dengan "✓ Foto selesai" setelah info foto terisi &
 * foto tertaut (TahapFoto); batal bila pesanan batal atau barangnya sudah terkirim.
 */
class FotoCustomSkill implements SkillAdmin, PunyaJawaban
{
    use JadiSkillManajer, TahapFoto;

    public function key(): string { return 'foto-custom'; }

    public function label(): string { return 'Foto produk custom'; }

    public function deskripsi(): string
    {
        return 'OP custom selesai diproduksi (tanpa langkah tempel stiker oleh Desain) → task memotret produknya sebelum '
            . 'dikirim. Ditutup dengan "Foto selesai" setelah info foto diisi & foto diunggah lewat tombol di kartu.';
    }

    public function peranBawaan(): string { return 'desain'; }

    public function bobotBawaan(): int { return 7; }

    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return Schema::hasTable('galeri_karya'); }

    public function pindai(Carbon $sejak): iterable
    {
        $pola = config('galeri.sku_custom', []);
        if (!$pola) return;

        $rows = DB::table('production_orders as p')
            ->join('sales_orders as s', 's.id', '=', 'p.sales_order_id')
            ->where('p.type', 'custom')
            // Semua langkah selesai (menunggu finalisasi) atau sudah difinalisasi.
            ->whereIn('p.status', ['completed', 'finalized'])
            ->where('p.updated_at', '>=', $sejak)
            ->whereNotIn('s.status', ['void', 'cancelled'])
            ->whereExists(fn ($q) => $q->from('sales_order_items as i')
                ->join('products as x', 'x.id', '=', 'i.product_id')
                ->whereColumn('i.sales_order_id', 's.id')
                ->where(fn ($w) => collect($pola)->each(fn ($p) => $w->orWhere('x.sku', 'like', $p))))
            // Difoto di kartu Cetak & tempel.
            ->whereNotExists(fn ($q) => $q->from('production_order_steps as st')
                ->whereColumn('st.production_order_id', 'p.id')->where('st.desain', 'tempel'))
            ->whereNotExists(fn ($q) => $q->from('galeri_foto as f')->whereColumn('f.sales_order_id', 's.id'))
            ->whereNotExists(fn ($q) => $q->from('galeri_karya as k')->whereColumn('k.sales_order_id', 's.id')->whereNotNull('k.tanpa_foto_alasan'))
            ->whereNotExists(fn ($q) => $q->from('sales_deliveries as d')->whereColumn('d.sales_order_id', 's.id')->where('d.status', 'posted'))
            ->select('s.id', 's.order_number', 'p.updated_at')
            ->orderBy('p.updated_at')
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
                'meta'          => ['order_number' => $so->order_number, 'tahap' => 'foto'],
                'dibuat_at'     => Carbon::parse($so->updated_at),
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
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
            $this->aksiFoto((int) $task->taskable_id),
            ['label' => 'Lihat SO', 'url' => route('sales.orders.show', $task->taskable_id)],
        ];
    }

    public function jawaban(Task $task): array
    {
        return $this->jawabanFoto();
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        return $this->jawabFoto($task, $kunci, $u, (int) $task->taskable_id)
            ?? throw new \RuntimeException('Jawaban tidak dikenal.');
    }

    public function soUntukFoto(Task $task): ?int
    {
        return $task->taskable_id ? (int) $task->taskable_id : null;
    }

    public function selesaiManual(): bool { return false; }
}
