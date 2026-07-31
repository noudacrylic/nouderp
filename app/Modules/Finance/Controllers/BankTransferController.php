<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\BankTransfer;
use App\Modules\Finance\Services\BankTransferService;
use App\Core\Accounting\Account;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    public function __construct(protected BankTransferService $service) {}

    public function index(Request $request)
    {
        $items = BankTransfer::with(['fromAccount', 'toAccount'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->q, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('number', 'like', "%{$term}%")
                      ->orWhere('reference', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('erp.finance.bank-transfers.index', compact('items'));
    }

    public function create()
    {
        return view('erp.finance.bank-transfers.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        try {
            $bt = $this->service->createDraft($data);
            $action = $request->input('_after_save', '');
            if ($action === 'post') {
                $this->service->post($bt);
                return redirect(list_url('finance.cash-bank.transfers.index'))
                    ->with('success', 'Transfer ' . $bt->number . ' dibuat & POSTED.');
            }
            return redirect(list_url('finance.cash-bank.transfers.index'))
                ->with('success', 'Transfer ' . $bt->number . ' dibuat sebagai draft.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        $bt = BankTransfer::with(['fromAccount', 'toAccount', 'adminFeeAccount'])->findOrFail($id);
        return view('erp.finance.bank-transfers.show', compact('bt'));
    }

    public function edit($id)
    {
        $bt = BankTransfer::findOrFail($id);
        if (!$bt->isDraft()) {
            return redirect()->route('finance.cash-bank.transfers.show', $bt->id)
                ->with('error', 'Hanya draft yang bisa diedit.');
        }
        return view('erp.finance.bank-transfers.edit', array_merge($this->formData(), compact('bt')));
    }

    public function update(Request $request, $id)
    {
        $bt = BankTransfer::findOrFail($id);
        $data = $this->validateData($request);
        try {
            $this->service->update($bt, $data);
            $action = $request->input('_after_save', '');
            if ($action === 'post') {
                $this->service->post($bt->refresh());
                return redirect(list_url('finance.cash-bank.transfers.index'))
                    ->with('success', 'Transfer ' . $bt->number . ' diupdate & POSTED.');
            }
            return redirect(list_url('finance.cash-bank.transfers.index'))
                ->with('success', 'Transfer ' . $bt->number . ' diupdate (draft).');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $bt = BankTransfer::findOrFail($id);
        if (!$bt->isDraft()) return back()->with('error', 'Hanya draft yang bisa dihapus.');
        $bt->delete();
        return back()->with('success', 'Draft dihapus.');
    }

    public function post($id)
    {
        $bt = BankTransfer::findOrFail($id);
        try {
            $this->service->post($bt);
            return redirect(list_url('finance.cash-bank.transfers.index'))
                ->with('success', 'Transfer ' . $bt->number . ' POSTED.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void($id)
    {
        $bt = BankTransfer::findOrFail($id);
        if (!$bt->canBeVoided()) return back()->with('error', 'Tidak bisa di-void.');
        try {
            $this->service->void($bt);
            return back()->with('success', 'Transfer ' . $bt->number . ' di-void.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print($id)
    {
        $bt = BankTransfer::with(['fromAccount', 'toAccount', 'adminFeeAccount'])->findOrFail($id);
        return view('erp.finance.bank-transfers.print', compact('bt'));
    }

    protected function formData(): array
    {
        $cashAccounts = Account::where('is_cash_account', 1)
            ->where('is_active', 1)->orderBy('code')->get();
        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', 1)->orderBy('code')->get();
        $defaultAdminFeeAccountId = Account::bankAdminFeeDefaultId();
        return compact('cashAccounts', 'expenseAccounts', 'defaultAdminFeeAccountId');
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'date'                 => 'required|date',
            'from_account_id'      => 'required|exists:accounts,id|different:to_account_id',
            'to_account_id'        => 'required|exists:accounts,id',
            'admin_fee_account_id' => 'nullable|exists:accounts,id',
            'amount'               => 'required|numeric|gt:0',
            'admin_fee'            => 'nullable|numeric|min:0',
            'fee_borne_by'         => 'nullable|in:source,destination',
            'reference'            => 'nullable|string|max:255',
            'notes'                => 'nullable|string',
        ]);
    }
}
