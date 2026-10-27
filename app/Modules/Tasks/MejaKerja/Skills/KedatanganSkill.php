<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\StokBahanService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillAdmin;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kedatangan barang: PO yang lewat tanggal mestinya datang tapi sisa barangnya belum
 * difakturkan → task "tanya supplier / input faktur". Mestinya datang = tanggal perkiraan PO,
 * atau tanggal PO + lead_time_days terlama bahannya (kosong → 3 hari).
 *
 * Selesai otomatis saat seluruh barang PO sudah difakturkan, atau tanggal perkiraan PO
 * dimundurkan setelah konfirmasi supplier (lewat lagi → task baru).
 */
class KedatanganSkill implements SkillAdmin
{
    use JadiSkillManajer;

    public function __construct(private StokBahanService $stok) {}

    public function key(): string { return 'kedatangan'; }

    public function label(): string { return 'Purchasing — barang belum datang'; }

    public function deskripsi(): string
    {
        return 'PO lewat tanggal perkiraan datang (kosong: tanggal PO + lead time bahan / 3 hari) tapi fakturnya belum diinput → task tanya supplier / Input Faktur.';
    }

    public function peranBawaan(): string { return 'layanan'; }

    public function bobotBawaan(): int { return 5; }

    public function slaMenitBawaan(): int { return 240; }

    public function tersedia(): bool { return true; }

    public function pindai(Carbon $sejak): iterable
    {
        foreach ($this->poTerbuka() as $po) {
            $tempo = $this->jatuhTempo($po);
            // Terlambat mulai esok hari setelah jatuh tempo; yang sudah terlambat sebelum
            // skill dinyalakan tidak membanjiri meja.
            $telat = $tempo->copy()->addDay();
            if ($telat->isFuture() || $telat->lt($sejak)) continue;

            $sisa = DB::table('purchase_order_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                ->where('i.purchase_order_id', $po->id)->whereColumn('i.qty_invoiced', '<', 'i.qty')
                ->get(['p.sku', 'i.description', 'i.qty', 'i.qty_invoiced', 'i.unit'])
                ->map(fn ($b) => ($b->qty - $b->qty_invoiced + 0) . " {$b->unit} " . ($b->sku ?: $b->description))->implode(', ');

            yield [
                'kunci'         => "kedatangan:po:{$po->id}:" . $tempo->format('Ymd'),
                'judul'         => "Barang belum datang: {$po->po_number} — {$po->supplier}",
                'deskripsi'     => "Mestinya datang " . $tempo->format('d/m/Y') . ($po->expected_date ? '' : ' (perkiraan, PO tanpa tanggal datang)') . ".\nSisa: $sisa",
                'taskable_type' => PurchaseOrder::class,
                'taskable_id'   => (int) $po->id,
                'meta'          => ['po_number' => $po->po_number, 'jatuh_tempo' => $tempo->toDateString(), 'telepon' => $po->phone],
                'dibuat_at'     => $telat,
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $po = $this->poTerbuka($task->taskable_id, semuaStatus: true)->first();
        if (!$po || $po->status === 'cancelled') return ['cancelled', 'PO dibatalkan.'];
        if ($po->status === 'closed' || !$po->ada_sisa) return ['done', 'Faktur barang diinput.'];

        $tempo = $this->jatuhTempo($po);
        if ($tempo->toDateString() > ($task->meta['jatuh_tempo'] ?? '') && $tempo->copy()->addDay()->isFuture()) {
            return ['done', 'Tanggal datang diperbarui ke ' . $tempo->format('d/m/Y') . '.'];
        }
        return null;
    }

    public function aksi(Task $task): array
    {
        $aksi = [
            ['label' => 'Input Faktur', 'url' => route('purchasing.invoices.create', ['po_id' => $task->taskable_id]), 'utama' => true],
            ['label' => 'Lihat PO', 'url' => route('purchasing.orders.show', $task->taskable_id)],
        ];
        $hp = normalize_phone($task->meta['telepon'] ?? null);
        if ($hp !== '') $aksi[] = ['label' => 'WA supplier', 'url' => 'https://wa.me/62' . substr($hp, 1)];

        return $aksi;
    }

    public function selesaiManual(): bool { return false; }

    /** PO posted yang masih punya sisa barang belum difakturkan (atau satu PO apa pun statusnya). */
    private function poTerbuka(?int $id = null, bool $semuaStatus = false)
    {
        $sisa = fn ($q) => $q->from('purchase_order_items as i')->whereColumn('i.purchase_order_id', 'o.id')->whereColumn('i.qty_invoiced', '<', 'i.qty');

        return DB::table('purchase_orders as o')->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->when($id, fn ($q) => $q->where('o.id', $id))
            ->when(!$semuaStatus, fn ($q) => $q->where('o.status', 'posted')->whereExists($sisa))
            ->select('o.id', 'o.po_number', 'o.po_date', 'o.expected_date', 'o.status', 's.name as supplier', 's.phone')
            ->selectRaw('EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = o.id AND i.qty_invoiced < i.qty) as ada_sisa')
            ->orderBy('o.id')
            ->get();
    }

    private function jatuhTempo(object $po): Carbon
    {
        $lead = DB::table('purchase_order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.purchase_order_id', $po->id)->max('p.lead_time_days');

        return $this->stok->jatuhTempoDatang($po, $lead ? (int) $lead : null);
    }
}
