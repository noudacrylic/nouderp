<?php

namespace App\Modules\Analysis\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Models\ProductionTimeAssumption;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Analysis\Services\ProductionTimeInsightService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductionTimeController extends Controller
{
    public function __construct(
        protected ProductionTimeAnalysisService $service,
        protected ProductionTimeInsightService $insight,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        // Pengukuran dulu, baru pengandaian ditempelkan — supaya "yang terukur" dan "yang
        // diandaikan" tidak pernah tercampur di dalam satu angka tanpa ketahuan.
        $rows    = collect($this->insight->enrich($this->service->perProduct($filters), $filters));

        if ($search = trim((string) $request->input('search', ''))) {
            $rows = $rows->filter(function ($row) use ($search) {
                return stripos((string) $row['product']['name'], $search) !== false
                    || stripos((string) $row['product']['sku'], $search) !== false;
            });
        }

        // Agregasi dilakukan di PHP (elapsed_working_seconds adalah accessor, bukan
        // kolom SQL) sehingga paginasinya dibuat manual dari koleksi hasil.
        $perPage = per_page_size();
        $page    = LengthAwarePaginator::resolveCurrentPage();
        $paged   = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('erp.analisa.waktu-produksi.index', [
            'rows'        => $paged,
            'departments' => $this->columnDepartments($paged->items(), $filters),
            'deptOptions' => $this->service->departmentsForFilter(),
            'mergedCount' => empty($filters['include_merged']) ? $this->service->mergedSampleCount($filters) : 0,
            'filters'     => $filters,
            'typeOptions' => $this->typeOptions(),
            'capacity'    => $this->insight->capacityPerDay($filters),
        ]);
    }

    public function show(Request $request, int $productId)
    {
        $filters = $this->filters($request);
        $data    = $this->service->forProduct($productId, $filters);

        if (!$data) {
            return redirect(list_url('analisa.waktu-produksi.index'))
                ->with('error', 'Produk ini belum punya sampel waktu produksi untuk filter yang dipilih.');
        }

        $data = $this->insight->enrichOne($data, $filters);

        return view('erp.analisa.waktu-produksi.show', [
            'data'        => $data,
            'departments' => $this->columnDepartments([$data], $filters),
            'filters'     => $filters,
            'perExecutor' => $this->insight->perExecutor($productId, $filters),
            'capacity'    => $this->insight->capacityPerDay($filters),
        ]);
    }

    public function saveExclusions(Request $request, int $productId)
    {
        $data = $request->validate([
            'rendered'   => 'nullable|array',
            'rendered.*' => 'integer',
            'use'        => 'nullable|array',
            'use.*'      => 'integer',
            'reason'     => 'nullable|string|max:255',
        ]);

        if (empty($data['rendered'])) {
            return back()->with('error', 'Tidak ada sampel yang bisa dipilih.');
        }

        $res = $this->service->saveExclusions(
            $data['rendered'],
            $data['use'] ?? [],
            $data['reason'] ?? null,
            auth()->id(),
        );

        return back()->with('success', sprintf(
            'Pilihan sampel disimpan — %d dikecualikan, %d dipakai kembali.',
            $res['excluded'],
            $res['restored'],
        ));
    }

