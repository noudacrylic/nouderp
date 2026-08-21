<?php

namespace App\Modules\Analysis\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Models\MaterialPriceAssumption;
use App\Modules\Analysis\Models\ProductPackingCost;
use App\Modules\Analysis\Services\BundleHppService;
use App\Modules\Analysis\Services\MaterialRecipeService;
use App\Modules\Analysis\Services\ProductHppService;
use App\Modules\Analysis\Services\ProductionCostRateService;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductHppController extends Controller
{
    public function __construct(protected ProductHppService $hpp) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return view('erp.analisa.hpp.index', [
            'rows'         => $this->arrange($request, $this->hpp->all($filters)),
            'basis'        => $this->hpp->reconciliation($filters),
            'packingNotes' => ProductPackingCost::pluck('notes', 'product_id'),
            'filters'      => $filters,
            'sort'         => $request->input('sort', 'margin'),
            'typeOptions'  => $this->typeOptions(),
        ]);
    }

    /**
     * HPP bundle — dirakit dari HPP komponennya di halaman Ready.
     *
     * Dipisah dari Ready, bukan digabung dalam satu tabel, karena kolomnya menjawab
     * pertanyaan yang berbeda: bundle tidak punya waktu produksi maupun variable cost
     * sendiri, yang ada isi paketnya. Produk preorder tetap di Ready — yang membedakan
     * bundle hanyalah HPP-nya dirakit, bukan diukur.
     */
    public function bundleIndex(Request $request, BundleHppService $bundles)
    {
        $filters = $this->filters($request);

        return view('erp.analisa.hpp.bundle', [
            'rows'         => $this->arrange($request, $bundles->all($filters)),
            'basis'        => $this->hpp->reconciliation($filters),
            'packingNotes' => ProductPackingCost::pluck('notes', 'product_id'),
            'filters'      => $filters,
            'sort'         => $request->input('sort', 'margin'),
        ]);
    }

    public function bundleShow(Request $request, int $productId, BundleHppService $bundles)
    {
        $filters = $this->filters($request);
        $data    = $bundles->forProduct($productId, $filters);

        if (!$data) {
            return redirect(list_url('analisa.hpp.bundle.index'))
                ->with('error', 'Produk ini bukan bundle, atau sudah tidak ada.');
        }

        return view('erp.analisa.hpp.bundle-show', [
            'data'    => $data,
            'basis'   => $this->hpp->reconciliation($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * Cari + urutkan + paginasi. Dipakai bersama Ready dan Bundle supaya keduanya
     * berperilaku sama persis — keduanya baris HPP dengan bentuk yang sama.
     */
    protected function arrange(Request $request, Collection $rows): LengthAwarePaginator
    {
        if ($search = trim((string) $request->input('search', ''))) {
            $rows = $rows->filter(fn ($r) => stripos((string) $r['product']['name'], $search) !== false
                || stripos((string) $r['product']['sku'], $search) !== false);
        }

        $rows = match ($request->input('sort', 'margin')) {
            'hpp'     => $rows->sortByDesc('hpp_per_unit'),
            'nama'    => $rows->sortBy(fn ($r) => $r['product']['name']),
            default   => $rows->sortBy(fn ($r) => $r['margin_percent'] ?? PHP_INT_MAX),
        };

        $perPage = per_page_size();
        $page    = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * Simpan biaya packing per unit sebuah produk (inline edit di baris tabel).
     *
     * Ini biaya EKSTRA di atas overhead packing yang biasa (peti kayu, kardus khusus) —
     * ditambahkan, bukan menimpa. Dikosongkan = barisnya dihapus, jadi produk itu cuma
     * menanggung overhead packing rata-rata.
     */
    public function savePackingCost(Request $request, int $productId)
    {
        $request->validate([
            'amount_per_unit' => 'nullable|string',
            'notes'           => 'nullable|string|max:255',
        ]);

        abort_unless(DB::table('products')->where('id', $productId)->exists(), 404);

        $raw   = trim((string) $request->input('amount_per_unit', ''));
        $notes = $request->input('notes') ?: null;

        if ($raw === '') {
            ProductPackingCost::where('product_id', $productId)->delete();

            return back()->with('success', 'Packing khusus dihapus — produk ini kembali hanya menanggung overhead packing.');
        }

        ProductPackingCost::updateOrCreate(
            ['product_id' => $productId],
            [
                'amount_per_unit' => (float) clean_number($raw),
                'notes'           => $notes,
                'updated_by'      => $request->user()?->id,
            ],
        );

        return back()->with('success', 'Biaya packing produk disimpan.');
    }

    public function show(Request $request, int $productId)
    {
        $filters = $this->filters($request);
        $data    = $this->hpp->forProduct($productId, $filters);

        if (!$data) {
            return redirect(list_url('analisa.hpp.index'))
                ->with('error', 'Produk ini belum punya sampel produksi untuk filter yang dipilih.');
        }

        return view('erp.analisa.hpp.show', [
            'data'    => $data,
            'basis'   => $this->hpp->reconciliation($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * Sub-tab Asumsi Bahan — "kalau akrilik 2 mm jadi Rp360.000, HPP saya jadi berapa".
     *
     * Yang bisa diisi hanya bahan BELI. Bahan setengah jadi tidak punya baris di sini karena
     * harganya bukan sesuatu yang ditawar ke pemasok — ia hasil dari harga bahan di bawahnya,
     * dan ikut naik sendiri lewat resepnya.
     */
    public function assumptions(Request $request, MaterialRecipeService $recipes)
    {
        $filters = $this->filters($request);

        return view('erp.analisa.hpp.asumsi', [
            'rows'    => $recipes->assumptionRows($filters),
            'basis'   => $this->hpp->reconciliation($filters),
            'filters' => $filters,
            'aktif'   => MaterialPriceAssumption::count(),
        ]);
    }

    public function saveAssumption(Request $request, MaterialRecipeService $recipes)
    {
        $request->validate(['product_id' => 'required|integer|exists:products,id', 'price' => 'nullable|string']);

        $productId = (int) $request->input('product_id');
        $raw       = trim((string) $request->input('price'));

        if ($raw === '') {
            MaterialPriceAssumption::where('product_id', $productId)->delete();

            return back()->with('success', 'Asumsi dihapus — bahan ini kembali memakai harga beli terakhirnya.');
        }

        MaterialPriceAssumption::updateOrCreate(
            ['product_id' => $productId],
            ['price' => (float) clean_number($raw), 'updated_by' => $request->user()?->id],
        );

        return back()->with('success', 'Asumsi harga bahan disimpan.');
    }

    /** Naikkan semua bahan sekaligus — skenario "semua bahan naik sekian persen". */
    public function bulkAssumption(Request $request, MaterialRecipeService $recipes)
    {
        $request->validate(['percent' => 'required|numeric|min:-90|max:500']);

        $percent = (float) $request->input('percent');
        $rows    = $recipes->assumptionRows($this->filters($request))->filter(fn ($r) => $r['price'] > 0);

        foreach ($rows as $row) {
            MaterialPriceAssumption::updateOrCreate(
                ['product_id' => $row['product']['id']],
                [
                    'price'      => round($row['price'] * (1 + $percent / 100)),
                    'updated_by' => $request->user()?->id,
                ],
            );
        }

        return back()->with('success', $rows->count() . ' bahan diisi asumsi ' . ($percent >= 0 ? '+' : '') . rtrim(rtrim(number_format($percent, 2, ',', '.'), '0'), ',') . '% dari harga beli terakhirnya.');
    }

    public function clearAssumptions()
    {
        MaterialPriceAssumption::query()->delete();

        return back()->with('success', 'Semua asumsi dikosongkan — halaman kembali memakai harga sebenarnya.');
    }

    protected function filters(Request $request): array
    {
        $types = (array) $request->input('types', ProductionTimeAnalysisService::DEFAULT_TYPES);
        $types = array_values(array_intersect($types, array_keys($this->typeOptions())));

        return [
            'date_from'      => $request->input('date_from') ?: null,
            'date_to'        => $request->input('date_to') ?: null,
            'types'          => $types ?: ProductionTimeAnalysisService::DEFAULT_TYPES,
            'include_merged' => $request->boolean('include_merged'),
            'months'         => (int) $request->input('months', ProductionCostRateService::DEFAULT_PERIOD_MONTHS),
            // Mode asumsi sengaja lewat URL (?asumsi=1), bukan pengaturan tersimpan: harga
            // jual tidak boleh ditetapkan dari angka andaian tanpa sadar.
            'assumption'     => $request->boolean('asumsi'),
        ];
    }

    /** @return array<string,string> */
    protected function typeOptions(): array
    {
        return [
            'ready_stock' => 'Ready Stock',
            'custom'      => 'Preorder',
            'perbaikan'   => 'Perbaikan',
            'garansi'     => 'Garansi',
            'repair'      => 'Perbaikan (lama)',
        ];
    }
}
