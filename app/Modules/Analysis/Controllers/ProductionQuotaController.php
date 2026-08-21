<?php

namespace App\Modules\Analysis\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Models\ProductionQuotaExcludedDate;
use App\Modules\Analysis\Models\ProductionQuotaSlot;
use App\Modules\Analysis\Services\ProductionCostRateService;
use App\Modules\Analysis\Services\ProductionQuotaService;
use App\Modules\Production\Models\Department;
use Illuminate\Http\Request;

/**
 * Kuota Produksi — berapa slot-jam yang pabrik punya sebulan, berapa yang terpakai, dan berapa
 * tarif fixed cost per slot-jam yang nanti dipakai HPP.
 *
 * Perhitungannya di ProductionQuotaService; di sini hanya penyaringan dan penyimpanan asumsi.
 */
class ProductionQuotaController extends Controller
{
    public function __construct(protected ProductionQuotaService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = [
            'months'      => (int) $request->input('months', ProductionCostRateService::DEFAULT_PERIOD_MONTHS),
            'window_days' => (int) $request->input('window_days', ProductionQuotaService::WINDOW_DAYS),
            'to'          => $request->input('to') ?: null,
        ];

        return view('erp.analisa.kuota.index', [
            'data'        => $this->service->build($filters),
            'filters'     => $filters,
            'departments' => Department::produksi()->where('is_active', 1)->orderBy('name')->get(),
        ]);
    }

    /** Asumsi jam per slot: jam/hari & hari/bulan, plus saklarnya. */
    public function saveSlots(Request $request)
    {
        $data = $request->validate([
            'slot'                          => 'required|array',
            'slot.*.assumed_hours_per_day'  => 'nullable|numeric|min:0|max:24',
            'slot.*.assumed_working_days'   => 'nullable|numeric|min:0|max:31',
            'slot.*.use_assumption'         => 'nullable|boolean',
        ]);

        foreach ($data['slot'] as $executorId => $row) {
            $jam   = $row['assumed_hours_per_day'] ?? null;
            $hari  = $row['assumed_working_days'] ?? null;
            $pakai = (bool) ($row['use_assumption'] ?? false);

            // Baris kosong yang tidak dicentang tidak perlu disimpan — jangan menaruh baris nol
            // di database hanya karena halamannya pernah dibuka.
            if ($jam === null && $hari === null && !$pakai) {
                ProductionQuotaSlot::where('executor_id', $executorId)->delete();
                continue;
            }

            $executor = \App\Modules\Production\Models\DepartmentExecutor::find($executorId);
            if (!$executor) {
                continue;
            }

            ProductionQuotaSlot::updateOrCreate(
                ['executor_id' => (int) $executorId],
                [
                    'department_id'         => $executor->department_id,
                    'assumed_hours_per_day' => $jam,
                    'assumed_working_days'  => $hari,
                    'use_assumption'        => $pakai,
                ]
            );
        }

        return back()->with('success', 'Asumsi jam disimpan.');
    }

    /** Slot pengandaian — mesin atau orang yang belum ada. */
    public function storeVirtualSlot(Request $request)
    {
        $data = $request->validate([
            'department_id'         => 'required|exists:production_departments,id',
            'label'                 => 'required|string|max:100',
            'assumed_hours_per_day' => 'required|numeric|min:0.25|max:24',
            'assumed_working_days'  => 'nullable|numeric|min:0|max:31',
        ]);

        ProductionQuotaSlot::create($data + ['executor_id' => null, 'use_assumption' => true]);

        return back()->with('success', 'Slot pengandaian ditambahkan.');
    }

    public function destroyVirtualSlot(int $id)
    {
        ProductionQuotaSlot::whereNull('executor_id')->where('id', $id)->delete();

        return back()->with('success', 'Slot pengandaian dihapus.');
    }

    /**
     * Hari yang datanya rusak dan tidak boleh ikut merata-rata.
     *
     * Sengaja menuntut alasan: mengecualikan hari tanpa keterangan adalah cara termudah membuat
     * angka terlihat bagus tanpa ada yang bisa memeriksanya lagi enam bulan kemudian.
     */
    public function storeExcludedDate(Request $request)
    {
        $data = $request->validate([
            'tanggal' => 'required|date|unique:production_quota_excluded_dates,tanggal',
            'reason'  => 'required|string|max:255',
        ]);

        ProductionQuotaExcludedDate::create($data);

        return back()->with('success', 'Tanggal dikecualikan dari rata-rata.');
    }

    public function destroyExcludedDate(int $id)
    {
        ProductionQuotaExcludedDate::findOrFail($id)->delete();

        return back()->with('success', 'Tanggal dikembalikan ke rata-rata.');
    }
}
