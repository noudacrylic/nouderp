<?php

namespace App\Modules\Analysis\Controllers;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Modules\Analysis\Models\ProductionCostComponent;
use App\Modules\Analysis\Services\ProductionCostRateService;
use App\Modules\Production\Models\Department;
use Illuminate\Http\Request;

/**
 * Susunan biaya tetap + tarif per divisi (penyusun HPP).
 *
 * Tidak ada lagi "pengaturan" yang perlu disimpan terpisah: letak sebuah biaya di
 * pohon sudah menentukan cara pembebanannya, dan apa pun yang tercantum di halaman
 * ini dibebankan. Tidak ingin dibebankan = hapus barisnya.
 */
class ProductionCostController extends Controller
{
    public function __construct(protected ProductionCostRateService $service) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return view('erp.analisa.biaya-divisi.index', [
            'result'          => $this->service->build($filters),
            // Dipakai merender form tambah/ubah/hapus di luar tabel (form tidak boleh bersarang).
            'componentIds'    => ProductionCostComponent::pluck('id'),
            'expenseAccounts' => Account::where('type', 'expense')->where('is_active', 1)
                                    ->orderBy('code')->get(['id', 'code', 'name']),
            'filters'         => $filters,
        ]);
    }

    public function storeComponent(Request $request)
    {
        $data = $this->validateComponent($request);

        ProductionCostComponent::create($data);

        return back()->with('success', 'Komponen biaya "' . $data['name'] . '" ditambahkan.');
    }

    public function updateComponent(Request $request, int $id)
    {
        $component = ProductionCostComponent::findOrFail($id);
        $component->update($this->validateComponent($request));

        return back()->with('success', 'Komponen biaya "' . $component->name . '" diperbarui.');
    }

    public function destroyComponent(int $id)
    {
        $component = ProductionCostComponent::findOrFail($id);
        $name      = $component->name;
        $component->delete();

        return back()->with('success', 'Komponen biaya "' . $name . '" dihapus.');
    }

    protected function validateComponent(Request $request): array
    {
        $data = $request->validate([
            'group_key'      => 'required|in:non_produksi,packing,overhead_produksi,divisi',
            'department_id'  => 'nullable|exists:production_departments,id',
            'name'           => 'required|string|max:255',
            'source'         => 'required|in:akun,manual',
            'account_id'     => 'required_if:source,akun|nullable|exists:accounts,id',
            'percentage'     => 'nullable|numeric|min:0.01|max:100',
            'amount_monthly' => 'nullable|string',
            'notes'          => 'nullable|string|max:255',
        ], [
            'name.required'       => 'Nama komponen wajib diisi.',
            'account_id.required_if' => 'Akun wajib dipilih untuk komponen bersumber akun.',
        ]);

        $isDivisi = $data['group_key'] === 'divisi';

        // Hanya divisi bertipe 'produksi' yang punya node sendiri di pohon biaya.
        // Divisi non-produksi biayanya ditanggung bersama lewat grup Non Produksi.
        if ($isDivisi) {
            $dept = Department::find($data['department_id'] ?? null);
            if (!$dept || $dept->type !== 'produksi') {
                abort(422, 'Komponen divisi hanya bisa dipasang pada divisi produksi.');
            }
        }

        return [
            'group_key'      => $data['group_key'],
            'department_id'  => $isDivisi ? $data['department_id'] : null,
            'name'           => $data['name'],
            'source'         => $data['source'],
            'account_id'     => $data['source'] === 'akun' ? $data['account_id'] : null,
            'percentage'     => $data['source'] === 'akun' ? ($data['percentage'] ?? 100) : 100,
            'amount_monthly' => $data['source'] === 'manual' ? clean_number($data['amount_monthly'] ?? 0) : 0,
            'notes'          => $data['notes'] ?? null,
            'is_active'      => true,
        ];
    }

    protected function filters(Request $request): array
    {
        return [
            'date_from' => $request->input('date_from') ?: null,
            'date_to'   => $request->input('date_to') ?: null,
            'months'    => (int) $request->input('months', ProductionCostRateService::DEFAULT_PERIOD_MONTHS),
        ];
    }
}
