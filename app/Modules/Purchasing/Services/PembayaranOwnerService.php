<?php

namespace App\Modules\Purchasing\Services;

use App\Models\Supplier;
use App\Models\User;
use App\Modules\Purchasing\Models\PengajuanBayarOwner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Services\FotoNotaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran pembelian oleh owner. Sisa tagihan selalu dihitung dari dokumen, bukan disimpan,
 * supaya kartunya tetap benar walau dibayar dari halaman Pelunasan.
 */
class PembayaranOwnerService
{
    public function __construct(private SupplierPaymentService $pembayaran, private FotoNotaService $nota) {}

    public function ajukan(PurchaseOrder|PurchaseInvoice $dokumen, ?User $u, bool $tempo = false): PengajuanBayarOwner
    {
        $kolom = $dokumen instanceof PurchaseOrder ? 'purchase_order_id' : 'purchase_invoice_id';

        return PengajuanBayarOwner::firstOrCreate([$kolom => $dokumen->id], ['diajukan_oleh' => $u?->id, 'tempo' => $tempo]);
    }

    /** Sudah ada pembayaran untuk PO ini? (Kaitan langsung, atau catatan "DP untuk PO/..." dari form Pembayaran.) */
    public static function poSudahDibayar(PurchaseOrder $po): bool
    {
        return SupplierPayment::where('status', 'posted')
            ->where(fn ($q) => $q->where('purchase_order_id', $po->id)->orWhere('notes', 'like', '%' . $po->po_number . '%'))
            ->exists();
    }

    /** Faktur yang menagih untuk pengajuan ini. */
    private function faktur(PengajuanBayarOwner $p): Collection
    {
        return PurchaseInvoice::where('status', 'posted')
            ->when($p->purchase_invoice_id, fn ($q) => $q->whereKey($p->purchase_invoice_id))
            ->when(!$p->purchase_invoice_id, fn ($q) => $q->where('purchase_order_id', $p->purchase_order_id))
            ->orderBy('id')->get();
    }

    /** PO masih punya barang yang belum difakturkan? Selama itu tagihannya mengikuti total PO. */
    private function poBelumTuntas(PengajuanBayarOwner $p): bool
    {
        $po = $p->purchaseOrder;

        return !$p->purchase_invoice_id && $po && $po->items()->whereColumn('qty_invoiced', '<', 'qty')->exists();
    }

    public function sisa(PengajuanBayarOwner $p): float
    {
        $faktur = $this->faktur($p);
        if (!$this->poBelumTuntas($p)) return round((float) $faktur->sum('outstanding_amount'), 2);

        $dibayar = (float) SupplierPayment::where('status', 'posted')->where('purchase_order_id', $p->purchase_order_id)->sum('amount');

        return max(0, round((float) $p->purchaseOrder->total - $dibayar, 2));
    }

    public function nomor(PengajuanBayarOwner $p): string
    {
        return (string) ($p->purchaseInvoice?->invoice_number ?? $p->purchaseOrder?->po_number);
    }

    public function supplier(PengajuanBayarOwner $p): ?Supplier
    {
        return ($p->purchaseInvoice ?? $p->purchaseOrder)?->supplier;
    }

    public function jatuhTempo(PengajuanBayarOwner $p): ?string
    {
        return $this->faktur($p)->where('outstanding_amount', '>', 0)->min('due_date')?->toDateString();
    }

    /** Dokumennya dibatalkan? Tagihannya ikut gugur. */
    public function batal(PengajuanBayarOwner $p): bool
    {
        if ($p->purchase_invoice_id) return !$p->purchaseInvoice || $p->purchaseInvoice->status === 'void';

        return !$p->purchaseOrder || $p->purchaseOrder->status === 'cancelled';
    }

    /** @param \Illuminate\Http\UploadedFile[] $bukti */
    public function bayar(PengajuanBayarOwner $p, int $rekeningId, float $nominal, array $bukti, ?User $u): SupplierPayment
    {
        $sisa = $this->sisa($p);
        if ($nominal <= 0) throw new \DomainException('Nominal pembayaran harus lebih dari 0.');
        if ($nominal > $sisa + 0.0001) throw new \DomainException('Nominal melebihi sisa tagihan Rp' . number_format($sisa, 0, ',', '.') . '.');

        // Faktur yang sudah ada dilunasi lebih dulu; sisanya jadi DP untuk PO.
        $alokasi = [];
        $belum = $nominal;
        foreach ($this->faktur($p) as $f) {
            $bagian = min($belum, (float) $f->outstanding_amount);
            if ($bagian <= 0) continue;
            $alokasi[] = ['purchase_invoice_id' => $f->id, 'amount_applied' => $bagian];
            $belum -= $bagian;
        }

        $payment = DB::transaction(function () use ($p, $rekeningId, $nominal, $alokasi, $belum) {
            $payment = $this->pembayaran->createDraft([
                'supplier_id'       => $this->supplier($p)->id,
                'purchase_order_id' => $p->purchase_order_id,
                'payment_date'      => today()->toDateString(),
                'payment_method'    => 'bank',
                'bank_account_id'   => $rekeningId,
                'amount'            => $nominal,
                'allocations'       => $alokasi,
                'notes'             => ($belum > 0.0001 ? 'DP untuk ' : 'Dibayar owner untuk ') . $this->nomor($p),
            ]);

            return $this->pembayaran->post($payment);
        });

        $this->nota->simpan('supplier_payment', $payment, $bukti, $u?->id);

        return $payment;
    }
}
