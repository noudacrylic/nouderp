<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PaymentFeeSetting;
use App\Core\Accounting\Account;
use App\Enums\AccountTypeEnum;

class PaymentFeeSettingController extends Controller
{
    public function index()
    {
        $settings = PaymentFeeSetting::with(['cashAccount', 'expenseAccount'])->get();
        
        $cashAccounts = Account::where('type', AccountTypeEnum::ASSET)
            ->whereIn('account_category', ['cash', 'cash_equivalent'])
            ->get();
            
        $expenseAccounts = Account::where('type', AccountTypeEnum::EXPENSE)
            ->orderBy('code')
            ->get();

        return view('erp.settings.payment-fee.index', compact('settings', 'cashAccounts', 'expenseAccounts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|exists:payment_fee_settings,id',
            'cash_account_id' => 'required|exists:accounts,id',
            'fee_flat' => 'nullable|numeric|min:0',
            'fee_percent' => 'nullable|numeric|min:0|max:100',
            'expense_account_id' => 'required|exists:accounts,id',
        ]);

        PaymentFeeSetting::updateOrCreate(
            ['id' => $validated['id'] ?? null],
            [
                'cash_account_id' => $validated['cash_account_id'],
                'fee_flat' => $validated['fee_flat'] ?? 0,
                'fee_percent' => $validated['fee_percent'] ?? 0,
                'expense_account_id' => $validated['expense_account_id']
            ]
        );

        return back()->with('success', 'Setting biaya admin berhasil disimpan.');
    }
    
    public function destroy($id)
    {
        PaymentFeeSetting::findOrFail($id)->delete();
        return back()->with('success', 'Setting berhasil dihapus.');
    }
}
