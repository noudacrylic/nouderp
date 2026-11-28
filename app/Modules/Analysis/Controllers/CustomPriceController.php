<?php

namespace App\Modules\Analysis\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Models\CustomPriceCategory;
use App\Modules\Analysis\Models\CustomPriceMaterial;
use App\Modules\Analysis\Models\CustomPriceSample;
use App\Modules\Analysis\Services\CustomPriceService;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Harga Custom — tiga sub-tab: Hitung (dipakai sehari-hari), Faktor Waktu (kalibrasi dari OP
 * sampel), Pengaturan (angka yang jarang diubah). Semua rumus di CustomPriceService.
 */
class CustomPriceController extends Controller
{
    public function __construct(protected CustomPriceService $service)
    {
    }

    // ── Hitung ───────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $category = $this->category($request);
        $raw      = $request->except(['kategori', 'qty', 'packing_khusus', 'packing', 'setup', 'markup']);

        // Setting & markup boleh diganti untuk SATU hitungan ini saja (tidak disimpan) —
        // angka bawaannya tetap dari Pengaturan kategori.
        $default = ['setup' => $category->setup_cost, 'markup' => $category->markup_percent];
        if (trim((string) $request->input('setup')) !== '') {
            $category->setup_cost = max(0.0, (float) clean_number($request->input('setup')));
        }
        if (trim((string) $request->input('markup')) !== '') {
            $category->markup_percent = max(0.0, (float) clean_number($request->input('markup')));
        }

        // Form dikirim lewat GET supaya hasilnya bisa dibuka ulang / dibagikan apa adanya.
        $result = null;
        if ($request->filled('p')) {
            $result = $this->service->calculate(
                $category,
                $raw,
                (int) max(1, (float) clean_number($request->input('qty', 1))),
                (float) clean_number($request->input('packing_khusus', 0)),
                (string) $request->input('packing', 'instant'),
            );
        }

