<?php

namespace App\Modules\Sales\Controllers;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Modules\Sales\Models\CustomerCredit;
use App\Modules\Sales\Services\CustomerCreditService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Kredit Pelanggan — memberi / mengurangi saldo kredit pelanggan tanpa ada uang yang bergerak.
 * Dipakai untuk barang yang kembali tanpa refund (tukar ukuran), koreksi, dan kasus lama yang
 * fakturnya tidak ada di ERP. Lihat CustomerCreditService untuk alasan & letak saldonya.
 */
class CustomerCreditController extends Controller
{
    /** Akun lawan bawaan: rugi yang timbul karena penjualan lama dibatalkan. */
    private const DEFAULT_COUNTER_ACCOUNT = '6105';

    public function index(Request $request)
    {
        $query = CustomerCredit::with(['customer:id,name', 'counterAccount:id,code,name']);

        if ($search = trim((string) $request->search)) {
            $query->where(function ($q) use ($search) {
                $q->where('credit_number', 'like', "%$search%")
                    ->orWhere('reason', 'like', "%$search%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%$search%"));
            });
        }

        if ($request->direction && array_key_exists($request->direction, CustomerCredit::DIRECTIONS)) {
            $query->where('direction', $request->direction);
        }

        if (in_array($request->status, ['posted', 'void'], true)) {
            $query->where('status', $request->status);
        }

        if ($request->date_from) {
            $query->whereDate('credit_date', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('credit_date', '<=', $request->date_to);
        }

        $credits = $query->orderByDesc('id')->paginate(per_page_size())->withQueryString();

        return view('erp.sales.customer-credits.index', [
            'credits'    => $credits,
            'directions' => CustomerCredit::DIRECTIONS,
        ]);
    }

    public function create()
    {
        return view('erp.sales.customer-credits.create', [
            'customers'  => Customer::aktif()->orderBy('name')->get(['id', 'name']),
            'accounts'   => $this->counterAccounts(),
            'directions' => CustomerCredit::DIRECTIONS,
            'defaultAccountId' => Account::where('code', self::DEFAULT_COUNTER_ACCOUNT)->value('id'),
        ]);
    }

    public function store(Request $request, CustomerCreditService $service)
    {
        $data = $request->validate([
            'customer_id'        => 'required|exists:customers,id',
            'credit_date'        => 'required|date',
            'direction'          => 'required|in:tambah,kurang',
            'amount'             => 'required|string',
            'counter_account_id' => 'required|exists:accounts,id',
            'reason'             => 'nullable|string|max:1000',
        ], [], [
            'customer_id'        => 'Pelanggan',
            'counter_account_id' => 'Akun lawan',
        ]);

        // Input rupiah datang berformat Indonesia ("84.150") — jangan pernah di-cast langsung.
        $data['amount']     = clean_number($request->input('amount'));
        $data['created_by'] = auth()->id();

        try {
            $credit = $service->create($data);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect(list_url('sales.kredit.index'))
            ->with('success', "Kredit Pelanggan {$credit->credit_number} dibuat.");
    }

    public function void(int $id, CustomerCreditService $service)
    {
        $credit = CustomerCredit::findOrFail($id);

        try {
            $service->void($credit);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Kredit Pelanggan {$credit->credit_number} di-void.");
    }

    /** Akun yang masuk akal jadi lawan: beban & pendapatan, tanpa akun induk. */
    private function counterAccounts()
    {
        return Account::whereIn('type', ['expense', 'revenue'])
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
