<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Core\Inventory\Product;
use App\Models\User;
use App\Modules\Purchasing\Models\LaporStok;
use App\Modules\Purchasing\Services\LaporStokService;
use App\Modules\Purchasing\Services\StokBahanService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing — laporan divisi (Lapor Stok): laporan barang habis / hampir habis yang belum
 * ditangani task Purchasing lain → task Beli (laporan sejenis digabung; "sudah habis" tenggatnya
 * separuh). Barang ready stok yang di ERP masih tercatat di atas min stok → task Cek stok
 * (opname), karena laporannya menandakan selisih stok, bukan kebutuhan beli.
 *
 * Laporan yang masuk saat barangnya sudah punya task Purchasing terbuka ditempel ke task itu
 * oleh LaporStokService, tidak lewat skill ini.
 */
class PurchasingLaporanSkill implements SkillAdmin, PunyaJawaban
{
    use JadiSkillManajer;

    /** Laporan lebih lama dari ini yang belum tertangani dianggap basi. */
    public const MASA_HARI = 14;

    public function __construct(private StokBahanService $stok) {}

    public function key(): string { return 'purchasing-laporan'; }

    public function label(): string { return 'Purchasing — laporan divisi'; }

    public function deskripsi(): string
    {
        return 'Laporan "Lapor Stok" dari divisi (Proses Produksi, Pemrosesan Pesanan) → task Beli; laporan barang sama digabung, "sudah habis" tenggatnya separuh. Barang ready stok yang di ERP masih banyak → task Cek stok (opname).';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 7; }

    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        $laporan = LaporStok::with('product:id,sku,name,base_unit,min_stock,is_active')->whereNull('task_id')
            ->where('created_at', '>=', now()->subDays(self::MASA_HARI))->orderBy('id')->get()
            ->groupBy(fn ($l) => $l->product_id ? "p{$l->product_id}" : 'n' . mb_strtolower(trim($l->nama_barang)));
        $bahanBeli = $this->stok->bahanBeliIds()->flip();

