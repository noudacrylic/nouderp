<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Core\Accounting\Account;
use App\Core\Journal\JournalLine;

class AccountController extends Controller
{
    /**
     * Live search akun untuk form picker.
     * ?q=keyword&types[]=asset&types[]=expense (filter by AccountTypeEnum value)
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $types = (array) $request->get('types', []);
        $limit = (int) ($request->get('limit', 20));

        $query = Account::where('is_active', true);

        if (!empty($types)) {
            $query->whereIn('type', $types);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%");
            });
        }

        $accounts = $query->orderBy('code')->limit($limit)->get(['id', 'code', 'name', 'type', 'normal_balance']);
        return response()->json($accounts);
    }

    public function index()
    {
        $status = request('status', 'active');
        
        // Kecualikan jurnal void agar saldo di daftar akun IDENTIK dgn ledger (show())
        // & Neraca. journalLines() relasi polos ikut menghitung baris jurnal void,
        // sehingga akun dgn jurnal yang di-void (mis. koreksi settlement marketplace)
        // menampilkan saldo salah di halaman ini padahal ledger sudah benar.
        $query = Account::with(['journalLines' => function ($q) {
            $q->whereHas('journal', fn ($j) => $j->where('status', 'posted'));
        }])->orderBy('code');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'archived') {
            $query->where('is_active', false);
        }

        $accounts = $query->get()->map(function ($account) {
            $debit = $account->journalLines->sum('debit');
            $credit = $account->journalLines->sum('credit');

            $balance = $account->normal_balance === 'debit'
                ? $debit - $credit
                : $credit - $debit;

            $account->debit = $debit;
            $account->credit = $credit;
            $account->balance = $balance;

            return $account;
        });

        // urutan tipe manual
        $typeOrder = ['asset', 'liability', 'equity', 'revenue', 'expense'];

        $groups = collect();

        foreach ($typeOrder as $type) {
            $typeAccounts = $accounts->where('type', $type);

            if ($typeAccounts->count() > 0) {
                if ($type === 'asset') {
                    $groups[$type] = $typeAccounts->groupBy(function ($item) {
                        return $item->account_category ?? 'other';
                    });
                } else {
                    // Selain asset langsung 1 group
                    $groups[$type] = ['main' => $typeAccounts];
                }
            }
        }

        return view('erp.accounts.index', compact('groups'));
    }

    public function archive(Account $account)
    {
        $account->update(['is_active' => false]);
        return back()->with('success', 'Akun berhasil diarsipkan.');
    }

    public function restore(Account $account)
    {
        $account->update(['is_active' => true]);
        return back()->with('success', 'Akun berhasil diaktifkan kembali.');
    }

    public function show(Account $account)
    {
        // Daftar tahun yang punya transaksi (untuk dropdown filter "Tahun").
        // Hanya jurnal posted — selaras dgn Neraca/Dashboard (AccountBalanceService),
        // supaya jurnal void (mis. DP order yang di-void Jubelio) tidak ikut terhitung.
        $availableYears = JournalLine::where('account_id', $account->id)
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted'))
            ->selectRaw('YEAR(created_at) as y')
            ->distinct()
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (string) $y);

        // Tahun & bulan terpilih. Default = tahun + bulan berjalan saat halaman dibuka tanpa filter.
        // Bulan = '01'..'12' atau '' (— Semua Bulan —); Tahun = 'YYYY' atau '' (— Semua —).
        $noFilter = ! request()->hasAny(['year', 'month', 'start_date', 'end_date']);
        $selectedYear  = $noFilter ? now()->format('Y') : (string) request('year', '');
        $selectedMonth = $noFilter ? now()->format('m') : (string) request('month', '');

        // Pastikan tahun terpilih selalu muncul di dropdown (mis. tahun berjalan belum ada transaksi)
        if ($selectedYear !== '' && ! $availableYears->contains($selectedYear)) {
            $availableYears = $availableYears->push($selectedYear)->sortDesc()->values();
        }

        $query = JournalLine::where('account_id', $account->id)
            // Kecualikan jurnal void agar running balance halaman ini identik dgn Neraca.
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted'))
            ->with('journal')
            ->orderBy('created_at');

        if ($selectedYear !== '' || $selectedMonth !== '') {
            // Filter berdasarkan tahun dan/atau bulan — mengabaikan start/end date
            if ($selectedYear !== '') {
                $query->whereYear('created_at', $selectedYear);
            }
            if ($selectedMonth !== '') {
                $query->whereMonth('created_at', $selectedMonth);
            }
        } else {
            if (request('start_date')) {
                $query->whereDate('created_at', '>=', request('start_date'));
            }

            if (request('end_date')) {
                $query->whereDate('created_at', '<=', request('end_date'));
            }
        }

        $lines = $query->get();

        $runningBalance = 0;

        foreach ($lines as $line) {
            // Fallback: ambil ref dari journal header untuk data lama yang belum punya ref di line
            if (empty($line->reference_number) && $line->journal) {
                $line->reference_number = $line->journal->reference_number;
                $line->reference_type   = $line->journal->reference_type;
                $line->reference_id     = $line->journal->reference_id;
            }

            if ($account->normal_balance === 'debit') {
                $runningBalance += $line->debit - $line->credit;
            } else {
                $runningBalance += $line->credit - $line->debit;
            }

            $line->running_balance = $runningBalance;
        }

        $grouped = $lines->groupBy(function ($item) {
            return $item->created_at->format('Y-m');
        });

        return view('erp.accounts.show', compact('account', 'grouped', 'availableYears', 'selectedYear', 'selectedMonth'));
    }

    public function create()
    {
        return view('erp.accounts.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|unique:accounts,code',
            'name' => 'required',
            'type' => 'required',
            'normal_balance' => 'required',
        ]);

        $data = $request->all();
        if ($request->type !== 'asset') {
            $data['account_category'] = null;
        }

        Account::create($data);

        return redirect(list_url('accounts.index'))->with('success', 'Akun berhasil dibuat.');
    }

    public function edit(Account $account)
    {
        $isUsed = \App\Core\Journal\JournalLine::where('account_id', $account->id)->exists();
        return view('erp.accounts.edit', compact('account', 'isUsed'));
    }

    public function update(Request $request, Account $account)
    {
        $isUsed = \App\Core\Journal\JournalLine::where('account_id', $account->id)->exists();

        $data = $request->all();
        if ($request->type !== 'asset') {
            $data['account_category'] = null;
        }

        if ($account->is_system) {
            unset($data['code']); 
            unset($data['type']);
            unset($data['normal_balance']);
        }

        if ($isUsed && isset($data['code']) && $data['code'] != $account->code) {
            return back()->withErrors(['code' => 'Nomor akun tidak bisa diubah karena sudah memiliki riwayat transaksi (ledger).']);
        }

        $account->update($data);

        return redirect(list_url('accounts.index'))->with('success', 'Akun berhasil diperbarui.');
    }

    public function destroy(Account $account)
    {
        if ($account->is_system) {
            return back()->with('error', 'System account cannot be deleted.');
        }

        $used = \App\Core\Journal\JournalLine::where('account_id', $account->id)->exists();

        if ($used) {
            return back()->with('error', 'Account already used in journal.');
        }

        $account->delete();

        return back()->with('success', 'Akun berhasil dihapus.');
    }
    public function getNextCode(Request $request)
    {
        $type = $request->type;
        $category = $request->category;

        $typeMap = [
            'asset' => 1,
            'liability' => 2,
            'equity' => 3,
            'revenue' => 4,
            'expense' => 5,
        ];

        $categoryMap = [
            'cash' => 1,
            'cash_equivalent' => 2,
            'receivable' => 3,
            'inventory' => 4,
        ];

        $prefix = $typeMap[$type] ?? 9;

        if ($type === 'asset' && $category) {
            $prefix .= $categoryMap[$category] ?? 9;
        } else {
            $prefix .= '0';
        }

        $last = \App\Core\Accounting\Account::where('code', 'like', $prefix . '%')
            ->whereRaw('LENGTH(code) = 4')
            ->orderByDesc('code')
            ->first();

        if ($last) {
            $next = (int)$last->code + 1;
        } else {
            $next = (int)($prefix . '01');
        }

        return response()->json(['code' => $next]);
    }
}
