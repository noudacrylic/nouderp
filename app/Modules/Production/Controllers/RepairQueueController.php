<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\RepairGroup;
use App\Modules\Production\Services\RepairGroupService;
use App\Modules\Production\Services\RepairQueueService;
use Illuminate\Http\Request;

/**
 * Produksi › Barang Perbaikan — antrean barang di Gudang Perbaikan (tab Antrean) dan
 * Kelompok Perbaikan yang akan dijadikan OP (tab Kelompok).
 */
class RepairQueueController extends Controller
{
    public function index(Request $request, RepairQueueService $svc)
    {
        $tab = $request->tab === 'kelompok' ? 'kelompok' : 'antrean';

        $data = $svc->data($request->q);
        $data['tab']        = $tab;
        $data['kelompok']   = $tab === 'kelompok' ? $svc->kelompok($request->status) : null;
        $data['jmlTerbuka'] = RepairGroup::where('status', RepairGroup::TERBUKA)->count();

        return view('erp.production.perbaikan.index', $data);
    }

    public function storeGroup(Request $request, RepairGroupService $groups)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'notes'   => 'nullable|string|max:1000',
            'items'   => 'required|array|min:1',
            'items.*' => 'nullable|numeric|min:0',
        ], ['items.required' => 'Pilih minimal satu barang.']);

        try {
            $group = $groups->create($request->name, $request->items, $request->notes);
            return redirect()->route('production.perbaikan.index', ['tab' => 'kelompok'])
                ->with('success', "Kelompok {$group->number} dibuat.");
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function editGroup(int $id, RepairQueueService $svc)
    {
        $group = RepairGroup::with(['items.product:id,sku,name', 'productionOrder:id,order_number,status'])->findOrFail($id);

        // Baris yang bisa dipilih: stok yang belum dikelompokkan + pesanan kelompok ini sendiri.
        $tersedia = $svc->tersedia($group->id);
        $menunggu = $svc->data()['menunggu']->map(function ($m) use ($tersedia, $group) {
            $m['bisa'] = round((float) ($tersedia[$m['product_id']] ?? 0), 4);
            $m['isi']  = (float) ($group->items->firstWhere('product_id', $m['product_id'])?->qty ?? 0);
            return $m;
        })->filter(fn ($m) => $m['bisa'] > 0 || $m['isi'] > 0)->values();

        return view('erp.production.perbaikan.kelompok-edit', compact('group', 'menunggu'));
    }

    public function updateGroup(Request $request, int $id, RepairGroupService $groups)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'notes'   => 'nullable|string|max:1000',
            'items'   => 'required|array|min:1',
            'items.*' => 'nullable|numeric|min:0',
        ]);

        try {
            $group = $groups->update(RepairGroup::findOrFail($id), $request->name, $request->items, $request->notes);
            return redirect(list_url('production.perbaikan.index', ['tab' => 'kelompok']))
                ->with('success', "Kelompok {$group->number} diperbarui.");
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function cancelGroup(int $id, RepairGroupService $groups)
    {
        try {
            $group = RepairGroup::findOrFail($id);
            $groups->cancel($group);
            return back()->with('success', "Kelompok {$group->number} dibatalkan — barangnya kembali ke antrean.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
