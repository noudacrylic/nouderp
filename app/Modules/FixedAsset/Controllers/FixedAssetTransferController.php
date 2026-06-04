<?php

namespace App\Modules\FixedAsset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Models\FixedAssetTransfer;
use App\Modules\FixedAsset\Services\FixedAssetTransferService;
use App\Core\Inventory\Warehouse;
use Illuminate\Http\Request;

class FixedAssetTransferController extends Controller
{
    public function __construct(
        protected FixedAssetTransferService $service
    ) {}

    public function index(Request $request)
    {
        $transfers = FixedAssetTransfer::with(['asset.category', 'fromWarehouse', 'toWarehouse'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('transfer_number', 'like', "%{$term}%")
                      ->orWhereHas('asset', fn($a) => $a->where('asset_code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('erp.fixed-assets.transfers.index', compact('transfers'));
    }

    public function create(Request $request)
    {
        $asset = null;
        if ($request->asset_id) {
            $asset = FixedAsset::with('warehouse')->find($request->asset_id);
        }
        $warehouses = Warehouse::where('is_active', 1)->orderBy('name')->get();
        return view('erp.fixed-assets.transfers.create', compact('asset', 'warehouses'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $action = $request->input('_after_save', '');

        try {
            $tr = $this->service->createDraft($data);
            if ($action === 'post') {
                $this->service->post($tr->refresh());
            }
            $msg = $action === 'post' ? 'Transfer dibuat & POSTED.' : 'Transfer draft dibuat.';
            return redirect(list_url('fixed-assets.transfers.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $transfer = FixedAssetTransfer::with(['asset.category', 'fromWarehouse', 'toWarehouse'])->findOrFail($id);
        return view('erp.fixed-assets.transfers.show', compact('transfer'));
    }

    public function edit($id)
    {
        $transfer = FixedAssetTransfer::with('asset')->findOrFail($id);
        if ($transfer->status !== 'draft') {
            return redirect()->route('fixed-assets.transfers.show', $transfer->id)
                ->with('error', 'Hanya transfer draft yang bisa diedit.');
        }
        $warehouses = Warehouse::where('is_active', 1)->orderBy('name')->get();
        return view('erp.fixed-assets.transfers.edit', compact('transfer', 'warehouses'));
    }

    public function update(Request $request, $id)
    {
        $transfer = FixedAssetTransfer::findOrFail($id);
        $data = $request->validate([
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);
        $action = $request->input('_after_save', '');

        try {
            $this->service->update($transfer, $data);
            if ($action === 'post') {
                $this->service->post($transfer->refresh());
            }
            $msg = $action === 'post' ? 'Transfer diupdate & POSTED.' : 'Transfer diupdate.';
            return redirect(list_url('fixed-assets.transfers.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $transfer = FixedAssetTransfer::findOrFail($id);
        try {
            $this->service->destroy($transfer);
            return redirect(list_url('fixed-assets.transfers.index'))->with('success', 'Transfer draft dihapus.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post($id)
    {
        $transfer = FixedAssetTransfer::findOrFail($id);
        try {
            $this->service->post($transfer);
            return back()->with('success', 'Transfer POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $transfer = FixedAssetTransfer::findOrFail($id);
        try {
            $this->service->void($transfer);
            return back()->with('success', 'Transfer di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'fixed_asset_id' => 'required|exists:fixed_assets,id',
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
