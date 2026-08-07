<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioFulfillmentService;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\POS\Services\PosFulfillmentService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FulfillmentController extends Controller
{
    /** Tab Semua: seluruh riwayat SO + status terkininya — untuk audit, bukan antrean kerja. */
    public function semua(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.semua', [
            'rows'   => $svc->allPaginated(
                $request->q,
                $request->only(['channel', 'status', 'from', 'to']),
                per_page_size()
            ),
            'counts' => $svc->counts(),
        ]);
    }

    /** Tab Belum Bayar: pesanan tanpa pembayaran sama sekali, termasuk yang masih draft. */
    public function belumBayar(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.belum-bayar', [
            'rows'      => $svc->bucket('belum_bayar', $request->q, $request->only(['channel', 'courier', 'prioritas'])),
            'counts'    => $svc->counts(),
            'couriers'  => $svc->courierOptions('belum_bayar'),
            'prioritas' => $svc->prioritasCounts('belum_bayar'),
        ]);
    }

    /** Tautan lama "Belum Siap" — sekarang sub-tab dari Perlu Diproses. */
    public function belumSiap(Request $request)
    {
        return redirect()->route('pos.fulfillment.perlu-diproses', ['tahap' => 'belum-siap'] + $request->query());
    }

    /** Tarik manual pesanan marketplace baru (cepat — hanya ready-to-process, tak menunggu sinkron 5 menit). */
    public function syncMarketplace(\App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService $sync)
    {
        if (!\App\Modules\Marketplace\Jubelio\Models\JubelioSetting::singleton()->isConfigured()) {
            return back()->with('error', 'Integrasi Jubelio belum aktif/dikonfigurasi.');
        }

        $res = $sync->pullNewOrders();

        if ($res['created'] > 0) {
            return back()->with('success', "✅ {$res['created']} pesanan baru masuk.");
        }

        $msg = 'Belum ada pesanan baru.';
        if ($res['errors'] > 0) {
            $msg .= " ({$res['errors']} pesanan gagal disinkron — cek log.)";
        }
        return back()->with('success', $msg);
    }

    /**
     * Tab Perlu Diproses dengan 4 sub-tab (?tahap=):
     *   belum-siap  → produksi belum selesai / stok belum cukup (tombol proses mati)
     *   belum-lunas → barang siap, menunggu pelunasan
     *   perlu-ukur  → lunas, kardusnya belum ditimbang & diukur
     *   siap        → benar-benar bisa dikerjakan sekarang (bawaan)
     */
    public function perluDiproses(Request $request, FulfillmentReadinessService $svc)
    {
        $tahap  = in_array($request->tahap, ['belum-siap', 'belum-lunas', 'perlu-ukur'], true) ? $request->tahap : 'siap';
        $bucket = match ($tahap) {
            'belum-siap'  => 'belum_siap',
            'belum-lunas' => 'belum_lunas',
            'perlu-ukur'  => 'perlu_ukur',
            default       => 'perlu_diproses',
        };

        return view('erp.pos.fulfillment.perlu-diproses', [
            'rows'      => $svc->bucket($bucket, $request->q, $request->only(['channel', 'courier', 'prioritas'])),
            'counts'    => $svc->counts(),
            'couriers'  => $svc->courierOptions($bucket),
            'prioritas' => $svc->prioritasCounts($bucket),
            'tahap'     => $tahap,
            'bucket'    => $bucket,
        ]);
    }

    /**
     * Tab Telah Diproses dengan 3 sub-tab (?resi=): belum_generate | belum_cetak | sudah_cetak.
     * Tanpa parameter → semua. Filternya memang sudah ada, di sini dinaikkan jadi sub-tab.
     */
    public function telahDiproses(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.telah-diproses', [
            'rows'       => $svc->bucket('telah_diproses', $request->q, $request->only(['channel', 'courier', 'resi', 'prioritas'])),
            'counts'     => $svc->counts(),
            'couriers'   => $svc->courierOptions('telah_diproses'),
            'resiFilter' => true,
            'resiCounts' => $svc->resiCounts(),
            'prioritas'  => $svc->prioritasCounts('telah_diproses'),
        ]);
    }

    /**
     * Simpan hasil timbang & ukur kardus (sub-tab "Perlu Ukur"), lalu pesanan pindah ke
     * "Siap Proses". Ukurannya menempel di SO dan otomatis dipakai saat resi diterbitkan.
     *
     * Kolomnya boleh dikosongkan: operator yang menilai ukuran yang sudah ada masih benar
     * cukup menekan tombolnya — yang menandai "sudah diukur" adalah `measured_at`, bukan
     * terisinya angka. Ongkir SO SENGAJA tidak ikut dihitung ulang: selisih antara yang
     * ditagih ke pelanggan dan ongkir aktual sudah ditangani jurnal titipan ongkir (1203).
     */
    public function simpanUkuran(Request $request, int $so)
    {
        $data = $request->validate([
            'weight_gram'    => 'nullable|numeric|min:0|max:1000000',
            'package_length' => 'nullable|numeric|min:0|max:1000',
            'package_width'  => 'nullable|numeric|min:0|max:1000',
            'package_height' => 'nullable|numeric|min:0|max:1000',
        ], [], [
            'weight_gram'    => 'berat',
            'package_length' => 'panjang',
            'package_width'  => 'lebar',
            'package_height' => 'tinggi',
        ]);

        $order = SalesOrder::findOrFail($so);

        $isi = fn (string $key) => ($data[$key] ?? null) !== null && $data[$key] !== ''
            ? (float) clean_number($data[$key])
            : null;

        $order->update(array_filter([
            'package_weight_gram' => ($w = $isi('weight_gram')) ? (int) round($w) : null,
            'package_length'      => $isi('package_length'),
            'package_width'       => $isi('package_width'),
            'package_height'      => $isi('package_height'),
        ], fn ($v) => $v !== null) + [
            'measured_at' => now(),
            'measured_by' => auth()->id(),
        ]);

        return back()->with('success', "Ukuran {$order->order_number} tersimpan — pesanan pindah ke Siap Proses.");
    }

    /**
     * Tandai satu Surat Jalan SUDAH SAMPAI di pembeli. Begitu semua paket sebuah pesanan
     * ditandai sampai, kartunya pindah dari tab "Dikirim" ke "Selesai".
     *
     * Ditandai manual (biasanya setelah operator membuka "Lacak"): status kurir tidak ditarik
     * otomatis, jadi yang memutuskan sampai/belum tetap orang.
     */
    public function tandaiSampai(int $delivery)
    {
        $sj = SalesDelivery::findOrFail($delivery);

        if ($sj->status !== 'posted') {
            return back()->with('error', "Surat Jalan {$sj->delivery_number} belum diposting.");
        }
        if ($sj->delivery_method === 'ambil_toko') {
            return back()->with('error', 'Pesanan ambil di toko tidak perlu ditandai sampai.');
        }
        if (! $sj->markDelivered(auth()->id())) {
            return back()->with('error', "Surat Jalan {$sj->delivery_number} sudah ditandai sampai.");
        }

        return back()->with('success', "Surat Jalan {$sj->delivery_number} ditandai sudah sampai.");
    }

    /** Tarik kembali penandaan sampai — pesanan kembali ke tab "Dikirim". */
    public function batalSampai(int $delivery)
    {
        $sj = SalesDelivery::findOrFail($delivery);
        $sj->forceFill(['delivered_at' => null, 'delivered_by' => null])->save();

        return back()->with('success', "Penandaan sampai {$sj->delivery_number} dibatalkan — kembali ke Dikirim.");
    }

    /** Batalkan penandaan sudah-diukur; pesanan kembali ke sub-tab "Perlu Ukur". */
    public function batalUkuran(int $so)
    {
        $order = SalesOrder::findOrFail($so);
        $order->update(['measured_at' => null, 'measured_by' => null]);

        return back()->with('success', "Penandaan ukur {$order->order_number} dibatalkan — kembali ke Perlu Ukur.");
    }

    /** Tab Dikirim: pesanan marketplace yang sudah diserahkan ke jasa kirim (Jubelio shipped / resi dicetak + H+1). */
    public function dikirim(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.dikirim', [
            'rows'     => $svc->bucket('dikirim', $request->q, $request->only(['channel', 'courier'])),
            'counts'   => $svc->counts(),
            'couriers' => $svc->courierOptions('dikirim'),
        ]);
    }

    /** Tandai/batal "sudah dicetak" resi marketplace (toggle manual). */
    public function togglePrinted(int $so)
    {
        $link = JubelioOrderLink::where('sales_order_id', $so)->firstOrFail();
        $link->resi_printed_at = $link->resi_printed_at ? null : now();
        $link->save();

        return back()->with('success', $link->resi_printed_at ? 'Ditandai sudah dicetak.' : 'Tanda cetak resi dibatalkan.');
    }

    /** Tab Selesai: pesanan yang sudah tuntas (marketplace: faktur terbit/transaksi selesai; non-marketplace: resi sudah/tak perlu di-generate). */
    public function selesai(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.selesai', [
            'rows'     => $svc->bucket('selesai', $request->q, $request->only(['channel', 'courier'])),
            'counts'   => $svc->counts(),
            'couriers' => $svc->courierOptions('selesai'),
        ]);
    }

    /** Tab Retur: pesanan marketplace yang diretur pembeli — cek barang lalu post draft retur. */
    public function retur(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.retur', [
            'rows'     => $svc->bucket('retur', $request->q, $request->only(['channel', 'courier'])),
            'counts'   => $svc->counts(),
            'couriers' => $svc->courierOptions('retur'),
        ]);
    }

    /** Tarik manual retur dari Jubelio (selain cron): buat draft retur untuk pesanan yang diretur. */
    public function syncRetur(\App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService $sync)
    {
        if (!\App\Modules\Marketplace\Jubelio\Models\JubelioSetting::singleton()->isConfigured()) {
            return back()->with('error', 'Integrasi Jubelio belum aktif/dikonfigurasi.');
        }
        $stats = $sync->syncReturns();

        return back()->with('success', "Retur disinkron: {$stats['created']} draft dibuat, {$stats['skipped']} dilewati.");
    }

    /** Tab Pembatalan: pesanan marketplace yang pembeli minta batal + SO marketplace yang sudah di-void. */
    public function pembatalan(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.pembatalan', [
            'rows'   => $svc->pembatalanRows($request->q),
            'counts' => $svc->counts(),
        ]);
    }

    /** Tarik manual permintaan pembatalan dari Jubelio (selain cron). */
    public function syncCancel(\App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService $sync)
    {
        if (!\App\Modules\Marketplace\Jubelio\Models\JubelioSetting::singleton()->isConfigured()) {
            return back()->with('error', 'Integrasi Jubelio belum aktif/dikonfigurasi.');
        }
        $stats = $sync->syncCancellationRequests();

        return back()->with('success', "Permintaan pembatalan disinkron: {$stats['flagged']} ditandai, {$stats['cleared']} dibersihkan.");
    }

    /** Proses Pesanan (SO): generate invoice + post (auto SJ). Gate: lunas + kode booking bila ambil_toko. */
    public function prosesPesanan(Request $request, int $so, PosFulfillmentService $posSvc)
    {
        $salesOrder = SalesOrder::with('items.product', 'customer')->findOrFail($so);

        // Pesanan marketplace (punya link Jubelio) → jalankan rantai WMS Jubelio
        // (pick → faktur → resi), BUKAN jalur invoice ERP biasa.
        $link = JubelioOrderLink::where('sales_order_id', $so)->first();
        if ($link) {
            $result = app(JubelioFulfillmentService::class)->process($link);

            // Faktur Jubelio terbit (stok dipotong di Jubelio) → buat Surat Jalan ERP
            // sekaligus, agar stok ERP keluar di waktu yang sama. Cron Tahap B akan skip
            // (sj_created) & hanya memindah tab ke "Dikirim" saat Jubelio shipped.
            if ($result['success']) {
                $this->createMarketplaceDelivery($link->fresh());
            }

            $route  = ($result['success'] && $link->fresh()->isWmsComplete())
                ? 'pos.fulfillment.telah-diproses'
                : 'pos.fulfillment.perlu-diproses';

            // Proses + Cetak Resi: bila resi sudah terbit, langsung buka cetak resi Jubelio.
            if ($result['success'] && $request->boolean('print_after') && $link->fresh()->isWmsComplete()) {
                return redirect()->route('pos.fulfillment.jubelio-resi', $so)
                    ->with('success', $result['message']);
            }

            return redirect()->route($route)
                ->with($result['success'] ? 'success' : 'error', $result['message']);
        }

        try {
            $invoice = $posSvc->createInvoiceFromSalesOrder($salesOrder, $request->input('pickup_code'));
            $salesOrder->forceFill(['process_error' => null, 'process_failed_at' => null])->save();
        } catch (\Throwable $e) {
            $salesOrder->forceFill(['process_error' => $e->getMessage(), 'process_failed_at' => now()])->save();
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
     * Buat Surat Jalan ERP untuk pesanan marketplace yang baru diproses, HANYA bila
     * Faktur Jubelio sudah terbit (stok dipotong di Jubelio) & SJ belum dibuat.
     * Idempoten + lock di service; gagal di sini tidak menggagalkan proses WMS.
     */
    private function createMarketplaceDelivery(JubelioOrderLink $link): void
    {
        if (!$link->j_invoice_done || $link->sj_created) {
            return;
        }
        try {
            app(JubelioOrderSyncService::class)->createDeliveryOnProcess($link);
        } catch (\Throwable $e) {
            Log::warning("Gagal buat Surat Jalan saat Proses marketplace SO {$link->sales_order_id}: " . $e->getMessage());
        }
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

        $links = JubelioOrderLink::whereIn('sales_order_id', $ids)->get()->keyBy('sales_order_id');

        foreach ($ids as $id) {
            $so = SalesOrder::with('items.product', 'customer')->find($id);
            if (!$so) { $failed[] = "#{$id} (tidak ditemukan)"; continue; }

            // Marketplace → rantai WMS Jubelio; selain itu → jalur invoice ERP.
            if ($link = $links->get($id)) {
                $res = app(JubelioFulfillmentService::class)->process($link);
                if ($res['success']) {
                    $processed[] = $so->order_number;
                    $this->createMarketplaceDelivery($link->fresh()); // SJ ERP sekaligus
                } else { $failed[] = "{$so->order_number} ({$res['message']})"; }
                continue;
            }

            try {
                $posSvc->createInvoiceFromSalesOrder($so); // tanpa pickup_code → ambil-toko otomatis ditolak
                $so->forceFill(['process_error' => null, 'process_failed_at' => null])->save();
                $processed[] = $so->order_number;
                $sj = SalesDelivery::where('sales_order_id', $so->id)
                    ->where('status', '!=', 'void')->latest('id')->first();
                if ($sj) $deliveryIds[] = $sj->id;
            } catch (\Throwable $e) {
                $so->forceFill(['process_error' => $e->getMessage(), 'process_failed_at' => now()])->save();
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

    /**
     * Cetak resi marketplace (label resmi Jubelio). Ambil URL report dari Jubelio lalu
     * navigasi SAME-TAB (redirect away) — patuhi aturan no target=_blank. URL di-cache di
     * link agar cetak ulang tak selalu menembak API.
     */
    public function cetakResiJubelio(int $so, JubelioClient $client)
    {
        $link = JubelioOrderLink::where('sales_order_id', $so)->firstOrFail();

        // Klik Cetak Resi → otomatis tandai sudah dicetak (sekali, agar tak menimpa toggle manual).
        if (!$link->resi_printed_at) {
            $link->forceFill(['resi_printed_at' => now()])->save();
        }

        if ($link->j_label_url) {
            return redirect()->away($link->j_label_url);
        }
        $res = $client->getShippingLabelUrl((int) $link->jubelio_salesorder_id);
        $url = data_get($res, 'data.url');
        if (!$res['success'] || !$url) {
            return back()->with('error', 'Gagal mengambil label resi Jubelio: ' . ($res['error'] ?? 'URL tidak tersedia'));
        }
        $link->forceFill(['j_label_url' => $url])->save();

        return redirect()->away($url);
    }

    /**
     * Cetak resi marketplace MASSAL: gabungkan beberapa pesanan marketplace menjadi SATU URL
     * report Jubelio (endpoint shipping-label menerima ids[] jamak → 1 PDF banyak label).
     * Tandai tiap pesanan "sudah dicetak" lalu navigasi SAME-TAB. SO tanpa link/resi dilewati.
     */
    public function cetakResiJubelioBulk(Request $request, JubelioClient $client)
    {
        $ids = collect(explode(',', (string) $request->query('so', '')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique();

        if ($ids->isEmpty()) {
            return back()->with('error', 'Tidak ada pesanan marketplace yang dipilih untuk cetak resi.');
        }

        $links = JubelioOrderLink::whereIn('sales_order_id', $ids)
            ->whereNotNull('tracking_no')->where('tracking_no', '!=', '')
            ->get()
            ->filter(fn ($l) => (int) $l->jubelio_salesorder_id > 0);

        if ($links->isEmpty()) {
            return back()->with('error', 'Pesanan terpilih belum punya resi yang terbit untuk dicetak.');
        }

        $res = $client->getShippingLabelUrl($links->pluck('jubelio_salesorder_id')->map(fn ($v) => (int) $v)->all());
        $url = data_get($res, 'data.url');
        if (!$res['success'] || !$url) {
            return back()->with('error', 'Gagal mengambil label resi gabungan Jubelio: ' . ($res['error'] ?? 'URL tidak tersedia'));
        }

        // Tandai sudah dicetak (sekali per pesanan), konsisten dengan cetak resi satuan.
        foreach ($links as $link) {
            if (!$link->resi_printed_at) {
                $link->forceFill(['resi_printed_at' => now()])->save();
            }
        }

        return redirect()->away($url);
    }

    /** Cetak faktur Jubelio (report), same-tab. Pakai j_invoice_id (fallback jubelio_invoice_id). */
    public function cetakFakturJubelio(int $so, JubelioClient $client)
    {
        $link = JubelioOrderLink::where('sales_order_id', $so)->firstOrFail();

        if ($link->j_faktur_url) {
            return redirect()->away($link->j_faktur_url);
        }
        $invoiceId = (int) ($link->j_invoice_id ?: $link->jubelio_invoice_id);
        if (!$invoiceId) {
            return back()->with('error', 'Faktur Jubelio belum dibuat untuk pesanan ini.');
        }
        $res = $client->getInvoiceReportUrl($invoiceId);
        $url = data_get($res, 'data.url');
        if (!$res['success'] || !$url) {
            return back()->with('error', 'Gagal mengambil faktur Jubelio: ' . ($res['error'] ?? 'URL tidak tersedia'));
        }
        $link->forceFill(['j_faktur_url' => $url])->save();

        return redirect()->away($url);
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
