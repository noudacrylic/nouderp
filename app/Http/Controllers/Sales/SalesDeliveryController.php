<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Core\Inventory\Product;
use App\Models\Customer;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;

use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Concerns\InlinesPrintAssets;

class SalesDeliveryController extends Controller
{
    use InlinesPrintAssets;

    public function index(Request $request)
    {
        $query = SalesDelivery::with(['order.customer', 'invoice.customer']);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('delivery_number', 'like', "%$search%")
                    ->orWhere('tracking_number', 'like', "%$search%")
                    ->orWhere('courier_name', 'like', "%$search%")
                    ->orWhereHas('order.customer', function ($c) use ($search) {
                        $c->where('name', 'like', "%$search%");
                    })
                    ->orWhereHas('invoice.customer', function ($c) use ($search) {
                        $c->where('name', 'like', "%$search%");
                    });
            });
        }

        // FILTER PENGIRIMAN — metode kirim / status booking resi.
        if ($request->pengiriman) {
            match ($request->pengiriman) {
                'ambil_toko' => $query->where('delivery_method', 'ambil_toko'),
                'kurir'      => $query->where(fn ($q) => $q->where('delivery_method', '<>', 'ambil_toko')
                                                          ->orWhereNull('delivery_method')),
                'booked'     => $query->whereNotNull('provider_order_id')->where('shipping_status', 'booked'),
                'not_booked' => $query->where('delivery_method', '<>', 'ambil_toko')
                                      ->where(fn ($q) => $q->whereNull('provider_order_id')
                                                           ->orWhere('shipping_status', '<>', 'booked')),
                default      => null,
            };
        }

        $deliveries = $query->orderByDesc('id')->paginate(per_page_size())->withQueryString();
        return view('erp.sales.deliveries.index', compact('deliveries'));
    }

    public function create($soId = null)
    {
        $order = null;
        $items = [];
        if ($soId) {
            // Partial delivery: TIDAK lagi memblokir SJ ke-2. Hitung sisa per baris.
            $order = SalesOrder::with(['customer', 'items.product'])->findOrFail($soId);

            $warehouseId = $order->warehouse_id ?? 1;
            $remainingMap = $this->deliveryRemainingMap($order);
            $engine = app(\App\Core\Inventory\InventoryEngine::class);

            foreach ($order->items as $soItem) {
                $product = $soItem->product;
                if (!$product) continue;
                if (in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) continue;

                $isBundle = ($product->sale_type === 'bundle') || ($product->type === 'bundle');
                $lineBase = $soItem->qty * ($soItem->conversion_to_base ?? 1);

                if ($isBundle) {
                    foreach ($this->getBundleComponents($product->id) as $component) {
                        $cp = Product::find($component->product_id);
                        if (!$cp) continue;
                        $ordered = $component->qty * $lineBase;
                        $this->pushDeliveryLine($items, $soItem->id, $cp, $ordered, $remainingMap, $engine, $warehouseId);
                    }
                } else {
                    $this->pushDeliveryLine($items, $soItem->id, $product, $lineBase, $remainingMap, $engine, $warehouseId);
                }
            }

            if (empty($items)) {
                // Semua barang sudah terkirim → jangan buka form create, tampilkan
                // surat jalan terakhir SO ini (kalau ada).
                $lastDelivery = $order->deliveries()->orderByDesc('id')->first();
                if ($lastDelivery) {
                    return redirect()
                        ->route('sales.deliveries.show', $lastDelivery->id)
                        ->with('info', 'Semua barang dari SO ini sudah terkirim. Menampilkan surat jalan terakhir.');
                }
                return redirect()
                    ->route('sales.deliveries.index')
                    ->with('warning', 'Semua barang dari SO ini sudah terkirim.');
            }
        }

        $salesOrders = SalesOrder::with('customer')
            ->where('status', SalesOrderStatus::CONFIRMED->value)
            ->orderBy('order_date', 'desc')
            ->get();

        return view('erp.sales.deliveries.create', compact('order', 'salesOrders', 'items'));
    }

    public function store(Request $request)
    {
        $soId = $request->sales_order_id;
        $so = SalesOrder::with(['items.product'])->findOrFail($soId);

        $method = in_array($so->delivery_method, ['kurir', 'instant', 'ambil_toko'], true) ? $so->delivery_method : 'kurir';

        // Ambil di Toko → wajib verifikasi booking code yang cocok dengan SO.
        if ($method === 'ambil_toko') {
            if ($so->pickup_status === 'picked_up') {
                return back()->with('error', 'Barang SO ini sudah tercatat diambil.');
            }
            $code = strtoupper(trim((string) $request->input('pickup_code', '')));
            if ($code === '' || $code !== strtoupper((string) $so->pickup_code)) {
                return back()->withInput()->with('error', 'Booking code tidak cocok dengan kode di SO. Periksa kembali.');
            }
        }

        // Validasi partial: qty tiap baris tidak boleh melebihi sisa yang belum dikirim.
        $remainingMap = $this->deliveryRemainingMap($so);
        foreach (($request->items ?? []) as $itemData) {
            $qty = (float) ($itemData['qty'] ?? 0);
            if ($qty <= 0) continue;

            $soItemId = $itemData['sales_order_item_id'] ?? null;
            $pid      = $itemData['product_id'] ?? null;
            $remaining = $remainingMap[$soItemId][$pid] ?? null;

            if ($remaining !== null && $qty > $remaining + 0.0001) {
                $p = Product::find($pid);
                $name = $p->name ?? "ID {$pid}";
                return back()
                    ->withInput()
                    ->with('error', "Qty kirim untuk \"{$name}\" ({$qty}) melebihi sisa yang belum dikirim ({$remaining}).");
            }
        }

        $delivery = DB::transaction(function () use ($request, $so, $method) {
            $delivery = SalesDelivery::create([
                'delivery_number' => NumberGeneratorService::generate('DOSO'),
                'sales_order_id' => $so->id,
                'reference_type' => 'sales_order',
                'reference_id' => $so->id,
                'warehouse_id' => $request->warehouse_id ?? $so->warehouse_id ?? 1,
                'delivery_date' => $request->delivery_date ?? now(),
                'delivery_method' => $method,
                'courier_name' => $method === 'ambil_toko' ? 'Ambil di Toko' : ($request->courier_name ?: trim(strtoupper((string)($so->shipping_courier_code ?? '')).' '.(string)($so->shipping_service_name ?? ''))),
                'shipping_courier_code' => $method === 'ambil_toko' ? null : ($so->shipping_courier_code ?: null),
                'shipping_service_code' => $method === 'ambil_toko' ? null : ($so->shipping_service_code ?: null),
                'tracking_number' => $method === 'ambil_toko' ? null : $request->tracking_number,
                'status' => 'draft',
            ]);

            foreach (($request->items ?? []) as $itemData) {
                if ((float) ($itemData['qty'] ?? 0) > 0) {
                    SalesDeliveryItem::create([
                        'sales_delivery_id' => $delivery->id,
                        'sales_order_item_id' => $itemData['sales_order_item_id'],
                        'product_id' => $itemData['product_id'],
                        'qty' => $itemData['qty']
                    ]);
                }
            }

            return $delivery;
        });

        // action: draft = simpan saja | post = simpan + posting | print = simpan + posting + cetak
        $action = $request->input('action', 'draft');

        if (in_array($action, ['post', 'print'], true)) {
            try {
                app(SalesDeliveryService::class)->post($delivery->id);
            } catch (\Exception $e) {
                return redirect()
                    ->route('sales.deliveries.show', $delivery->id)
                    ->with('error', 'Surat jalan tersimpan sebagai draft, tetapi gagal posting: ' . $e->getMessage());
            }

            // Ambil di Toko: tandai SO sudah diambil setelah SJ diposting (barang diserahkan).
            if ($method === 'ambil_toko' && $so->pickup_status !== 'picked_up') {
                $so->update(['pickup_status' => 'picked_up', 'picked_up_at' => now()]);
            }

            if ($action === 'print') {
                return redirect()->route('sales.deliveries.print', $delivery->id);
            }

            return redirect()
                ->route('sales.deliveries.show', $delivery->id)
                ->with('success', 'Surat jalan disimpan & diposting.');
        }

        return redirect()
            ->route('sales.deliveries.index')
            ->with('success', 'Surat jalan disimpan sebagai draft.');
    }

    public function createFromInvoice($invoiceId)
    {
        // Nanti kita buat, qty mengikuti invoice_item qty.
    }

    public function show($id)
    {
        $delivery = SalesDelivery::with([
            'order.customer',
            'invoice.customer',
            'items.product'
        ])->findOrFail($id);

        return view('erp.sales.deliveries.show', compact('delivery'));
    }

    public function edit($id)
    {
        $delivery = SalesDelivery::with([
            'order.customer',
            'items.product'
        ])->findOrFail($id);

        return view('erp.sales.deliveries.edit', compact('delivery'));
    }

    /** Lihat tracking resi (on-demand, ambil status dari Biteship saat dibuka). */
    public function trackShipment($id, \App\Modules\Shipping\Providers\BiteshipProvider $biteship)
    {
        $delivery = SalesDelivery::with(['order.customer', 'invoice.customer'])->findOrFail($id);

        if (!$delivery->provider_order_id) {
            return redirect()->route('sales.deliveries.show', $delivery->id)
                ->with('error', 'Belum ada resi untuk dilacak. Lakukan Booking Resi dulu.');
        }

        $result = $biteship->track($delivery->provider_order_id);

        return view('erp.sales.deliveries.tracking', compact('delivery', 'result'));
    }

    /** Halaman cetak label resi (pengirim/penerima + nomor resi + barcode). */
    public function printResi($id)
    {
        $delivery = SalesDelivery::with(['order.customer', 'invoice.customer', 'items.product'])->findOrFail($id);

        if (!$delivery->isBooked() || !$delivery->tracking_number) {
            return redirect()->route('sales.deliveries.show', $delivery->id)
                ->with('error', 'Belum ada resi untuk dicetak. Lakukan Booking Resi dulu.');
        }

        // Tandai sudah dicetak (sekali) — untuk filter & pindah ke "Selesai" H+1.
        if (!$delivery->resi_printed_at) {
            $delivery->forceFill(['resi_printed_at' => now()])->save();
        }

        [$origin, $dest] = $this->resiAddresses($delivery);

        return view('erp.sales.deliveries.resi', compact('delivery', 'origin', 'dest'));
    }

    /** Cetak resi MASSAL (banyak label sekaligus) dari POS → Telah Diproses. */
    public function printResiBulk(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return redirect()->route('pos.fulfillment.telah-diproses')->with('error', 'Tidak ada resi yang dipilih untuk dicetak.');
        }

        $deliveries = SalesDelivery::with(['order.customer', 'invoice.customer', 'items.product'])
            ->whereIn('id', $ids)
            ->whereNotNull('tracking_number')
            ->get()
            ->sortBy(fn ($d) => $ids->search($d->id))
            ->values();

        if ($deliveries->isEmpty()) {
            return redirect()->route('pos.fulfillment.telah-diproses')->with('error', 'Tidak ada resi yang bisa dicetak (belum ada nomor resi).');
        }

        // Tandai sudah dicetak (sekali) — untuk filter & pindah ke "Selesai" H+1.
        $deliveries->each(function ($d) {
            if (!$d->resi_printed_at) {
                $d->forceFill(['resi_printed_at' => now()])->save();
            }
        });

        // [delivery, origin, dest] per label.
        $labels = $deliveries->map(fn ($d) => [
            'delivery' => $d,
            'addr'     => $this->resiAddresses($d),
        ]);

        return view('erp.sales.deliveries.resi-bulk', compact('labels'));
    }

    /** Label pengiriman TANPA nomor resi (pengirim/penerima + barang) — untuk kurir manual. */
    public function printLabel($id)
    {
        $delivery = SalesDelivery::with(['order.customer', 'invoice.customer', 'items.product'])->findOrFail($id);

        if ($delivery->delivery_method === 'ambil_toko') {
            return redirect()->route('sales.deliveries.show', $delivery->id)
                ->with('error', 'Metode Ambil di Toko tidak perlu label pengiriman.');
        }

        [$origin, $dest] = $this->resiAddresses($delivery);

        return view('erp.sales.deliveries.label', compact('delivery', 'origin', 'dest'));
    }

    /** Cetak label MASSAL tanpa resi (dari POS → Telah Diproses). */
    public function printLabelBulk(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return redirect()->route('pos.fulfillment.telah-diproses')->with('error', 'Tidak ada pengiriman yang dipilih untuk dicetak.');
        }

        $deliveries = SalesDelivery::with(['order.customer', 'invoice.customer', 'items.product'])
            ->whereIn('id', $ids)
            ->where('delivery_method', '!=', 'ambil_toko')
            ->get()
            ->sortBy(fn ($d) => $ids->search($d->id))
            ->values();

        if ($deliveries->isEmpty()) {
            return redirect()->route('pos.fulfillment.telah-diproses')->with('error', 'Tidak ada label yang bisa dicetak.');
        }

        $labels = $deliveries->map(fn ($d) => [
            'delivery' => $d,
            'addr'     => $this->resiAddresses($d),
        ]);

        return view('erp.sales.deliveries.label-bulk', compact('labels'));
    }

    /** Bangun alamat pengirim/penerima untuk label resi. */
    private function resiAddresses(SalesDelivery $delivery): array
    {
        $warehouse = \App\Core\Inventory\Warehouse::find($delivery->warehouse_id);
        $profile   = \App\Models\BusinessProfile::instance();
        $customer  = $delivery->order?->customer ?: $delivery->invoice?->customer;

        $origin = [
            'name'    => $warehouse->contact_name ?: $profile->name,
            'phone'   => $warehouse->contact_phone ?: ($profile->phone ?: $profile->whatsapp),
            'address' => collect([$warehouse->address, $warehouse->city, $warehouse->province, $warehouse->postal_code])->filter()->implode(', '),
        ];
        $dest = [
            'name'    => $customer->name ?? '-',
            'phone'   => $customer->recipient_phone ?? $customer->phone ?? '-',
            'address' => $customer
                ? (collect([$customer->shipping_address, $customer->district, $customer->city, $customer->province, $customer->postal_code])->filter()->implode(', ') ?: $customer->address)
                : '-',
        ];

        return [$origin, $dest];
    }

    public function update(Request $request, $id)
    {
        $delivery = SalesDelivery::findOrFail($id);

        if ($delivery->status == 'posted') {
            $delivery->update([
                'courier_name' => $request->courier_name,
                'tracking_number' => $request->tracking_number
            ]);
        } else {
            $delivery->update([
                'delivery_date' => $request->delivery_date,
                'courier_name' => $request->courier_name,
                'tracking_number' => $request->tracking_number
            ]);
        }

        return redirect(list_url('sales.deliveries.index'))
            ->with('success', 'Delivery updated successfully');
    }


    /**
     * Default cara ambil paket (pickup/dropoff) untuk sebuah kurir dari Settings Biteship.
     */
    public function collectionDefault(string $courierCode): string
    {
        $cfg = \App\Models\ShippingSetting::for('biteship')->config['courier_collection'] ?? [];
        $val = $cfg[$courierCode] ?? 'pickup';
        return in_array($val, ['pickup', 'dropoff'], true) ? $val : 'pickup';
    }

    /**
     * Data ringkas untuk popup "Cek Ulang Biaya Pengiriman" sebelum generate resi
     * (dipakai di POS → Pemrosesan Pesanan → Telah Diproses, juga reusable).
     * Berat default = Σ(weight_gram × qty) item fisik; dimensi default dari SO/Invoice.
     */
    public function shipInfo($id)
    {
        $delivery = SalesDelivery::with(['order.customer', 'invoice.customer', 'items.product'])->findOrFail($id);
        $src      = $delivery->order ?: $delivery->invoice;
        $customer = $delivery->order?->customer ?: $delivery->invoice?->customer;

        // Berat bawaan: hasil timbang kardus (sub-tab "Perlu Ukur") kalau ada, baru jatuh ke
        // penjumlahan berat master produk. Popup ini SELALU mengirim weight_gram sebagai
        // override — kalau di sini diisi taksiran, hasil timbang operator ketimpa diam-diam.
        // Ditaksir dari BARIS SURAT JALAN ini (kiriman parsial hanya membawa sebagian barang),
        // bukan dari seluruh isi SO.
        $defaultWeight = app(\App\Modules\Shipping\Services\PackageDefaults::class)
            ->weightFor($src, $delivery->items);

        $courierCode = $src->shipping_courier_code ?? null;
        $provider    = $src->shipping_provider ?? null;

        // Jubelio mengenali kurir lewat ID angka — menampilkannya ("17 J&T Cargo") cuma bikin
        // bingung, jadi untuk provider itu cukup nama layanannya.
        $courierLabel = $provider === 'jubelio_shipment'
            ? ($src->shipping_service_name ?: 'Jubelio Shipment')
            : ($courierCode ? (strtoupper($courierCode) . ' ' . ($src->shipping_service_name ?? '')) : null);

        return response()->json([
            'delivery_number' => $delivery->delivery_number,
            'courier_code'    => $courierCode,
            'service_code'    => $src->shipping_service_code ?? null,
            'courier_label'   => $courierLabel,
            'provider'        => $provider,
            'mode'            => $delivery->delivery_method === 'instant' ? 'instant' : 'regular',
            'warehouse_id'    => $delivery->warehouse_id,
            // Tiap agregator punya kamus wilayahnya sendiri. Kirim customer_id juga supaya
            // cek ongkir bisa mengambil area yang benar untuk provider yang sedang dipakai —
            // `area` di bawah khusus Biteship dan kosong untuk pesanan Jubelio.
            'customer_id'     => $customer?->id,
            'area'            => $customer?->biteship_area_id,
            'dest_lat'        => ($customer && $customer->latitude  !== null) ? (float) $customer->latitude  : null,
            'dest_lng'        => ($customer && $customer->longitude !== null) ? (float) $customer->longitude : null,
            'weight_gram'     => max(1, $defaultWeight),
            'package_length'  => (float) ($src->package_length ?? 0) ?: null,
            'package_width'   => (float) ($src->package_width  ?? 0) ?: null,
            'package_height'  => (float) ($src->package_height ?? 0) ?: null,
            'is_ambil_toko'   => $delivery->delivery_method === 'ambil_toko',
            'booked'          => $delivery->isBooked(),
        ]);
    }

    /**
     * Fase 3 — Booking resi ke Biteship dari Surat Jalan.
     * Origin = gudang, tujuan = customer, kurir/layanan dari SO/Invoice, berat/dimensi dari produk.
     */
    public function bookShipment(Request $request, $id, \App\Modules\Shipping\Services\ShipmentBookingService $booking)
    {
        $delivery = SalesDelivery::findOrFail($id);

        $result = $booking->book($delivery, $request->input('collection_method'), [
            'weight_gram'    => $request->input('weight_gram'),
            'package_length' => $request->input('package_length'),
            'package_width'  => $request->input('package_width'),
            'package_height' => $request->input('package_height'),
        ]);

        if ($result['level'] === 'success') {
            return back()->with('success', 'Resi berhasil dibuat: ' . ($result['tracking'] ?: 'menunggu dari kurir') . '.');
        }
        if ($result['level'] === 'warning') {
            // Resi SUDAH terbit, yang gagal jurnalnya — sebut akun provider yang benar,
            // bukan "Saldo Biteship" yang sejak Agustus 2026 tidak dipakai lagi.
            $label = \App\Models\FreightSetting::providerLabel((string) $delivery->shipping_provider);

            return back()->with('error', $result['message'] . " Cek akun Saldo {$label} di Settings → Pengaturan Ongkir.");
        }
        return back()->with('error', 'Booking resi gagal — ' . $result['message']);
    }

    /**
     * Helper universal: ambil komponen bundle dari salah satu dari dua tabel.
     * Coba product_bundles (qty_required) dulu, fallback ke bundle_components (qty).
     */
    private function getBundleComponents(int $productId): \Illuminate\Support\Collection
    {
        // 1. Prioritaskan tabel bundle_components (model BundleComponent, field qty)
        $rows = \App\Core\Inventory\BundleComponent::where('bundle_product_id', $productId)->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn($c) => (object)[
                'product_id' => $c->component_product_id,
                'qty'        => $c->qty ?? 1,
            ]);
        }

        // 2. Fallback: tabel product_bundles (model ProductBundle, field qty_required)
        $rows = \App\Core\Inventory\ProductBundle::where('bundle_product_id', $productId)->get();

        return $rows->map(fn($c) => (object)[
            'product_id' => $c->component_product_id,
            'qty'        => $c->qty_required ?? 1,
        ]);
    }

    /**
     * Peta sisa qty (base unit) per [sales_order_item_id][product_id] untuk 1 SO.
     * sisa = ordered (expand bundle) − total terkirim (SJ non-void). Jasa/non_stock di-skip.
     */
    private function deliveryRemainingMap(SalesOrder $order): array
    {
        // Terkirim per (soItemId, productId)
        $deliveredMap = [];
        $deliveries = SalesDelivery::where('sales_order_id', $order->id)
            ->where('status', '!=', 'void')
            ->with('items')
            ->get();
        foreach ($deliveries as $d) {
            foreach ($d->items as $it) {
                $deliveredMap[$it->sales_order_item_id][$it->product_id] =
                    ($deliveredMap[$it->sales_order_item_id][$it->product_id] ?? 0) + (float) $it->qty;
            }
        }

        // Ordered − terkirim = sisa
        $remaining = [];
        foreach ($order->items as $soItem) {
            $product = $soItem->product;
            if (!$product) continue;
            if (in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) continue;

            $lineBase = $soItem->qty * ($soItem->conversion_to_base ?? 1);
            $isBundle = ($product->sale_type === 'bundle') || ($product->type === 'bundle');

            if ($isBundle) {
                foreach ($this->getBundleComponents($product->id) as $c) {
                    $ordered = $c->qty * $lineBase;
                    $remaining[$soItem->id][$c->product_id] = $ordered - ($deliveredMap[$soItem->id][$c->product_id] ?? 0);
                }
            } else {
                $remaining[$soItem->id][$product->id] = $lineBase - ($deliveredMap[$soItem->id][$product->id] ?? 0);
            }
        }

        return $remaining;
    }

    /**
     * Tambah 1 baris ke daftar item form delivery, lengkap dengan sisa & stok tersedia.
     * Skip jika sisa ≤ 0 (baris sudah terkirim penuh).
     */
    private function pushDeliveryLine(array &$items, $soItemId, $product, float $ordered, array $remainingMap, $engine, $warehouseId): void
    {
        $remaining = $remainingMap[$soItemId][$product->id] ?? $ordered;
        if ($remaining <= 0.00001) return;

        $delivered = $ordered - $remaining;
        $available = $engine->onHand($product->id, $warehouseId);

        $items[] = [
            'sales_order_item_id' => $soItemId,
            'product_id'          => $product->id,
            'name'                => $product->name,
            'sku'                 => $product->sku,
            'ordered'             => $ordered,
            'delivered'           => $delivered,
            'remaining'           => $remaining,
            'available'           => $available,
            'qty'                 => $remaining, // default kirim = sisa
        ];
    }

    public function post($id)
    {
        try {
            app(SalesDeliveryService::class)->post($id);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('sales.deliveries.show', $id)
            ->with('success', 'Delivery posted');
    }

    public function void($id)
    {
        $delivery = SalesDelivery::with(['items.product', 'invoice'])->findOrFail($id);

        try {
            app(\App\Modules\Sales\Services\SalesDeliveryService::class)->voidDelivery($delivery);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal void Surat Jalan: ' . $e->getMessage());
        }

        return back()->with('success', 'Surat Jalan berhasil di-void.');
    }

    public function print($id)
    {
        $delivery = SalesDelivery::with([
            'order.customer',
            'order.items.product',
            'items.product',
            'items.salesOrderItem',
        ])->findOrFail($id);

        if ($delivery->status !== 'posted') {
            abort(403, 'Delivery must be posted before printing');
        }

        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        return view('erp.sales.deliveries.print', compact('delivery', 'profile'));
    }

    /** Cetak banyak Surat Jalan sekaligus dalam 1 halaman (page-break per dokumen). */
    public function printBulk(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return redirect()->route('sales.deliveries.index')->with('error', 'Tidak ada surat jalan yang dipilih untuk dicetak.');
        }

        $deliveries = SalesDelivery::with([
                'order.customer', 'order.items.product', 'items.product', 'items.salesOrderItem',
            ])
            ->whereIn('id', $ids)
            ->where('status', 'posted')
            ->get()
            ->sortBy(fn ($d) => $ids->search($d->id))
            ->values();

        if ($deliveries->isEmpty()) {
            return redirect()->route('sales.deliveries.index')->with('error', 'Surat jalan terpilih tidak dapat dicetak.');
        }

        $profile  = \App\Models\BusinessProfile::instance()->load('bankAccounts');
        $indexUrl = route('pos.fulfillment.telah-diproses');

        return view('erp.sales.deliveries.print-bulk', compact('deliveries', 'profile', 'indexUrl'));
    }

    public function downloadPdf($id)
    {
        $delivery = SalesDelivery::with([
            'order.customer',
            'order.items.product',
            'items.product',
            'items.salesOrderItem',
        ])->findOrFail($id);

        if ($delivery->status !== 'posted') {
            abort(403, 'Delivery must be posted before printing');
        }

        $profile = \App\Models\BusinessProfile::instance()->load('bankAccounts');

        $html = view('erp.sales.deliveries.print', [
            'delivery' => $delivery,
            'profile'  => $profile,
            'pdfMode'  => true,
        ])->render();
        $html = $this->inlineLocalAssets($html);

        $bs = \Spatie\Browsershot\Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(0, 0, 0, 0)
            ->emulateMedia('print')
            ->timeout(120)
            ->waitUntilNetworkIdle();

        $nodePath = env('BROWSERSHOT_NODE_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : null);
        $npmPath  = env('BROWSERSHOT_NPM_PATH',  PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\npm.cmd'  : null);
        if ($nodePath) $bs->setNodeBinary($nodePath);
        if ($npmPath)  $bs->setNpmBinary($npmPath);

        // Tanpa ini puppeteer cari Chrome di cache default HOME (kosong di server) → 500.
        // Path absolut Chrome di-set via env pool php-fpm (lihat SlipGajiController).
        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }

        $pdf = $bs->pdf();
        $filename = 'SuratJalan_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $delivery->delivery_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