    /**
     * Asumsi waktu per unit — PEMBILANG model HPP.
     *
     * Diisi dalam MENIT karena begitulah orang membicarakannya ("assembling 3 jam"), disimpan
     * dalam detik karena begitulah semua perhitungan lain memakainya.
     */
    public function saveAssumptions(Request $request)
    {
        $data = $request->validate([
            'a'             => 'required|array',
            'a.*.*.minutes' => 'nullable|numeric|min:0|max:100000',
            'a.*.*.use'     => 'nullable|boolean',
            'a.*.*.notes'   => 'nullable|string|max:255',
        ]);

        $tersimpan = 0;
        foreach ($data['a'] as $productId => $perDept) {
            foreach ($perDept as $deptId => $row) {
                $menit = $row['minutes'] ?? null;
                $pakai = (bool) ($row['use'] ?? false);
                $kunci = ['product_id' => (int) $productId, 'department_id' => (int) $deptId];

                // Baris kosong yang tidak dicentang tidak perlu disimpan — jangan menaruh ribuan
                // baris nol hanya karena halamannya pernah dibuka.
                if (($menit === null || $menit === '') && !$pakai) {
                    ProductionTimeAssumption::where($kunci)->delete();
                    continue;
                }

                ProductionTimeAssumption::updateOrCreate($kunci, [
                    'assumed_seconds_per_unit' => ($menit === null || $menit === '') ? null : (float) $menit * 60,
                    'use_assumption'           => $pakai,
                    'notes'                    => $row['notes'] ?? null,
                ]);
                $tersimpan++;
            }
        }

        return back()->with('success', "Asumsi waktu disimpan ({$tersimpan} baris).");
    }

    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $rows    = $this->service->perProduct($filters);
        $depts   = $this->columnDepartments($rows->all(), $filters);

        $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Rata-rata per Produk');

        $header = ['SKU', 'Produk', 'Jml Sampel', 'Hasil/Siklus'];
        foreach ($depts as $dept) {
            $header[] = $dept['name'] . ' (dtk/siklus)';
            $header[] = $dept['name'] . ' (dtk/unit)';
        }
        $header[] = 'TOTAL dtk/siklus';
        $header[] = 'TOTAL dtk/unit';
        $sheet->fromArray($header, null, 'A1');

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($header));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(42);

        $r = 2;
        foreach ($rows as $row) {
            $line = [
                $row['product']['sku'],
                $row['product']['name'],
                $row['included_count'],
                $row['qty_per_cycle'],
            ];
            foreach ($depts as $dept) {
                $cell   = $row['per_division'][$dept['id']] ?? null;
                $line[] = $cell['sec_per_cycle'] ?? null;
                $line[] = $cell['sec_per_unit'] ?? null;
            }
            $line[] = $row['total']['sec_per_cycle'];
            $line[] = $row['total']['sec_per_unit'];
            $sheet->fromArray($line, null, 'A' . $r++);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'analisa-waktu-produksi.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ==========================================================

    /** Satu-satunya tempat membaca request — service sengaja bebas dari request(). */
    protected function filters(Request $request): array
    {
        $types = (array) $request->input('types', ProductionTimeAnalysisService::DEFAULT_TYPES);
        $types = array_values(array_intersect($types, array_keys($this->typeOptions())));

        return [
            'date_from'      => $request->input('date_from') ?: null,
            'date_to'        => $request->input('date_to') ?: null,
            'types'          => $types ?: ProductionTimeAnalysisService::DEFAULT_TYPES,
            'department_id'  => $request->input('department_id') ?: null,
            'include_merged' => $request->boolean('include_merged'),
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

    /**
     * Kolom divisi yang perlu dirender = gabungan divisi yang muncul di baris hasil,
     * diurutkan mengikuti urutan master divisi supaya konsisten antar produk.
     */
    protected function columnDepartments(array $rows, array $filters): array
    {
        $seen = [];
        foreach ($rows as $row) {
            foreach ($row['per_division'] as $deptId => $cell) {
                $seen[$deptId] = $cell['department'];
            }
            foreach (array_merge($row['samples'] ?? [], $row['ineligible_samples'] ?? []) as $sample) {
                foreach (array_keys($sample['sec']) as $deptId) {
                    $seen[$deptId] ??= ['id' => $deptId, 'code' => null, 'name' => 'Divisi #' . $deptId, 'type' => null];
                }
            }
        }

        $order = $this->service->departmentsForFilter()->pluck('id')->all();
        uasort($seen, function ($a, $b) use ($order) {
            $ia = array_search($a['id'], $order, true);
            $ib = array_search($b['id'], $order, true);
            return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
        });

        return array_values($seen);
    }
}
