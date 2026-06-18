<?php

namespace App\Modules\POS\Services;

use App\DTO\SalesInvoiceDTO;
use App\DTO\SalesInvoiceItemDTO;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Modules\Sales\Services\CustomerPaymentService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Services\InvoicePostingService;
use DomainException;

/**
 * Penjualan kasir POS: langsung bikin Invoice (TANPA Sales Order), post (→ Surat Jalan otomatis
 * + stok keluar + jurnal AR/COGS), lalu catat pembayaran. Reuse penuh service Sales — tidak ada
 * akuntansi baru. Pengiriman selalu "ambil di toko" (barang dibawa langsung, ongkir 0).
 */
class PosSaleService
{
    public function __construct(
        private SalesInvoiceService $invoiceService,
        private InvoicePostingService $postingService,
        private CustomerPaymentService $payments,
    ) {}

    /** Customer default "Umum" untuk transaksi walk-in (dibuat sekali bila belum ada). */
    public function resolveWalkInCustomer(): Customer
    {
        return Customer::firstOrCreate(
            ['code' => 'UMUM'],
            ['name' => 'Umum', 'is_active' => true, 'is_marketplace' => false],
        );
    }

    /**
     * Bikin invoice no-SO dari keranjang lalu post. Lempar DomainException bila gagal
     * (mis. stok kurang) — draft invoice yang terlanjur dibuat dihapus agar tidak nyangkut.
     *
     * @param array{customer_id:int,global_discount_type:string,global_discount_value:float,
     *              ppn_percent:float,notes:?string,items:array<int,array>} $data
     */
    public function createSale(array $data): SalesInvoice
    {
        $ppn = (float) ($data['ppn_percent'] ?? 0);

        $items = [];
        foreach ($data['items'] as $it) {
            $qty = (float) $it['qty'];
            if ($qty <= 0) continue;
            $items[] = new SalesInvoiceItemDTO(
                null,                                   // sales_order_item_id (no SO)
                (int) $it['product_id'],
                (string) ($it['description'] ?? ''),
                'product',
                $qty,
                (float) $it['unit_price'],
                in_array($it['discount_type'] ?? 'nominal', ['nominal', 'percent'], true) ? $it['discount_type'] : 'nominal',
                (float) ($it['discount_value'] ?? 0),
                0,                                      // discount_amount (dihitung ulang service)
                $ppn,
                0,                                      // pph_percent
            );
        }
        if (empty($items)) {
            throw new DomainException('Keranjang kosong.');
        }

        $dto = new SalesInvoiceDTO(
            null,                                       // sales_order_id (POS = tanpa SO)
            (int) $data['customer_id'],
            (int) $this->defaultWarehouseId(),
            now()->toDateString(),
            in_array($data['global_discount_type'] ?? 'nominal', ['nominal', 'percent'], true) ? $data['global_discount_type'] : 'nominal',
            (float) ($data['global_discount_value'] ?? 0),
            $ppn,
            0,                                          // pph_percent
            0,                                          // shipping_cost (ambil di toko)
            0,                                          // additional_fee
            0,                                          // advance_applied
            $data['notes'] ?? null,
            0,                                          // marketplace_fee (POS bukan marketplace)
            $items,
        );

        $invoice = $this->invoiceService->createDraft($dto);

        $invoice->update([
            'delivery_method'        => 'ambil_toko',
            'shipping_gross'         => 0,
            'shipping_discount_type' => 'nominal',
            'shipping_discount_value' => 0,
        ]);

        try {
            $this->postingService->post($invoice);
        } catch (\Throwable $e) {
            // Draft belum punya pembayaran/alokasi → aman dihapus supaya tidak jadi sampah.
            $invoice->delete();
            throw new DomainException('Gagal memproses penjualan: ' . $e->getMessage(), 0, $e);
        }

        return $invoice->refresh();
    }

    /** Catat pembayaran tunai sebesar grand total invoice ke kas terpilih (langsung posted). */
    public function recordCashPayment(SalesInvoice $invoice, int $cashAccountId): CustomerPayment
    {
        $payment = $this->payments->create([
            'customer_id'     => $invoice->customer_id,
            'date'            => now()->toDateString(),
            'cash_account_id' => $cashAccountId,
            'amount'          => round((float) $invoice->grand_total, 2),
            'payment_type'    => 'invoice',
            'notes'           => 'POS Kasir — ' . $invoice->invoice_number,
        ]);

        $this->payments->post($payment->id, null, [$invoice->id], [], false);

        return $payment->refresh();
    }

    private function defaultWarehouseId(): int
    {
        return (int) (\App\Core\Inventory\Warehouse::orderBy('id')->value('id') ?? 1);
    }
}