        foreach ($laporan as $grup) {
            $l = $grup->first();
            $p = $l->product;
            if ($p && !$p->is_active) continue;
            // Barangnya keburu dapat task dari skill Purchasing lain → tempelkan, jangan kembar.
            if ($p && ($ada = app(LaporStokService::class)->taskTerbuka($p->id))) {
                LaporStok::whereIn('id', $grup->pluck('id'))->update(['task_id' => $ada->id]);
                continue;
            }

            $sisa = $p && isset($bahanBeli[$p->id]) ? ($this->stok->tersedia([$p->id])[$p->id] ?? 0) : null;
            $opname = $sisa !== null && $sisa > max(0, (float) $p->min_stock);
            $habis = $grup->contains('kondisi', 'habis');
            $nama = $p?->name ?? $l->nama_barang;
            $divisi = $grup->pluck('divisi')->unique()->implode(', ');

            yield [
                'kunci'         => "lapor:{$l->id}",
                'judul'         => $opname
                    ? "Cek stok {$p->sku}: dilaporkan habis, ERP mencatat " . ($sisa + 0) . " {$p->base_unit}"
                    : "Beli $nama — dilaporkan " . ($habis ? 'sudah habis' : 'hampir habis') . " ($divisi)",
                'deskripsi'     => $grup->map(fn ($x) => '• ' . $x->created_at->format('d/m H:i') . " {$x->divisi} · {$x->namaPelapor()} · "
                    . LaporStok::KONDISI[$x->kondisi] . ($x->catatan ? " — {$x->catatan}" : ''))->implode("\n")
                    . ($p ? '' : "\nBarang belum terdaftar di Produk."),
                'taskable_type' => $p ? Product::class : null,
                'taskable_id'   => $p?->id,
                'meta'          => ['tahap' => $opname ? 'opname' : 'beli', 'laporan_ids' => $grup->pluck('id')->all(), 'nama' => $nama],
                'sla_faktor'    => $habis ? 0.5 : 1,
                'dibuat_at'     => $l->created_at->copy()->max($sejak),
            ];
        }
    }

    public function setelahDibuat(Task $task, array $kerja): void
    {
        LaporStok::whereIn('id', $kerja['meta']['laporan_ids'] ?? [])->update(['task_id' => $task->id]);
    }

    public function keadaan(Task $task): ?array
    {
        if (!$task->taskable_id) return null; // barang belum terdaftar → dijawab manual
        if (!DB::table('products')->where('id', $task->taskable_id)->where('is_active', true)->exists()) {
            return ['cancelled', 'Barang diarsipkan.'];
        }

        if (($task->meta['tahap'] ?? 'beli') === 'opname') {
            $koreksi = DB::table('inventory_ledgers')->where('product_id', $task->taskable_id)
                ->whereIn('transaction_type', ['adjustment_in', 'adjustment_out'])->where('created_at', '>', $task->created_at)->exists();
            return $koreksi ? ['done', 'Stok dikoreksi lewat penyesuaian stok.'] : null;
        }

        $po = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('i.product_id', $task->taskable_id)->where('o.status', '!=', 'cancelled')
            ->where('o.created_at', '>', $task->meta['beli_sejak'] ?? $task->created_at)->value('o.po_number');
        return $po ? ['done', "Dibeli lewat PO $po."] : null;
    }

    public function aksi(Task $task): array
    {
        if (!$task->taskable_id) return [];
        return ($task->meta['tahap'] ?? 'beli') === 'opname'
            ? [['label' => 'Penyesuaian stok', 'url' => route('inventory.adjustments.create'), 'utama' => true]]
            : [['label' => 'Buat PO', 'url' => route('purchasing.orders.create', ['product_id' => $task->taskable_id]), 'utama' => true]];
    }

    public function selesaiManual(): bool { return false; }

    public function jawaban(Task $task): array
    {
        if (($task->meta['tahap'] ?? 'beli') === 'opname') {
            return [
                ['kunci' => 'ada', 'label' => 'Barang ternyata ada'],
                ['kunci' => 'beli', 'label' => 'Memang habis → beli', 'utama' => true],
            ];
        }
        return $task->taskable_id
            ? [['kunci' => 'tidak', 'label' => 'Tidak perlu dibeli']]
            : [['kunci' => 'tidak', 'label' => 'Tidak perlu dibeli'], ['kunci' => 'dibeli', 'label' => 'Sudah dibeli', 'utama' => true]];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        $meja = app(MejaKerjaService::class);

        return match ($kunci) {
            'ada'    => tap('Dicatat: barang ada, stok ERP benar.', fn () => $meja->ubahStatus($task, 'done', $u, 'Barang ternyata ada — stok ERP benar')),
            'tidak'  => tap('Dicatat: tidak perlu dibeli.', fn () => $meja->ubahStatus($task, 'cancelled', $u, 'Tidak perlu dibeli')),
            'dibeli' => tap('Dicatat: sudah dibeli.', fn () => $meja->ubahStatus($task, 'done', $u, 'Sudah dibeli (barang belum terdaftar)')),
            'beli'   => tap('Dicatat: perlu beli. Task ini selesai sendiri saat PO dibuat — stok ERP perlu dikoreksi juga.', function () use ($task, $u) {
                $task->meta = array_merge($task->meta ?? [], ['tahap' => 'beli', 'beli_sejak' => now()->toDateTimeString()]);
                $task->title = mb_substr('Beli ' . ($task->meta['nama'] ?? $task->title) . ' — stok ERP tak sesuai fisik', 0, 255);
                $task->save();
                TaskHistory::create(['task_id' => $task->id, 'user_id' => $u->id, 'action' => 'perlu_beli', 'summary' => 'Hasil cek: memang habis, perlu beli', 'created_at' => now()]);
            }),
            default  => abort(422, 'Jawaban tidak dikenal.'),
        };
    }
}
