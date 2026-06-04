<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceConfig;
use App\Models\Customer;
use Illuminate\Http\Request;

class MarketplaceConfigController extends Controller
{
    public function index()
    {
        $configs = MarketplaceConfig::with('customer', 'holdAccount', 'feeAccount', 'walletAccount')->get();
        $customers = Customer::all();
        $accounts = \App\Core\Accounting\Account::orderBy('code')->get();

        return view('erp.settings.marketplace.index', compact('configs', 'customers', 'accounts'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id|unique:marketplace_configs,customer_id',
            'admin_fee_percent' => 'nullable|numeric|min:0',
            'admin_fee_fixed' => 'nullable|numeric|min:0',
            'account_receivable_hold_id' => 'required|exists:accounts,id',
            'account_fee_id' => 'required|exists:accounts,id',
            'account_wallet_id' => 'required|exists:accounts,id',
        ]);

        MarketplaceConfig::create([
            'customer_id' => $request->customer_id,
            'admin_fee_percent' => $request->admin_fee_percent ?? 0,
            'admin_fee_fixed' => $request->admin_fee_fixed ?? 0,
            'account_receivable_hold_id' => $request->account_receivable_hold_id,
            'account_fee_id' => $request->account_fee_id,
            'account_wallet_id' => $request->account_wallet_id,
            'is_active' => true,
        ]);

        Customer::where('id', $request->customer_id)->update(['is_marketplace' => true]);

        return back()->with('success', 'Konfigurasi Marketplace berhasil dibuat.');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'admin_fee_percent' => 'nullable|numeric|min:0',
            'admin_fee_fixed' => 'nullable|numeric|min:0',
            'account_receivable_hold_id' => 'required|exists:accounts,id',
            'account_fee_id' => 'required|exists:accounts,id',
            'account_wallet_id' => 'required|exists:accounts,id',
        ]);

        $config = MarketplaceConfig::findOrFail($id);

        $config->update([
            'admin_fee_percent' => $request->admin_fee_percent ?? 0,
            'admin_fee_fixed' => $request->admin_fee_fixed ?? 0,
            'account_receivable_hold_id' => $request->account_receivable_hold_id,
            'account_fee_id' => $request->account_fee_id,
            'account_wallet_id' => $request->account_wallet_id,
            'is_active' => $request->has('is_active') ? $request->is_active : $config->is_active,
        ]);

        return back()->with('success', 'Konfigurasi Marketplace berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $config = MarketplaceConfig::findOrFail($id);
        $customerId = $config->customer_id;
        $config->delete();

        Customer::where('id', $customerId)->update(['is_marketplace' => false]);

        return back()->with('success', 'Konfigurasi Marketplace berhasil dihapus.');
    }
}
