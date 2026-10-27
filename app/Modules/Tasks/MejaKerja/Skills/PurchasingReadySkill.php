<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Core\Inventory\Product;
use App\Modules\Purchasing\Services\StokBahanService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing ready stok (bahan baku & aksesoris berstok → HPP): tersedia ≤ min_stock dan
 * belum ada PO yang ditunggu → task "Beli" dengan tombol Buat PO. Selesai otomatis begitu PO berisi bahan itu dibuat; batal bila stok naik
 * lagi tanpa pembelian (bukan hasil kerja admin) atau min_stock dikosongkan.
 *
 * Berbeda dari skill lain, pemicunya KEADAAN stok, bukan dokumen baru — bahan yang sudah
 * menipis saat skill dinyalakan ikut masuk (jumlahnya kecil, dan memang harus dibeli).
 * Menggantikan aturan Task Otomasi jenis stok selama skill ini aktif.
 */
class PurchasingReadySkill implements SkillAdmin
{
    use JadiSkillManajer;

    public function __construct(private StokBahanService $stok) {}

    public function key(): string { return 'purchasing-ready'; }

    public function label(): string { return 'Purchasing ready stok'; }

    public function deskripsi(): string
    {
        return 'Bahan baku & aksesoris berstok (masuk HPP) yang tersedianya ≤ min stok dan belum ada PO yang ditunggu → task Beli dengan tombol Buat PO (supplier & harga terakhir terisi). Selesai sendiri saat PO dibuat.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 6; }

    public function slaMenitBawaan(): int { return 480; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        $produk = DB::table('products')->whereIn('id', $this->stok->bahanBeliIds())->where('min_stock', '>', 0)
            ->get(['id', 'sku', 'name', 'base_unit', 'min_stock']);
        $ids = $produk->pluck('id')->all();
        $sisa = $this->stok->tersedia($ids);
        $ditunggu = $this->stok->adaPoTerbuka($ids);
        $sudahAda = Task::where('skill', $this->key())->whereIn('status', ['open', 'in_progress', 'pending'])
            ->pluck('taskable_id')->flip();
        $pernah = Task::where('skill', $this->key())->groupBy('taskable_id')->selectRaw('taskable_id, COUNT(*) n')->pluck('n', 'taskable_id');

        foreach ($produk as $p) {
            if ($sisa[$p->id] > (float) $p->min_stock || isset($ditunggu[$p->id]) || isset($sudahAda[$p->id])) continue;

            $min  = (float) $p->min_stock + 0;
            $unit = $p->base_unit ?: '';
            $beli = $this->stok->pembelianTerakhir($p->id);
            yield [
                // Kunci per kejadian (ke-n): bahan yang sama boleh menipis lagi bulan depan, tapi
                // tetap sama selama belum dibuat — mode Bayangan tak mencatatnya ulang tiap jalan.
                'kunci'         => "beli:{$p->id}:" . (($pernah[$p->id] ?? 0) + 1),
                'judul'         => "Beli {$p->sku} — sisa " . ($sisa[$p->id] + 0) . " $unit (min $min)",
                'deskripsi'     => $p->name . ($beli
                    ? "\nPembelian terakhir: " . ($beli->qty + 0) . " {$beli->unit} dari {$beli->supplier} @ Rp" . number_format((float) $beli->price, 0, ',', '.')
                      . ' (' . Carbon::parse($beli->po_date)->format('d/m/Y') . ')'
                    : ''),
                'taskable_type' => Product::class,
                'taskable_id'   => (int) $p->id,
                'meta'          => ['sku' => $p->sku, 'min_stock' => $min, 'tersedia' => $sisa[$p->id]],
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $po = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('i.product_id', $task->taskable_id)->where('o.status', '!=', 'cancelled')
            ->where('o.created_at', '>', $task->created_at)
            ->value('o.po_number');
        if ($po) return ['done', "PO $po dibuat."];

        $p = DB::table('products')->where('id', $task->taskable_id)->first(['is_active', 'min_stock']);
        if (!$p || !$p->is_active || !($p->min_stock > 0)) return ['cancelled', 'Bahan diarsipkan / min stok dikosongkan.'];
        if (($this->stok->tersedia([$task->taskable_id])[$task->taskable_id] ?? 0) > (float) $p->min_stock) {
            return ['cancelled', 'Stok kembali di atas minimum tanpa pembelian.'];
        }
        return null;
    }

    public function aksi(Task $task): array
    {
        return [
            ['label' => 'Buat PO', 'url' => route('purchasing.orders.create', ['product_id' => $task->taskable_id]), 'utama' => true],
            ['label' => 'Saran min stok', 'url' => $this->urlPengaturan(MejaKerjaService::KARYAWAN)],
        ];
    }

    /** Pengaturan skill ini = min stok bahan (peran/bobot/tenggat tetap di Pengaturan Manajer Admin). */
    public function urlPengaturan(string $kode): string
    {
        return route('karyawan-virtual.admin.min-stok', $kode);
    }

    public function selesaiManual(): bool { return false; }
}