        return view('erp.analisa.harga-custom.index', $this->formData($category) + [
            'raw'    => $raw + [
                'qty'            => $request->input('qty', 1),
                'packing_khusus' => $request->input('packing_khusus'),
                'packing'        => $request->input('packing', 'instant'),
                'setup'          => $request->input('setup'),
                'markup'         => $request->input('markup'),
            ],
            'result'  => $result,
            'default' => $default,
        ]);
    }

    // ── Faktor Waktu ─────────────────────────────────────────────────────────────

    public function factors(Request $request)
    {
        $category = $this->category($request);

        return view('erp.analisa.harga-custom.faktor', $this->formData($category) + [
            'factors'    => $this->service->factors($category),
            'candidates' => $this->candidateOrders($category),
            'raw'        => old() ?: [],
        ]);
    }

    public function storeSample(Request $request)
    {
        $category = $this->category($request);
        $request->validate([
            'order_number' => 'required|string',
            'units'        => 'nullable|string',
            'notes'        => 'nullable|string|max:255',
        ]);

        $order = ProductionOrder::where('order_number', trim($request->input('order_number')))->first();
        if (!$order) {
            return back()->withInput()->with('error', 'Nomor OP tidak ditemukan.');
        }

        $raw = $request->except(['_token', 'kategori', 'order_number', 'units', 'notes']);
        $geo = $this->service->geometry($category, $raw);
        if (!$geo['ok']) {
            return back()->withInput()->with('error', implode(' ', $geo['errors']));
        }

        $units = (float) clean_number($request->input('units'));
        if ($units <= 0) {
            $units = $this->service->orderUnits($order);
        }
        if ($units <= 0) {
            return back()->withInput()->with('error', 'Jumlah unit OP tidak diketahui — isi manual.');
        }

        CustomPriceSample::updateOrCreate(
            ['category_id' => $category->id, 'production_order_id' => $order->id],
            ['units' => $units, 'input' => $raw, 'notes' => $request->input('notes')],
        );

        return redirect()->route('analisa.harga-custom.faktor', ['kategori' => $category->code])
            ->with('success', "Sampel {$order->order_number} disimpan.");
    }

    /** Coret / pulihkan sampel. Mencoret wajib beralasan, sama seperti pengecualian di Kuota. */
    public function toggleSample(Request $request, int $id)
    {
        $sample = CustomPriceSample::findOrFail($id);

        if ($sample->excluded) {
            $sample->update(['excluded' => false, 'exclude_reason' => null]);

            return back()->with('success', 'Sampel dipakai lagi.');
        }

        $data = $request->validate(['reason' => 'required|string|max:255']);
        $sample->update(['excluded' => true, 'exclude_reason' => $data['reason']]);

        return back()->with('success', 'Sampel dicoret dari perhitungan faktor.');
    }

    public function destroySample(int $id)
    {
        CustomPriceSample::findOrFail($id)->delete();

        return back()->with('success', 'Sampel dihapus.');
    }

    /** Kunci faktor manual. Kosong = ikut data sampel. */
    public function saveFactors(Request $request)
    {
        $category = $this->category($request);
        $request->validate([
            'cnc_factor_manual'      => 'nullable|string',
            'assembly_factor_manual' => 'nullable|string',
        ]);

        $val = function (string $k) use ($request) {
            $v = trim((string) $request->input($k));

            return $v === '' ? null : max(0.01, (float) clean_number($v));
        };

        $category->update([
            'cnc_factor_manual'      => $val('cnc_factor_manual'),
            'assembly_factor_manual' => $val('assembly_factor_manual'),
        ]);

        return back()->with('success', 'Faktor waktu disimpan.');
    }

    // ── Pengaturan ───────────────────────────────────────────────────────────────

    public function settings(Request $request)
    {
        $category = $this->category($request);

        return view('erp.analisa.harga-custom.pengaturan', $this->formData($category) + [
            'paramDefs'   => $this->service->paramDefinitions($category),
            'params'      => $this->service->params($category),
            'departments' => Department::produksi()->where('is_active', 1)->orderBy('name')->get(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $category = $this->category($request);
        $request->validate([
            'setup_cost'             => 'required|string',
            'markup_percent'         => 'required|string',
            'waste_percent'          => 'required|string',
            'cnc_department_id'      => 'nullable|integer|exists:production_departments,id',
            'assembly_department_id' => 'nullable|integer|exists:production_departments,id',
            'params'                 => 'nullable|array',
        ]);

        $params = [];
        foreach (array_keys($this->service->paramDefinitions($category)) as $key) {
            $v = trim((string) $request->input("params.$key"));
            if ($v !== '') {
                $params[$key] = (float) clean_number($v);
            }
        }

        $category->update([
            'setup_cost'             => (float) clean_number($request->input('setup_cost')),
            'markup_percent'         => (float) clean_number($request->input('markup_percent')),
            'waste_percent'          => (float) clean_number($request->input('waste_percent')),
            'min_order_enabled'      => $request->boolean('min_order_enabled'),
            'cnc_department_id'      => $request->input('cnc_department_id') ?: null,
            'assembly_department_id' => $request->input('assembly_department_id') ?: null,
            'params'                 => $params,
        ]);

        return back()->with('success', "Pengaturan {$category->name} disimpan.");
    }

    /**
     * Simpan setting & markup yang sedang dicoba di layar Hitung jadi bawaan kategori. Kembali
     * ke hitungan yang sama, tanpa isian setting/markup (sekarang sudah sama dengan bawaan).
     */
    public function saveDefaults(Request $request)
    {
        $category = $this->category($request);
        $request->validate([
            'setup_cost'     => 'required|numeric|min:0',
            'markup_percent' => 'required|numeric|min:0',
        ]);

        $category->update([
            'setup_cost'     => (float) $request->input('setup_cost'),
            'markup_percent' => (float) $request->input('markup_percent'),
        ]);

        $back = url()->previous();
        $back = preg_replace('/([?&])(setup|markup)=[^&]*/', '$1', $back);

        return redirect($back)->with('success', "Bawaan {$category->name} disimpan: setting Rp"
            . number_format($category->setup_cost, 0, ',', '.') . ', markup '
            . rtrim(rtrim(number_format($category->markup_percent, 2, ',', '.'), '0'), ',') . '%.');
    }

    public function saveMaterials(Request $request)
    {
        $request->validate(['bahan' => 'required|array']);

        foreach ($request->input('bahan', []) as $productId => $row) {
            $tebal = trim((string) ($row['tebal_mm'] ?? ''));
            $speed = trim((string) ($row['cut_speed'] ?? ''));

            // Dua-duanya kosong = kembali ke bawaan (tebal dari nama, kecepatan dari tebal).
            if ($tebal === '' && $speed === '') {
                CustomPriceMaterial::where('product_id', $productId)->delete();
                continue;
            }

            CustomPriceMaterial::updateOrCreate(
                ['product_id' => (int) $productId],
                [
                    'tebal_mm'  => $tebal === '' ? null : (float) clean_number($tebal),
                    'cut_speed' => $speed === '' ? null : (float) clean_number($speed),
                ],
            );
        }

        return back()->with('success', 'Tebal & kecepatan potong bahan disimpan.');
    }

    // ── Bantuan ──────────────────────────────────────────────────────────────────

    private function category(Request $request): CustomPriceCategory
    {
        $categories = $this->service->categories();
        abort_if($categories->isEmpty(), 404, 'Belum ada kategori Harga Custom.');

        return $categories->firstWhere('code', $request->input('kategori')) ?? $categories->first();
    }

    /** Data yang dipakai bersama oleh form resep di ketiga halaman. */
    private function formData(CustomPriceCategory $category): array
    {
        return [
            'category'   => $category,
            'categories' => $this->service->categories(),
            'recipe'     => $this->service->recipe($category),
            'materials'  => $this->service->materials(),
            'hinges'     => DB::table('products')->where('name', 'like', '%engsel%')->orderBy('name')->get(['id', 'sku', 'name']),
        ];
    }

    /** OP selesai yang punya timer — pilihan nomor OP di form sampel. */
    private function candidateOrders(CustomPriceCategory $category)
    {
        $taken = CustomPriceSample::where('category_id', $category->id)->pluck('production_order_id');

        return ProductionOrder::whereIn('status', ProductionTimeAnalysisService::ELIGIBLE_STATUSES)
            ->whereHas('steps.timeLogs')
            ->whereNotIn('id', $taken)
            ->with(['outputs' => fn ($q) => $q->where('output_type', 'main'), 'outputs.product:id,name'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'order_number', 'production_date', 'type']);
    }
}
