<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioFulfillmentService;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\POS\Services\PickupReadyService;
use App\Modules\POS\Services\PosFulfillmentService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shipping\Services\ShipmentBookingService;
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

    /**
     * Tandai pesanan ambil di toko SUDAH DIAMBIL → pindah dari "Telah Diproses" ke "Selesai".
     *
     * Untuk pesanan yang Surat Jalannya terbit lewat jalur selain tombol Proses / konfirmasi
     * booking code, sehingga pickup_status tak pernah terisi. Tanggal diambil = tanggal SJ
     * (barang keluar gudang hari itu), bukan hari tombol ditekan — tanggal ini dipakai grafik
     * Penjualan sebagai tanggal pesanan selesai.
     */
    public function tandaiDiambil(int $so)
    {
        $order = SalesOrder::findOrFail($so);

        if (! $order->isPickup()) {
            return back()->with('error', "{$order->order_number} bukan pesanan ambil di toko.");
        }
        if ($order->pickup_status === 'picked_up') {
            return back()->with('error', "Barang {$order->order_number} sudah tercatat diambil.");
        }

        $sj = SalesDelivery::where('sales_order_id', $order->id)
            ->where('status', 'posted')
            ->latest('delivery_date')->first();
        if (! $sj) {
            return back()->with('error', "{$order->order_number} belum punya Surat Jalan yang diposting — proses pesanannya dulu.");
        }

        $order->update([
            'pickup_status' => 'picked_up',
            'picked_up_at'  => \Carbon\Carbon::parse($sj->delivery_date)->setTimeFrom($sj->created_at ?? now()),
        ]);

        return back()->with('success', "Barang {$order->order_number} ditandai sudah diambil — pindah ke Selesai.");
    }

    /** Tarik kembali penandaan sampai — pesanan kembali ke tab "Dikirim". */
    public function batalSampai(int $delivery)
    {
        $sj = SalesDelivery::findOrFail($delivery);
        $sj->forceFill(['delivered_at' => null, 'delivered_by' => null])->save();

        return back()->with('success', "Penandaan sampai {$sj->delivery_number} dibatalkan — kembali ke Dikirim.");
    }

    /**
     * Bebaskan pesanan dari gerbang produksi tanpa order produksi yang difinalisasi.
     *
     * Untuk produk yang dibuat khusus per pesanan, kesiapan dinilai dari OP miliknya sendiri.
     * Kadang barangnya sudah ada tanpa pernah lewat produksi di ERP — misalnya sisa pesanan
     * yang batal yang dimasukkan lewat Stock Opname. Pesanan seperti itu tak akan pernah punya
     * OP finalized, jadi tanpa pintu ini ia menetap di "Belum Siap" selamanya.
     *
     * Yang dilepas HANYA gerbang produksi. Kecukupan stok tetap dihitung: barang yang memang
     * tidak ada tetap menahan pesanannya, karena membebaskannya tidak menciptakan barang.
     */
    public function waiveProduksi(Request $request, int $so)
    {
        $data = $request->validate(
            ['reason' => 'required|string|max:255'],
            [],
            ['reason' => 'alasan']
        );

        $order = SalesOrder::findOrFail($so);

        if ($order->production_waived_at) {
            return back()->with('error', "Pesanan {$order->order_number} sudah dibebaskan sebelumnya.");
        }

        // Alasan WAJIB diisi. Pembebasan ini melangkahi satu-satunya pemeriksaan yang menjamin
        // barangnya ada; tanpa catatan, bulan depan tidak ada yang bisa menjelaskan kenapa
        // pesanan ini lolos — dan itu persis cara masalah lama bersembunyi.
        $order->update([
            'production_waived_at'     => now(),
            'production_waived_reason' => trim($data['reason']),
        ]);

        return back()->with('success', "Pesanan {$order->order_number} dibebaskan dari gerbang produksi — "
            . 'pastikan barangnya benar-benar ada sebelum dipacking.');
    }

    /** Tarik kembali pembebasan gerbang produksi. */
    public function batalWaiveProduksi(int $so)
    {
        $order = SalesOrder::findOrFail($so);
        $order->update(['production_waived_at' => null, 'production_waived_reason' => null]);

        return back()->with('success', "Pembebasan {$order->order_number} ditarik kembali.");
    }

    /**
     * Tandai pesanan ambil-di-toko SIAP DIAMBIL → pembeli dikabari lewat WhatsApp.
     *
     * Tombol manualnya ada karena pemindaian otomatis (`pos:pindai-siap-diambil`) hanya
     * menyimpulkan kesiapan dari bucket, dan ada pesanan yang barangnya sudah selesai lebih
     * dulu daripada yang bisa disimpulkan ERP. Keduanya menstempel kolom yang sama lewat
     * service yang sama, jadi pesannya tidak mungkin berbeda.
     */
    public function tandaiSiapDiambil(int $so, PickupReadyService $svc)
    {
        $order = SalesOrder::findOrFail($so);

        if (! $order->isPickup()) {
            return back()->with('error', "Pesanan {$order->order_number} bukan Ambil di Toko.");
        }
        if (! $svc->tandaiSiap($order, auth()->id())) {
            return back()->with('error', "Pesanan {$order->order_number} sudah ditandai siap atau sudah diambil.");
        }

        return back()->with('success', "Pesanan {$order->order_number} ditandai siap diambil — "
            . 'pembeli dikabari lewat WhatsApp (menyesuaikan jam buka toko).');
    }

    /**
     * Kabari pembeli pesanan kirim bahwa barangnya siap & tinggal dilunasi.
     *
     * Pasangan manual `pos:pindai-pelunasan` — lewat service yang sama, jadi pesan dan
     * pagarnya (bukan ambil-toko, bukan tempo, satu kabar per sisa tagihan) tidak bercabang.
     */
    public function kabariPelunasan(int $so, \App\Modules\POS\Services\PelunasanNoticeService $svc)
    {
        [$ok, $pesan] = $svc->kabari(SalesOrder::with('customer')->findOrFail($so), manual: true);

        return back()->with($ok ? 'success' : 'error', $pesan);
    }

    /** Tarik kembali penandaan siap diambil; notifikasi yang belum berangkat ikut dibatalkan. */
    public function batalSiapDiambil(int $so, PickupReadyService $svc)
    {
        $order = SalesOrder::findOrFail($so);
        $svc->batalSiap($order);

        return back()->with('success', "Penandaan siap diambil {$order->order_number} ditarik kembali.");
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

    /**
     * Tab Selesai: SELURUH pesanan yang sudah tuntas, dipaginasi di SQL.
     *
     * Bukan lagi lewat bucket() — riwayatnya ribuan baris, dan mesin bucket
     * menghidrasi semuanya ke memori sekaligus. Itu yang dulu memaksa tab ini
     * mengarsip apa pun yang selesai > 3 hari lalu, sehingga ia nyaris selalu
     * kosong. Lihat FulfillmentReadinessService::selesaiPaginated().
     */
    public function selesai(Request $request, FulfillmentReadinessService $svc)
    {
        return view('erp.pos.fulfillment.selesai', [
            'rows'   => $svc->selesaiPaginated(
                $request->q,
                $request->only(['channel', 'from', 'to']),
                per_page_size()
            ),
            'counts' => $svc->counts(),
        ]);
    }

    /**
     * Tab Retur: SELURUH retur yang belum diselesaikan, dua sub-tab.
     *
     *   Retur Baru  retur yang baru masuk & belum diputuskan nasibnya
     *   Banding     retur yang sedang disengketakan ke marketplace
     *
     * Di sinilah retur dikerjakan, apa pun kanalnya — itu sebabnya sumbernya
     * DOKUMEN retur dan bukan lagi pesanan bertautan Jubelio.
     */
    public function retur(Request $request, FulfillmentReadinessService $svc)
    {
        $tahap = in_array($request->tahap, \App\Modules\Sales\Models\SalesReturn::STAGES_AKTIF, true)
            ? $request->tahap
            : 'baru';

        return view('erp.pos.fulfillment.retur', [
            'tahap'       => $tahap,
            'rows'        => $svc->returRows($tahap, $request->q),
            'returCounts' => $svc->returCounts(),
            'counts'      => $svc->counts(),
        ]);
    }

    /**
     * Pindahkan retur ke tahap Banding, atau tarik kembali ke Retur Baru.
     *
     * Hanya tahap yang berpindah — tidak ada jurnal, tidak ada stok. Yang
     * membukukan apa pun cuma "Selesaikan Retur" (post) di form returnya.
     */
    public function returTahap(Request $request, int $retur)
    {
        $data = $request->validate([
            'tahap' => 'required|in:baru,banding',
        ]);

        $return = \App\Modules\Sales\Models\SalesReturn::findOrFail($retur);

        if ($return->status !== 'draft') {
            return back()->with('error', 'Retur yang sudah diselesaikan tidak bisa dipindahkan lagi.');
        }

        $return->update(['stage' => $data['tahap']]);

        return back()->with('success', $data['tahap'] === 'banding'
            ? "Retur {$return->return_number} dipindahkan ke Banding."
            : "Retur {$return->return_number} dikembalikan ke Retur Baru.");
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

        $resi  = $this->terbitkanResi($salesOrder);
        $flash .= $this->pesanResi($resi);

        // Opsi "Proses + Cetak Resi": langsung buka cetak Surat Jalan yang baru dibuat.
        if ($request->boolean('print_after')) {
            $delivery = SalesDelivery::where('sales_order_id', $salesOrder->id)
                ->where('status', '!=', 'void')
                ->latest('id')->first();
            if ($delivery) {
                return redirect()->route('sales.deliveries.print', $delivery->id)->with('success', $flash);
            }
        }

        return redirect()->route('pos.fulfillment.telah-diproses', $this->subTabResi($resi))
            ->with($resi['gagal'] ? 'warning' : 'success', $flash);
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
     * Terbitkan resi untuk Surat Jalan kurir-API milik pesanan yang BARU diproses.
     *
     * Dulu "Proses Pesanan" berhenti di faktur + Surat Jalan, lalu operator harus menekan
     * "Generate Resi" sebagai langkah kedua — dan langkah itu menanyakan ulang berat &
     * dimensi yang sudah dikunci di sub-tab "Perlu Ukur". Dua pertanyaan untuk jawaban yang
     * sama. Sekarang resi ikut terbit di sini memakai ukuran yang tersimpan di SO, sehingga
     * pesanan langsung mendarat di sub-tab "Belum dicetak".
     *
     * Booking TIDAK boleh menggagalkan proses: faktur & Surat Jalan sudah terlanjur jadi, dan
     * membatalkannya jauh lebih mahal daripada membiarkan resi menyusul. Kegagalan
     * dikembalikan sebagai pesan; kartunya tetap duduk di "Belum di-generate" untuk dicoba
     * ulang manual.
     *
     * @return array{ok:string[], gagal:string[]}
     */
    private function terbitkanResi(SalesOrder $so): array
    {
        $hasil = ['ok' => [], 'gagal' => []];

        // Ambil di toko tidak punya paket; kurir manual tidak punya API yang bisa dipesan.
        if ($so->isPickup() || \App\Models\ManualCourier::isManualCode($so->shipping_courier_code)) {
            return $hasil;
        }

        $deliveries = SalesDelivery::where('sales_order_id', $so->id)
            ->where('status', 'posted')
            ->where('delivery_method', '!=', 'ambil_toko')
            ->get()
            ->filter(fn (SalesDelivery $d) => empty($d->tracking_number)
                && !\App\Models\ManualCourier::isManualCode($d->shipping_courier_code));

        foreach ($deliveries as $delivery) {
            try {
                $res = app(ShipmentBookingService::class)->book($delivery);
            } catch (\Throwable $e) {
                Log::warning("Gagal terbitkan resi SJ {$delivery->delivery_number}: " . $e->getMessage());
                $hasil['gagal'][] = "{$delivery->delivery_number}: {$e->getMessage()}";
                continue;
            }

            // Ada nomor resi = resi nyata sudah terbit & Coins sudah terpotong, apa pun
            // levelnya. Level 'warning' berarti resinya terbit tapi JURNALNYA gagal — itu
            // tetap dihitung berhasil di sini, sebabnya dilaporkan lewat cabang di bawah.
            if (!empty($res['tracking'])) {
                $hasil['ok'][] = (string) $res['tracking'];
            }
            if ($res['level'] !== 'success') {
                $hasil['gagal'][] = $res['message'];
            }
        }

        return $hasil;
    }

    /** Potongan flash hasil penerbitan resi; kosong bila pesanan memang tak perlu resi. */
    private function pesanResi(array $resi): string
    {
        $teks = '';
        if ($resi['ok']) {
            $teks .= ' Resi terbit: ' . implode(', ', $resi['ok']) . '.';
        }
        if ($resi['gagal']) {
            $teks .= ' Resi belum terbit — ' . implode('; ', $resi['gagal'])
                . '. Coba lagi lewat tombol Generate Resi.';
        }

        return $teks;
    }

    /**
     * Sub-tab tujuan setelah proses: ke tempat pekerjaan berikutnya berada. Resi terbit →
     * "Belum dicetak"; gagal → "Belum di-generate" supaya masalahnya langsung terlihat.
     */
    private function subTabResi(array $resi): array
    {
        if ($resi['gagal']) return ['resi' => 'belum_generate'];
        if ($resi['ok'])    return ['resi' => 'belum_cetak'];

        return [];
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
        $resiOk = [];
        $resiGagal = [];

        $links = JubelioOrderLink::whereIn('sales_order_id', $ids)->get()->keyBy('sales_order_id');

        foreach ($ids as $id) {
            $hasil = $this->prosesSatuPesanan($id, $posSvc, $links->get($id));

            if (!$hasil['found']) { $failed[] = "#{$id} (tidak ditemukan)"; continue; }

            if ($hasil['ok']) {
                $processed[] = $hasil['order_number'];
            } else {
                $failed[] = "{$hasil['order_number']} ({$hasil['message']})";
            }

            $resiOk    = array_merge($resiOk, $hasil['resi_ok']);
            $resiGagal = array_merge($resiGagal, $hasil['resi_gagal']);
            if ($hasil['delivery_id']) $deliveryIds[] = $hasil['delivery_id'];
        }

        $resi = ['ok' => $resiOk, 'gagal' => $resiGagal];

        $msg = count($processed) . ' pesanan diproses' . (count($processed) ? ': ' . implode(', ', $processed) : '') . '.';
        if ($failed) {
            $msg .= ' Dilewati ' . count($failed) . ': ' . implode('; ', $failed) . '.';
        }
        $msg .= $this->pesanResi($resi);

        $flashKey = $processed ? ($failed || $resiGagal ? 'warning' : 'success') : 'error';

        if ($request->boolean('print_after') && $deliveryIds) {
            return redirect()->route('sales.deliveries.print-bulk', ['ids' => implode(',', $deliveryIds)])
                ->with($flashKey, $msg);
        }

        // Yang berhasil pindah ke "Telah Diproses"; hanya kalau semuanya gagal operator
        // tetap ditinggal di antrean asalnya.
        if (!$processed) {
            return redirect()->route('pos.fulfillment.perlu-diproses')->with($flashKey, $msg);
        }

        return redirect()->route('pos.fulfillment.telah-diproses', $this->subTabResi($resi))
            ->with($flashKey, $msg);
    }

    /**
     * Inti proses SATU pesanan. Sumber kebenaran tunggal yang dipakai bersama oleh proses
     * massal server-side (fallback tanpa JS) dan endpoint AJAX per-pesanan, supaya kedua
     * jalur mustahil berbeda perilaku.
     *
     * Sengaja tidak melempar exception: semua kegagalan dikembalikan sebagai ok=false agar
     * pesanan berikutnya dalam antrean tetap jalan.
     */
    private function prosesSatuPesanan(int $id, PosFulfillmentService $posSvc, ?JubelioOrderLink $link = null): array
    {
        $hasil = [
            'found'        => true,
            'ok'           => false,
            'order_number' => "#{$id}",
            'message'      => '',
            'tracking_no'  => null,
            'delivery_id'  => null,
            'resi_ok'      => [],
            'resi_gagal'   => [],
        ];

        $so = SalesOrder::with('items.product', 'customer')->find($id);
        if (!$so) {
            $hasil['found'] = false;

            return $hasil;
        }

        $hasil['order_number'] = $so->order_number;

        // Marketplace → rantai WMS Jubelio; selain itu → jalur invoice ERP.
        $link ??= JubelioOrderLink::where('sales_order_id', $id)->first();
        if ($link) {
            $res              = app(JubelioFulfillmentService::class)->process($link);
            $hasil['ok']      = (bool) $res['success'];
            $hasil['message'] = $res['message'];

            if ($res['success']) {
                $fresh = $link->fresh();
                $this->createMarketplaceDelivery($fresh); // SJ ERP sekaligus
                $hasil['tracking_no'] = $fresh->tracking_no;
            }

            return $hasil;
        }

        try {
            $posSvc->createInvoiceFromSalesOrder($so); // tanpa pickup_code → ambil-toko otomatis ditolak
            $so->forceFill(['process_error' => null, 'process_failed_at' => null])->save();

            // Resi ikut terbit di sini — sama seperti proses satuan, supaya hasil kedua
            // jalur itu identik dan tidak ada pesanan yang tertinggal tanpa resi hanya
            // karena operator memakai centang massal.
            $resi                = $this->terbitkanResi($so);
            $hasil['resi_ok']    = $resi['ok'];
            $hasil['resi_gagal'] = $resi['gagal'];

            $sj = SalesDelivery::where('sales_order_id', $so->id)
                ->where('status', '!=', 'void')->latest('id')->first();
            if ($sj) {
                $hasil['delivery_id'] = $sj->id;
                $hasil['tracking_no'] = $sj->tracking_number ?: null;
            }

            $hasil['ok']      = true;
            $hasil['message'] = 'Faktur + Surat Jalan dibuat.';
        } catch (\Throwable $e) {
            $so->forceFill(['process_error' => $e->getMessage(), 'process_failed_at' => now()])->save();
            $hasil['message'] = $e->getMessage();
        }

        return $hasil;
    }

    /**
     * Proses SATU pesanan lewat AJAX — dipakai tombol "✅ Proses" massal, yang kini menembak
     * pesanan satu per satu dari browser dan bukan lagi mengirim seluruh centang sekaligus.
     *
     * Alasannya: jalur lama menjalankan SEMUA pesanan di dalam satu request web, padahal tiap
     * pesanan marketplace butuh 6–9 panggilan HTTP berurutan ke Jubelio (terukur 5–15 detik).
     * Lebih dari ~4 pesanan menembus `fastcgi_read_timeout` nginx (default 60 dtk) → operator
     * melihat "502" lalu menekan Proses lagi padahal request lama MASIH berjalan (timer PHP
     * tidak menghitung waktu tunggu jaringan). Proses-proses itu menumpuk dan membanjiri
     * Jubelio sampai membalas HTTP 500 — 450 dari 495 error sepekan menumpuk di jam 08:00.
     * Satu pesanan per request membuat tiap request selesai jauh di bawah batas, dan browser
     * yang memberi jeda antar pesanan.
     */
    public function prosesAjax(Request $request, int $so, PosFulfillmentService $posSvc): JsonResponse
    {
        $hasil = $this->prosesSatuPesanan($so, $posSvc);

        if (!$hasil['found']) {
            return response()->json([
                'ok'           => false,
                'order_number' => "#{$so}",
                'message'      => 'Pesanan tidak ditemukan.',
            ], 404);
        }

        // Sebab kegagalan resi digabung ke message supaya operator melihatnya langsung di
        // baris progres, tanpa harus membuka pesanan satu per satu.
        $pesanResi = $this->pesanResi(['ok' => $hasil['resi_ok'], 'gagal' => $hasil['resi_gagal']]);

        return response()->json([
            'ok'           => $hasil['ok'],
            'order_number' => $hasil['order_number'],
            'message'      => trim($hasil['message'] . $pesanResi),
            'tracking_no'  => $hasil['tracking_no'] ?: ($hasil['resi_ok'][0] ?? null),
            'delivery_id'  => $hasil['delivery_id'],
            'resi_gagal'   => (bool) $hasil['resi_gagal'],
        ]);
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
     * Cetak resi marketplace (label resmi Jubelio). Ambil URL report dari Jubelio, pastikan
     * labelnya benar-benar bisa dibuka, baru tampilkan SAME-TAB. URL di-cache di link agar
     * cetak ulang tak selalu menembak API.
     *
     * Tanda "sudah dicetak" baru dipasang SETELAH label terbukti keluar: report Jubelio bisa
     * membalas "Error. An error occurred while processing your request." (HTTP 500). Bila begitu,
     * ERP mencetak label resinya SENDIRI (nomor resi + penerima dari Jubelio) supaya paket
     * tetap bisa dikirim — lihat labelResiErp().
     *
     * `?erp=1` (tombol "Label ERP") langsung mencetak label ERP tanpa menyentuh report Jubelio.
     * Perlu karena report Jubelio juga bisa MACET tanpa error (halaman terbuka, label tak
     * pernah jadi: "0 pages loaded") — kasus yang tak terdeteksi dari sisi server.
     */
    public function cetakResiJubelio(Request $request, int $so, JubelioClient $client)
    {
        $link = JubelioOrderLink::where('sales_order_id', $so)->firstOrFail();

        if ($request->boolean('erp')) {
            if (trim((string) $link->tracking_no) === '') {
                return back()->with('error', 'Pesanan ' . ($link->jubelio_salesorder_no ?: $so) . ' belum punya nomor resi — label ERP belum bisa dicetak.');
            }
            return $this->labelResiErp(collect([$link]), $client);
        }

        $respon = $link->j_label_url ? $this->bukaLabelJubelio($link->j_label_url) : null;

        if (!$respon) {
            // Cache kosong atau URL lama gagal → minta URL baru sekali.
            $res = $client->getShippingLabelUrl((int) $link->jubelio_salesorder_id);
            $url = data_get($res, 'data.url');
            if ($res['success'] && $url) {
                $respon = $this->bukaLabelJubelio($url);
                $link->forceFill(['j_label_url' => $respon ? $url : null])->save();
            }
        }

        if (!$respon) {
            if (trim((string) $link->tracking_no) === '') {
                return back()->with('error', 'Label resi ' . ($link->jubelio_salesorder_no ?: $so) . ' gagal dibuat oleh Jubelio dan nomor resinya belum terbit. Pesanan TIDAK ditandai sudah dicetak — coba lagi beberapa saat lagi.');
            }
            return $this->labelResiErp(collect([$link]), $client);
        }

        return $this->tampilkanLabelJubelio(collect([$link]), $respon);
    }

    /**
     * Cetak resi marketplace MASSAL: gabungkan beberapa pesanan marketplace menjadi SATU URL
     * report Jubelio (endpoint shipping-label menerima ids[] jamak → 1 PDF banyak label).
     * Bila label terbukti keluar, tandai tiap pesanan "sudah dicetak" lalu tampilkan SAME-TAB.
     * SO tanpa link/resi dilewati.
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

        if ($request->boolean('erp')) {
            return $this->labelResiErp($links->values(), $client);
        }

        $res = $client->getShippingLabelUrl($links->pluck('jubelio_salesorder_id')->map(fn ($v) => (int) $v)->all());
        $url = data_get($res, 'data.url');
        $respon = ($res['success'] && $url) ? $this->bukaLabelJubelio($url) : null;

        if (!$respon) {
            // Label resmi Jubelio gagal → cetak label ERP (lihat cetakResiJubelio).
            return $this->labelResiErp($links->values(), $client);
        }

        return $this->tampilkanLabelJubelio($links->values(), $respon);
    }

    /**
     * Tampilkan label Jubelio yang sudah lolos bukaLabelJubelio().
     *
     * Langsung ditandai sudah dicetak. Halaman penampil report (yang biasa dikirim Jubelio) bisa
     * macet "0 pages loaded" tanpa error, maka ia dibingkai di halaman ERP bersama tombol
     * "Cetak Label ERP" — packing tetap bisa mencetak label lewat jalur itu. (Sempat ditandai
     * lewat tombol konfirmasi, tapi packing tak pernah menekannya: semua resi tampak belum dicetak.)
     */
    private function tampilkanLabelJubelio(\Illuminate\Support\Collection $links, array $hasil)
    {
        $this->tandaiDicetak($links);

        if (isset($hasil['pdf'])) {
            return response($hasil['pdf'], 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="resi.pdf"',
            ]);
        }

        $kembali = url()->previous();
        if (parse_url($kembali, PHP_URL_HOST) !== request()->getHost() || str_contains($kembali, '/jubelio-resi')) {
            $kembali = route('pos.fulfillment.telah-diproses');
        }

        return view('erp.pos.fulfillment.resi-marketplace-jubelio', [
            'url'     => $hasil['viewer'],
            'links'   => $links,
            'kembali' => $kembali,
        ]);
    }

    /** Tandai sudah dicetak sekali per pesanan, agar tak menimpa toggle manual. */
    private function tandaiDicetak(\Illuminate\Support\Collection $links): void
    {
        foreach ($links as $link) {
            if (!$link->resi_printed_at) {
                $link->forceFill(['resi_printed_at' => now()])->save();
            }
        }
    }

    /**
     * Label resi CADANGAN buatan ERP untuk pesanan marketplace, dipakai saat report label
     * Jubelio error. Nomor resi & kurir dari link, penerima & berat dari detail pesanan
     * Jubelio (pelanggan SO marketplace = kanalnya, bukan pembeli), barang dari Surat Jalan.
     * Halaman ini tampil = label keluar → pesanan ditandai sudah dicetak.
     */
    private function labelResiErp(\Illuminate\Support\Collection $links, JubelioClient $client)
    {
        $profile = \App\Models\BusinessProfile::instance();

        $labels = $links->map(function (JubelioOrderLink $link) use ($client, $profile) {
            $sj = SalesDelivery::with(['items.product', 'order'])
                ->where('sales_order_id', $link->sales_order_id)
                ->where('status', '!=', 'void')
                ->latest('id')->first();

            $d = data_get($client->getOrder((int) $link->jubelio_salesorder_id), 'data', []);

            $warehouse = \App\Core\Inventory\Warehouse::find($sj?->warehouse_id ?? SalesOrder::find($link->sales_order_id)?->warehouse_id);
            $origin = [
                'name'    => $warehouse?->contact_name ?: $profile->name,
                'phone'   => $warehouse?->contact_phone ?: ($profile->phone ?: $profile->whatsapp),
                'address' => collect([$warehouse?->address, $warehouse?->city, $warehouse?->province, $warehouse?->postal_code])->filter()->implode(', '),
            ];
            $dest = [
                'name'    => data_get($d, 'shipping_full_name') ?: ($link->snap_customer ?: '-'),
                'phone'   => data_get($d, 'shipping_phone') ?: '-',
                'address' => data_get($d, 'shipping_address') ?: '-',
            ];

            // Dokumen label dirakit di memori saja — tak ada yang disimpan ke SJ.
            $delivery = $sj ? clone $sj : new SalesDelivery(['delivery_date' => now()]);
            if (!$sj) $delivery->setRelation('items', collect());
            $delivery->tracking_number       = $link->tracking_no;
            $delivery->courier_name          = $link->shipper ?: data_get($d, 'courier');
            $delivery->shipping_courier_code = null;
            $delivery->provider_order_id     = $link->jubelio_salesorder_no;

            $kg = (float) data_get($d, 'total_weight_in_kg', 0);
            $weight = $kg > 0
                ? $kg * 1000
                : ($sj ? app(\App\Modules\Shipping\Services\PackageDefaults::class)->weightFor($sj->order, $delivery->items) : 0);

            return compact('delivery', 'origin', 'dest', 'weight');
        });

        $this->tandaiDicetak($links);

        return view('erp.pos.fulfillment.resi-marketplace-erp', compact('labels'));
    }

    /**
     * Buka URL report label Jubelio dari server untuk memastikan labelnya benar-benar jadi.
     * PDF → ['pdf' => isi] (diteruskan langsung, tak perlu Jubelio merender dua kali). Balasan
     * 2xx lain → ['viewer' => url] (halaman penampil report). Gagal/galat → null.
     */
    private function bukaLabelJubelio(string $url): ?array
    {
        @set_time_limit(120);

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(55)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Label resi Jubelio tak bisa dibuka: ' . $e->getMessage());
            return null;
        }

        if (!$res->successful()) {
            Log::warning('Label resi Jubelio membalas HTTP ' . $res->status());
            return null;
        }

        if (str_contains(strtolower((string) $res->header('Content-Type')), 'pdf')) {
            return ['pdf' => $res->body()];
        }

        return ['viewer' => $url];
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
