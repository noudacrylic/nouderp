<?php

namespace App\Modules\FixedAsset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Models\FixedAssetDisposal;
use App\Modules\FixedAsset\Services\FixedAssetDisposalService;
use App\Core\Accounting\Account;
use Illuminate\Http\Request;

class FixedAssetDisposalController extends Controller
{
    public function __construct(
        protected FixedAssetDisposalService $service
    ) {}

    public function index(Request $request)
    {
        $disposals = FixedAssetDisposal::with(['asset.category', 'proceedsAccount', 'journal'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->disposal_type, fn($q, $t) => $q->where('disposal_type', $t))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('disposal_date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('disposal_date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('disposal_number', 'like', "%{$term}%")
                      ->orWhereHas('asset', fn($a) => $a->where('asset_code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('disposal_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('erp.fixed-assets.disposals.index', compact('disposals'));
    }

    public function create(Request $request)
    {
        $asset = null;
        if ($request->asset_id) {
            $asset = FixedAsset::with('category')->find($request->asset_id);
        }
        $proceedsAccounts = Account::whereIn('type', ['asset'])
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->where('is_cash_account', true)
                  ->orWhere('account_category', 'cash')
                  ->orWhere('account_category', 'cash_equivalent')
                  ->orWhere('account_category', 'receivable');
            })
            ->orderBy('code')
            ->get();
        return view('erp.fixed-assets.disposals.create', compact('asset', 'proceedsAccounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $action = $request->input('_after_save', '');

        try {
            $disposal = $this->service->createDraft($data);
            if ($action === 'post' || $action === 'print') {
                $this->service->post($disposal->refresh());
            }
            if ($action === 'print') {
                return redirect()->route('fixed-assets.disposals.show', ['id' => $disposal->id, 'print' => 1])
                    ->with('success', 'Disposisi dibuat & POSTED.');
            }
            $msg = $action === 'post' ? 'Disposisi dibuat & POSTED.' : 'Disposisi draft dibuat.';
            return redirect(list_url('fixed-assets.disposals.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $disposal = FixedAssetDisposal::with(['asset.category', 'proceedsAccount', 'journal.lines.account'])->findOrFail($id);
        return view('erp.fixed-assets.disposals.show', compact('disposal'));
    }

    public function edit($id)
    {
        $disposal = FixedAssetDisposal::with('asset')->findOrFail($id);
        if ($disposal->status !== 'draft') {
            return redirect()->route('fixed-assets.disposals.show', $disposal->id)
                ->with('error', 'Hanya disposisi draft yang bisa diedit.');
        }
        $proceedsAccounts = Account::whereIn('type', ['asset'])
            ->where('is_active', 1)
            ->orderBy('code')
            ->get();
        return view('erp.fixed-assets.disposals.edit', compact('disposal', 'proceedsAccounts'));
    }

    public function update(Request $request, $id)
    {
        $disposal = FixedAssetDisposal::findOrFail($id);
        $data = $request->validate([
            'disposal_date' => 'required|date',
            'disposal_type' => 'required|in:sale,damage,loss,donation,scrap',
            'proceeds_amount' => 'nullable|numeric|min:0',
            'proceeds_account_id' => 'nullable|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);
        $action = $request->input('_after_save', '');

        try {
            $this->service->update($disposal, $data);
            if ($action === 'post') {
                $this->service->post($disposal->refresh());
            }
            $msg = $action === 'post' ? 'Disposisi diupdate & POSTED.' : 'Disposisi diupdate.';
            return redirect(list_url('fixed-assets.disposals.index'))->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $disposal = FixedAssetDisposal::findOrFail($id);
        try {
            $this->service->destroy($disposal);
            return redirect(list_url('fixed-assets.disposals.index'))->with('success', 'Disposisi draft dihapus.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post($id)
    {
        $disposal = FixedAssetDisposal::findOrFail($id);
        try {
            $this->service->post($disposal);
            return back()->with('success', 'Disposisi POSTED + jurnal terbuat.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $disposal = FixedAssetDisposal::findOrFail($id);
        try {
            $this->service->void($disposal);
            return back()->with('success', 'Disposisi di-void; aset kembali aktif.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print($id)
    {
        $disposal = FixedAssetDisposal::with(['asset.category', 'proceedsAccount', 'journal.lines.account'])->findOrFail($id);
        return view('erp.fixed-assets.disposals.print', compact('disposal'));
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'fixed_asset_id' => 'required|exists:fixed_assets,id',
            'disposal_date' => 'required|date',
            'disposal_type' => 'required|in:sale,damage,loss,donation,scrap',
            'proceeds_amount' => 'nullable|numeric|min:0',
            'proceeds_account_id' => 'nullable|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
