<?php

namespace App\Modules\Tasks\MejaKerja\Contracts;

use App\Models\User;
use App\Modules\Purchasing\Models\PengajuanBayarOwner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PembayaranOwnerService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Tahap kartu Purchasing setelah barang dibeli: PO/faktur dibuat → tombol Bayar sendiri / Bayar
 * owner → selesai saat dibayar atau diserahkan ke owner. PO yang selesai dari kartu ini lanjut
 * ke kartu Selesaikan pesanan (SelesaikanPesananSkill).
 */
trait AlurPembelian
{
    /** Sejak kapan dokumen pembelian dianggap milik kartu ini. */
    protected function beliSejak(Task $task): mixed
    {
        return $task->meta['beli_sejak'] ?? $task->created_at;
    }

    /** Keadaan kartu yang sudah punya dokumen; null = belum beres / belum punya dokumen. */
    protected function keadaanPembelian(Task $task): ?array
    {
        if ($poId = $task->meta['po_id'] ?? null) {
            $po = PurchaseOrder::find($poId);
            if (!$po || $po->status === 'cancelled') return ['cancelled', 'PO dibatalkan.'];
            if (PembayaranOwnerService::poSudahDibayar($po)) return ['done', "PO {$po->po_number} dibayar."];

            return PengajuanBayarOwner::where('purchase_order_id', $po->id)->exists() ? ['done', "PO {$po->po_number} diserahkan ke owner."] : null;
        }

        if ($fakturId = $task->meta['faktur_id'] ?? null) {
            $f = PurchaseInvoice::find($fakturId);
            if (!$f || $f->status === 'void') return ['cancelled', 'Faktur dibatalkan.'];
            if ((float) $f->outstanding_amount <= 0.0001) return ['done', "Faktur {$f->invoice_number} lunas."];

            return PengajuanBayarOwner::where('purchase_invoice_id', $f->id)->exists() ? ['done', "Faktur {$f->invoice_number} diserahkan ke owner."] : null;
        }

        return null;
    }

    /** Kartu belum punya dokumen: cari PO / faktur baru berisi barangnya, lalu tempelkan. */
    public function perbarui(Task $task): ?array
    {
        if (!$task->taskable_id || !empty($task->meta['po_id']) || !empty($task->meta['faktur_id'])) return null;
        if (($task->meta['tahap'] ?? 'beli') === 'opname') return null;

        $sejak = $this->beliSejak($task);
        $po = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('i.product_id', $task->taskable_id)->where('o.status', '!=', 'cancelled')->where('o.created_at', '>', $sejak)
            ->first(['o.id', 'o.po_number']);
        if ($po) {
            return ['meta' => array_merge($task->meta ?? [], ['po_id' => $po->id]), 'status' => null, 'catatan' => "PO {$po->po_number} dibuat."];
        }

        $faktur = DB::table('purchase_invoice_items as i')->join('purchase_invoices as f', 'f.id', '=', 'i.purchase_invoice_id')
            ->where('i.product_id', $task->taskable_id)->where('f.status', 'posted')->whereNull('f.purchase_order_id')->where('f.created_at', '>', $sejak)
            ->first(['f.id', 'f.invoice_number']);
        if ($faktur) {
            return ['meta' => array_merge($task->meta ?? [], ['faktur_id' => $faktur->id]), 'status' => null, 'catatan' => "Faktur {$faktur->invoice_number} dibuat."];
        }

        return null;
    }

    protected function aksiBeli(Task $task): array
    {
        return [
            ['label' => 'Buat PO', 'url' => route('purchasing.orders.create', ['product_id' => $task->taskable_id]), 'utama' => true],
            ['label' => 'Buat Faktur', 'url' => route('purchasing.invoices.create', ['product_id' => $task->taskable_id])],
        ];
    }

    /** Tombol tahap bayar; null = kartu belum punya dokumen. */
    protected function aksiBayar(Task $task): ?array
    {
        if ($po = PurchaseOrder::find($task->meta['po_id'] ?? 0)) {
            return [
                ['label' => 'Bayar sendiri', 'url' => route('purchasing.payments.create', ['supplier_id' => $po->supplier_id, 'po_id' => $po->id, 'jumlah' => (float) $po->total]), 'utama' => true],
                ['label' => 'Lihat PO', 'url' => route('purchasing.orders.show', $po->id)],
            ];
        }
        if ($f = PurchaseInvoice::find($task->meta['faktur_id'] ?? 0)) {
            return [
                ['label' => 'Bayar sendiri', 'url' => route('purchasing.payments.create', ['supplier_id' => $f->supplier_id, 'jumlah' => (float) $f->outstanding_amount]), 'utama' => true],
                ['label' => 'Lihat faktur', 'url' => route('purchasing.invoices.show', $f->id)],
            ];
        }

        return null;
    }

    /** @return array<int, array{kunci: string, label: string}>|null null = kartu belum punya dokumen */
    protected function jawabanBayar(Task $task): ?array
    {
        return !empty($task->meta['po_id']) || !empty($task->meta['faktur_id'])
            ? [['kunci' => 'bayar-owner', 'label' => 'Bayar owner']]
            : null;
    }

    protected function serahkanKeOwner(Task $task, User $u): string
    {
        $dokumen = !empty($task->meta['po_id'])
            ? PurchaseOrder::findOrFail($task->meta['po_id'])
            : PurchaseInvoice::findOrFail($task->meta['faktur_id']);
        app(PembayaranOwnerService::class)->ajukan($dokumen, $u);
        app(MejaKerjaService::class)->sinkronkanSkill($this);

        return 'Diserahkan ke owner. Tugas Anda untuk pembayaran ini selesai.';
    }
}
