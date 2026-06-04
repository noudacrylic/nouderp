<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceIntegration;
use App\Models\Customer;
use App\Core\Accounting\Account;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    public function index()
    {
        $marketplaces = MarketplaceIntegration::with('customer')->get();
        return view('erp.integrations.marketplace.index', compact('marketplaces'));
    }

    public function create()
    {
        $accounts = Account::all();

        $marketplaceCustomers = Customer::where('customer_type', 'marketplace')->get();

        return view('erp.integrations.marketplace.create', compact(
            'accounts',
            'marketplaceCustomers'
        ));
    }

    public function store(Request $request)
    {
        MarketplaceIntegration::create([
            'customer_id' => $request->customer_id,
            'admin_fee_percent' => $request->admin_fee_percent,
            'admin_fee_nominal' => $request->admin_fee_nominal,
            'account_bank_id' => $request->account_bank_id,
            'account_revenue_id' => $request->account_revenue_id,
            'account_admin_expense_id' => $request->account_admin_expense_id,
            'account_recon_plus_id' => $request->account_recon_plus_id,
            'account_recon_minus_id' => $request->account_recon_minus_id,
        ]);

        return redirect(list_url('integrations.marketplace.index'));
    }

    public function edit($id)
    {
        $marketplace = MarketplaceIntegration::findOrFail($id);
        $accounts = Account::all();

        return view('erp.integrations.marketplace.edit', compact('marketplace', 'accounts'));
    }

    public function update(Request $request, $id)
    {
        $marketplace = MarketplaceIntegration::findOrFail($id);

        $marketplace->update([
            'admin_fee_percent' => $request->admin_fee_percent,
            'admin_fee_nominal' => $request->admin_fee_nominal,
            'account_bank_id' => $request->account_bank_id,
            'account_revenue_id' => $request->account_revenue_id,
            'account_admin_expense_id' => $request->account_admin_expense_id,
            'account_recon_plus_id' => $request->account_recon_plus_id,
            'account_recon_minus_id' => $request->account_recon_minus_id,
        ]);

        return redirect(list_url('integrations.marketplace.index'))->with('success', 'Marketplace settings updated.');
    }
}
