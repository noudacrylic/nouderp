<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\SalesQuotationAttachment;
use App\Models\Customer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
// ===== INVENTORY =====
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;

// ===== SERVICES =====
use App\Services\NumberGeneratorService;

class QuotationController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesQuotation::with('customer');

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%$search%")
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('name', 'like', "%$search%");
                    });
            });
        }

        // FILTER UMUR PENAWARAN BELUM DIPROSES — selaras dengan age-badge (belum converted & bukan cancelled).
        // Bucket: < 1 minggu | 1 minggu–1 bulan | 1 bulan–3 bulan | > 3 bulan (umur dari quotation_date).
        if ($request->age) {
            $today = \Carbon\Carbon::today();
            $w1 = $today->copy()->subWeek();      // batas 1 minggu
            $m1 = $today->copy()->subMonth();     // batas 1 bulan
            $m3 = $today->copy()->subMonths(3);   // batas 3 bulan

            // Hanya yang "belum diproses": belum dikonversi ke SO & bukan cancelled.
            $query->whereRaw("LOWER(COALESCE(status, '')) NOT IN (?, ?)", ['converted', 'cancelled']);

            if ($request->age === 'lt_1w') {
                $query->whereDate('quotation_date', '>=', $w1);
            } elseif ($request->age === '1w_1m') {
                $query->whereDate('quotation_date', '<', $w1)->whereDate('quotation_date', '>=', $m1);
            } elseif ($request->age === '1m_3m') {
                $query->whereDate('quotation_date', '<', $m1)->whereDate('quotation_date', '>=', $m3);
            } elseif ($request->age === 'gt_3m') {
                $query->whereDate('quotation_date', '<', $m3);
            }
        }

        $quotations = $query->orderByDesc('id')->get();

        return view('erp.sales.quotations.index', compact('quotations'));
    }

    public function create()
    {
        $customers = Customer::all();
        $products = Product::with('units')->get();
        $warehouses = Warehouse::all();
        $profile = \App\Models\BusinessProfile::instance();
        return view('erp.sales.quotations.create', compact('customers', 'products', 'warehouses', 'profile'));
    }

    public function store(Request $request)
    {
        // dd($request->all());
        $request->validate([
            'customer_id' => 'required',
        ], [
            'customer_id.required' => 'Mohon pilih customer terlebih dahulu.'
        ]);

        DB::beginTransaction();

        try {

            $number = $request->quotation_number ?? NumberGeneratorService::generate('SQ');

            $quotation = SalesQuotation::create([
                'quotation_number' => $number,
                'perihal' => $request->perihal,
                'lampiran' => $request->lampiran,
                'customer_id' => $request->customer_id,
                'warehouse_id' => $request->warehouse_id,
                'delivery_method' => in_array($request->delivery_method, ['kurir','instant','ambil_toko'], true) ? $request->delivery_method : 'kurir',
                'quotation_date' => $request->quotation_date,
                'notes' => $request->notes,
                'opening_text' => $request->opening_text,
                'payment_terms' => $request->payment_terms,
                'show_bank_account' => $request->boolean('show_bank_account'),
                'status' => 'draft',
                'subtotal' => clean_number($request->subtotal),
                'subtotal_product' => clean_number($request->subtotal_product),
                'global_discount_type' => $request->global_discount_type ?? 'nominal',
                'global_discount_value' => clean_number($request->global_discount_value),
                'discount_global' => clean_number($request->global_discount_value), // Fallback for specific field name
                'ppn_percent' => $request->ppn_percent ?? 0,
                'shipping_charge' => $request->delivery_method === 'ambil_toko' ? 0 : clean_number($request->shipping_cost),
                'service_charge' => clean_number($request->service_charge),
                'other_expense' => clean_number($request->selling_expense),
                'grand_total' => clean_number($request->grand_total),
            ]);

            foreach ($request->items as $item) {
                if (empty($item['product_id'])) continue;

                $qty = $item['qty'];
                $price = clean_number($item['unit_price']);
                $discount = clean_number($item['discount_value']);

                $rowTotal = $qty * $price;
                if ($item['discount_type'] == 'percent') {
                    $rowTotal -= ($rowTotal * $discount / 100);
                } else {
                    $rowTotal -= $discount;
                }
                $rowTotal = round($rowTotal); // bulatkan: tanpa koma

                $unit = \App\Core\Inventory\ProductUnit::find($item['unit_id'] ?? null);

                SalesQuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'],
                    'unit_id' => $unit?->id,
                    'unit_name' => $unit?->unit_name,
                    'conversion_to_base' => $unit?->conversion_to_base ?? 1,
                    'description' => $item['description'] ?? null,
                    'qty' => $qty,
                    'unit_price' => $price,
                    'discount_type' => $item['discount_type'],
                    'discount_value' => $discount,
                    'subtotal' => $rowTotal
                ]);
            }

            $this->syncAttachments($quotation, $request);

            DB::commit();

            if ($request->input('_after_save') === 'print') {
                return redirect()->route('sales.quotations.print', $quotation->id);
            }

            return redirect(list_url('sales.quotations.index'))
                ->with('success', 'Penawaran berhasil disimpan.');

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $quotation = \App\Models\SalesQuotation::with('items.product.units')->findOrFail($id);

        return view('erp.sales.quotations.show', compact('quotation'));
    }

    public function edit($id)
    {
        $quotation = SalesQuotation::with(['items.product.units', 'attachments'])->findOrFail($id);

        if ($quotation->status !== 'draft') {
            return back()->with('error', 'Hanya Quotation DRAFT yang boleh diubah');
        }

        $products = Product::with('units')->get();
        $customers = Customer::all();
        $warehouses = Warehouse::all();
        $profile = \App\Models\BusinessProfile::instance();

        $items = $quotation->items;

        return view('erp.sales.quotations.edit', compact(
            'quotation',
            'items',
            'products',
            'customers',
            'warehouses',
            'profile'
        ));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'customer_id' => 'required',
        ], [
            'customer_id.required' => 'Mohon pilih customer terlebih dahulu.'
        ]);

        DB::beginTransaction();

        try {

            $quotation = SalesQuotation::findOrFail($id);

            if ($quotation->status !== 'draft') {
                return back()->with('error', 'Hanya Quotation DRAFT yang boleh diubah');
            }

            $quotation->update([
                'perihal' => $request->perihal,
                'lampiran' => $request->lampiran,
                'customer_id' => $request->customer_id,
                'warehouse_id' => $request->warehouse_id,
                'delivery_method' => in_array($request->delivery_method, ['kurir','instant','ambil_toko'], true) ? $request->delivery_method : 'kurir',
                'quotation_date' => $request->quotation_date,
                'shipping_address' => $request->shipping_address,
                'notes' => $request->notes,
                'opening_text' => $request->opening_text,
                'payment_terms' => $request->payment_terms,
                'show_bank_account' => $request->boolean('show_bank_account'),
                'subtotal' => clean_number($request->subtotal),
                'subtotal_product' => clean_number($request->subtotal_product),
                'global_discount_type' => $request->global_discount_type ?? 'nominal',
                'global_discount_value' => clean_number($request->global_discount_value),
                'discount_global' => clean_number($request->global_discount_value),
                'ppn_percent' => $request->ppn_percent ?? 0,
                'shipping_charge' => $request->delivery_method === 'ambil_toko' ? 0 : clean_number($request->shipping_cost),
                'service_charge' => clean_number($request->service_charge),
                'other_expense' => clean_number($request->selling_expense),
                'grand_total' => clean_number($request->grand_total),
            ]);

            SalesQuotationItem::where('quotation_id', $quotation->id)->delete();

            foreach ($request->items as $item) {
                if (empty($item['product_id'])) continue;

                $qty = $item['qty'];
                $price = clean_number($item['unit_price']);
                $discount = clean_number($item['discount_value']);

                $rowTotal = $qty * $price;
                if ($item['discount_type'] == 'percent') {
                    $rowTotal -= ($rowTotal * $discount / 100);
                } else {
                    $rowTotal -= $discount;
                }
                $rowTotal = round($rowTotal); // bulatkan: tanpa koma

                $unit = \App\Core\Inventory\ProductUnit::find($item['unit_id'] ?? null);

                SalesQuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'],
                    'unit_id' => $unit?->id,
                    'unit_name' => $unit?->unit_name,
                    'conversion_to_base' => $unit?->conversion_to_base ?? 1,
                    'description' => $item['description'] ?? null,
                    'qty' => $qty,
                    'unit_price' => $price,
                    'discount_type' => $item['discount_type'],
                    'discount_value' => $discount,
                    'subtotal' => $rowTotal
                ]);
            }

            $this->syncAttachments($quotation, $request);

            DB::commit();

            if ($request->input('_after_save') === 'print') {
                return redirect()->route('sales.quotations.print', $quotation->id);
            }

            return redirect(list_url('sales.quotations.index'))
                ->with('success', 'Penawaran berhasil diperbarui.');

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function convertToSO($id)
    {
        $quotation = \App\Models\SalesQuotation::with('items')->findOrFail($id);

        // Convert the status
        $quotation->update([
            'status' => 'converted'
        ]);

        // Hitung totals dari items quotation
        $subtotal = 0;
        $totalItemDiscount = 0;

        $so = \App\Modules\Sales\Models\SalesOrder::create([
            'order_number'  => NumberGeneratorService::generate('SO'), // 🔥 Generate nomor SO standar
            'customer_id'   => $quotation->customer_id,
            'warehouse_id'  => $quotation->warehouse_id,
            'quotation_id'  => $quotation->id,
            'order_date'    => now()->toDateString(),
            'notes'         => $quotation->notes,
            'status'        => 'draft',
            'grand_total'   => 0, // Temporary, akan di-update setelah items dibuat
        ]);

        foreach ($quotation->items as $item) {
            $qty       = (float) $item->qty;
            $unitPrice = (float) $item->unit_price;
            // Bulatkan ke rupiah penuh — hindari koma pada total akibat diskon persen.
            $lineSubtotal = round($qty * $unitPrice);

            $discountValue = (float) $item->discount_value;
            if ($item->discount_type === 'percent') {
                $lineDiscount = round($lineSubtotal * ($discountValue / 100));
            } else {
                $lineDiscount = round($discountValue);
            }

            $lineTotal = $lineSubtotal - $lineDiscount;

            \App\Modules\Sales\Models\SalesOrderItem::create([
                'sales_order_id'     => $so->id,
                'product_id'         => $item->product_id,
                'unit_id'            => $item->unit_id,
                'unit_name'          => $item->unit_name,
                'conversion_to_base' => $item->conversion_to_base ?? 1,
                'description'        => $item->description,
                'qty'                => $qty,
                'unit_price'         => $unitPrice,
                'discount_type'      => $item->discount_type ?? 'nominal',
                'discount_value'     => $discountValue,
                'discount_per_unit'  => $qty > 0 ? $lineDiscount / $qty : 0,
                'net_unit_price'     => $unitPrice - ($qty > 0 ? $lineDiscount / $qty : 0),
                'line_subtotal'      => $lineSubtotal,
                'line_discount'      => $lineDiscount,
                'line_total'         => $lineTotal,
            ]);

            $subtotal          += $lineSubtotal;
            $totalItemDiscount += $lineDiscount;
        }

        // Hitung grand total dari data quotation
        $globalDiscountType   = $quotation->global_discount_type ?? 'nominal';
        $globalDiscountValue  = (float) ($quotation->global_discount_value ?? 0);
        $dpp = $subtotal - $totalItemDiscount;
        $globalDiscountAmount = $globalDiscountType === 'percent'
            ? round($dpp * ($globalDiscountValue / 100))
            : round($globalDiscountValue);
        $dpp -= $globalDiscountAmount;

        $ppnPercent = (float) ($quotation->ppn_percent ?? 0);
        $ppnAmount  = round($dpp * ($ppnPercent / 100));
        $shipping   = (float) ($quotation->shipping_charge ?? 0);
        $expense    = (float) (($quotation->service_charge ?? 0) + ($quotation->other_expense ?? 0));
        $grandTotal = round($dpp + $ppnAmount + $shipping + $expense);

        $so->update([
            'subtotal'              => $subtotal,
            'discount_total'        => $totalItemDiscount,
            'global_discount_type'  => $globalDiscountType,
            'global_discount_value' => $globalDiscountValue,
            'global_discount_amount'=> $globalDiscountAmount,
            'ppn_percent'           => $ppnPercent,
            'ppn_amount'            => $ppnAmount,
            'shipping_cost'         => $shipping,
            'selling_expense'       => $expense,
            'grand_total'           => $grandTotal,
        ]);

        return redirect()->route('sales.orders.show', $so->id);
    }

    public function cancel($id)
    {
        $quotation = \App\Models\SalesQuotation::findOrFail($id);

        $quotation->update([
            'status' => 'cancelled'
        ]);

        return back()->with('success', 'Quotation dibatalkan');
    }

    public function apiShow($id)
    {
        return SalesQuotation::with('items.product')->findOrFail($id);
    }

    public function showApi($id)
    {
        $q = SalesQuotation::with('customer')->findOrFail($id);

        return response()->json([
            'id' => $q->id,
            'number' => $q->quotation_number,
            'customer_name' => $q->customer->name
        ]);
    }

    public function updateNotes(Request $request, $id)
    {
        $request->validate(['notes' => 'nullable|string|max:2000']);

        $quotation = SalesQuotation::findOrFail($id);
        $quotation->notes = $request->notes;
        $quotation->save();

        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $quotation = SalesQuotation::findOrFail($id);
        
        if ($quotation->status !== 'draft') {
            return back()->with('error', 'Hanya Quotation DRAFT yang boleh dihapus');
        }

        DB::beginTransaction();
        try {
            SalesQuotationItem::where('quotation_id', $quotation->id)->delete();

            foreach ($quotation->attachments as $att) {
                if ($att->image_path) {
                    Storage::disk('public')->delete($att->image_path);
                }
            }
            SalesQuotationAttachment::where('quotation_id', $quotation->id)->delete();

            $quotation->delete();
            DB::commit();

            return back()->with('success', 'Quotation berhasil dihapus');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }

    public function print($id)
    {
        $quotation = SalesQuotation::with(['customer', 'warehouse', 'items.product', 'attachments'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance();

        return view('erp.sales.quotations.print', compact('quotation', 'profile'));
    }

    public function downloadPdf($id)
    {
        $quotation = SalesQuotation::with(['customer', 'warehouse', 'items.product', 'attachments'])->findOrFail($id);
        $profile = \App\Models\BusinessProfile::instance();

        $html = view('erp.sales.quotations.print', [
            'quotation' => $quotation,
            'profile' => $profile,
            'pdfMode' => true,
        ])->render();

        // Hindari deadlock saat dijalankan di belakang `php artisan serve`
        // (single worker): Chromium tidak boleh balik nge-hit URL /storage/* di
        // server yang sama. Inline semua aset lokal jadi data URI.
        $html = $this->inlineLocalAssets($html);

        $bs = \Spatie\Browsershot\Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(0, 0, 0, 0)
            ->emulateMedia('print')
            ->timeout(120)
            ->waitUntilNetworkIdle();

        // Windows: pakai path node hasil install dari nodejs.org. Override via .env kalau perlu.
        $nodePath = env('BROWSERSHOT_NODE_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\node.exe' : null);
        $npmPath  = env('BROWSERSHOT_NPM_PATH',  PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\nodejs\\npm.cmd'  : null);
        if ($nodePath) $bs->setNodeBinary($nodePath);
        if ($npmPath)  $bs->setNpmBinary($npmPath);

        $pdf = $bs->pdf();

        $filename = 'Penawaran_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $quotation->quotation_number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function inlineLocalAssets(string $html): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $publicRoot = public_path();

        return preg_replace_callback(
            '/(src|href)\s*=\s*"([^"]+)"/i',
            function ($m) use ($appUrl, $publicRoot) {
                $attr = $m[1];
                $url  = $m[2];

                // Hanya tangani URL ke server kita sendiri.
                if ($appUrl === '' || !str_starts_with($url, $appUrl . '/')) {
                    return $m[0];
                }

                $path = parse_url($url, PHP_URL_PATH);
                if (!$path) return $m[0];

                $absolute = $publicRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);
                if (!is_file($absolute)) return $m[0];

                $mime = @mime_content_type($absolute) ?: 'application/octet-stream';
                $data = base64_encode((string) file_get_contents($absolute));
                return $attr . '="data:' . $mime . ';base64,' . $data . '"';
            },
            $html
        ) ?? $html;
    }

    /**
     * Sync attachments: update/delete existing entries and ingest new uploads.
     */
    private function syncAttachments(SalesQuotation $quotation, Request $request): void
    {
        // Batas karakter deskripsi disesuaikan dgn area teks 76mm × 115mm di print A4.
        // ~18 baris × 52 char/baris ≈ 900 char teoritis; 500 aman dengan margin.
        $descMaxLength = 500;

        $existing = $request->input('existing_attachments', []);
        $replacementFiles = $request->file('existing_attachment_replacement_files', []);
        if (is_array($existing)) {
            foreach ($existing as $id => $data) {
                $att = SalesQuotationAttachment::where('quotation_id', $quotation->id)
                    ->where('id', $id)
                    ->first();
                if (!$att) continue;

                if (!empty($data['_delete'])) {
                    if ($att->image_path) {
                        Storage::disk('public')->delete($att->image_path);
                    }
                    $att->delete();
                    continue;
                }

                $updates = [
                    'title' => mb_substr(trim((string) ($data['title'] ?? $att->title)), 0, 200),
                    'description' => isset($data['description'])
                        ? mb_substr((string) $data['description'], 0, $descMaxLength)
                        : null,
                    'sort_order' => (int) ($data['sort_order'] ?? $att->sort_order),
                ];

                // Ganti gambar bila user upload file pengganti via modal edit.
                $replacement = $replacementFiles[$id] ?? null;
                if ($replacement && $replacement->isValid()) {
                    if ($att->image_path) {
                        Storage::disk('public')->delete($att->image_path);
                    }
                    $ext = strtolower($replacement->getClientOriginalExtension() ?: 'jpg');
                    $filename = 'att-' . Str::random(10) . '.' . $ext;
                    $updates['image_path'] = $replacement->storeAs(
                        "quotation-attachments/{$quotation->id}",
                        $filename,
                        'public'
                    );
                }

                $att->update($updates);
            }
        }

        $files = $request->file('attachment_files', []);
        $titles = $request->input('attachment_titles', []);
        $descs = $request->input('attachment_descriptions', []);

        if (!is_array($files)) return;

        $maxSort = (int) (SalesQuotationAttachment::where('quotation_id', $quotation->id)->max('sort_order') ?? 0);

        foreach ($files as $idx => $file) {
            if (!$file || !$file->isValid()) continue;

            $title = mb_substr(trim((string) ($titles[$idx] ?? '')), 0, 200);
            if ($title === '') continue;

            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = 'att-' . Str::random(10) . '.' . $ext;
            $path = $file->storeAs(
                "quotation-attachments/{$quotation->id}",
                $filename,
                'public'
            );

            SalesQuotationAttachment::create([
                'quotation_id' => $quotation->id,
                'image_path' => $path,
                'title' => $title,
                'description' => isset($descs[$idx])
                    ? mb_substr((string) $descs[$idx], 0, $descMaxLength)
                    : null,
                'sort_order' => ++$maxSort,
            ]);
        }
    }
}
