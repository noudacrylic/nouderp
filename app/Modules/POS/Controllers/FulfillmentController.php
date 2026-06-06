<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\POS\Services\PosFulfillmentService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function belumSiap(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.belum-siap', [
            'rows'   => $svc->bucket('belum_siap', $request->q),
            'counts' => $svc->counts(),
        ]);
    }

    public function perluDiproses(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.perlu-diproses', [
            'rows'   => $svc->bucket('perlu_diproses', $request->q),
            'counts' => $svc->counts(),
        ]);
    }

    public function telahDiproses(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.telah-diproses', [
            'rows'   => $svc->bucket('telah_diproses', $request->q),
            'counts' => $svc->counts(),
        ]);
    }

    /** Proses Pesanan (SO): generate invoice + post (auto SJ). Gate: lunas + kode booking bila ambil_toko. */
    public function prosesPesanan(Request $request, int $so, PosFulfillmentService $posSvc)
    {
        $salesOrder = SalesOrder::with('items.product', 'customer')->findOrFail($so);

        try {
            $invoice = $posSvc->createInvoiceFromSalesOrder($salesOrder, $request->input('pickup_code'));
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal memproses pesanan: ' . $e->getMessage());
        }

        $flash = "Pesanan {$salesOrder->order_number} diproses. Invoice {$invoice->invoice_number} + Surat Jalan otomatis dibuat.";

        // Opsi "Proses + Cetak Resi": langsung buka cetak Surat Jalan yang baru dibuat.
        if ($request->boolean('print_after')) {
            $delivery = SalesDelivery::where('sales_order_id', $salesOrder->id)
                ->where('status', '!=', 'void')
                ->latest('id')->first();
            if ($delivery) {
                return redirect()->route('sales.deliveries.print', $delivery->id)->with('success', $flash);
            }
        }

        return redirect()->route('pos.fulfillment.telah-diproses')->with('success', $flash);
    }

    /**
     * Proses massal beberapa SO sekaligus. SO yang belum lunas / ambil-di-toko (butuh kode
     * booking) otomatis dilewati dengan keterangan. Opsi print_after → langsung cetak gabungan
     * Surat Jalan yang baru dibuat.
     */
    public function prosesBulk(Request $request, PosFulfillmentService $posSvc)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)->filter()->unique();

        if ($ids->isEmpty()) {
            return back()->with('error', 'Tidak ada pesanan yang dipilih.');
        }

        $processed = [];
        $deliveryIds = [];
        $failed = [];

        foreach ($ids as $id) {
            $so = SalesOrder::with('items.product', 'customer')->find($id);
            if (!$so) { $failed[] = "#{$id} (tidak ditemukan)"; continue; }
            try {
                $posSvc->createInvoiceFromSalesOrder($so); // tanpa pickup_code → ambil-toko otomatis ditolak
                $processed[] = $so->order_number;
                $sj = SalesDelivery::where('sales_order_id', $so->id)
                    ->where('status', '!=', 'void')->latest('id')->first();
                if ($sj) $deliveryIds[] = $sj->id;
            } catch (\Throwable $e) {
                $failed[] = "{$so->order_number} ({$e->getMessage()})";
            }
        }

        $msg = count($processed) . ' pesanan diproses' . (count($processed) ? ': ' . implode(', ', $processed) : '') . '.';
        if ($failed) {
            $msg .= ' Dilewati ' . count($failed) . ': ' . implode('; ', $failed) . '.';
        }
        $flashKey = $processed ? 'success' : 'error';

        if ($request->boolean('print_after') && $deliveryIds) {
            return redirect()->route('sales.deliveries.print-bulk', ['ids' => implode(',', $deliveryIds)])
                ->with($flashKey, $msg);
        }

        return redirect()->route('pos.fulfillment.perlu-diproses')->with($flashKey, $msg);
    }

    /**
     * Generate resi MASSAL untuk beberapa Surat Jalan sekaligus (dari Telah Diproses).
     * Tanpa cek berat/dimensi per-item — pakai default SO/produk. SJ yang gagal/sudah
     * ber-resi/ambil-toko dilewati dengan keterangan.
     */
    public function bookBulk(Request $request, \App\Modules\Shipping\Services\ShipmentBookingService $booking)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)->filter()->unique();

        if ($ids->isEmpty()) {
            return back()->with('error', 'Tidak ada Surat Jalan yang dipilih untuk dibuatkan resi.');
        }

        $ok = []; $warn = []; $err = [];
        foreach ($ids as $id) {
            $delivery = SalesDelivery::find($id);
            if (!$delivery) { $err[] = "#{$id} (tidak ditemukan)"; continue; }
            $res = $booking->book($delivery); // tanpa override → default SO
            if ($res['level'] === 'success') { $ok[] = $res['tracking']; }
            elseif ($res['level'] === 'warning') { $warn[] = $res['message']; }
            else { $err[] = $res['message']; }
        }

        $msg = count($ok) . ' resi dibuat' . (count($ok) ? ' (' . implode(', ', $ok) . ')' : '') . '.';
        if ($warn) $msg .= ' ' . count($warn) . ' perlu perhatian: ' . implode('; ', $warn) . '.';
        if ($err)  $msg .= ' Dilewati ' . count($err) . ': ' . implode('; ', $err) . '.';

        return redirect()->route('pos.fulfillment.telah-diproses')
            ->with(count($ok) ? 'success' : 'error', $msg);
    }

    /** Simpan Catatan Penjual (komunikasi CS ↔ packing) via AJAX dari kartu pemrosesan. */
    public function updateSellerNotes(Request $request, int $so): JsonResponse
    {
        $data = $request->validate(['seller_notes' => ['nullable', 'string', 'max:2000']]);

        $salesOrder = SalesOrder::findOrFail($so);
        $salesOrder->update(['seller_notes' => $data['seller_notes'] ?? null]);

        return response()->json(['ok' => true, 'seller_notes' => $salesOrder->seller_notes]);
    }
}
