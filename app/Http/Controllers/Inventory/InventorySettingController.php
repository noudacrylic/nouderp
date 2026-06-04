<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Core\Accounting\Account;
use App\Models\InventoryAccountSetting;

class InventorySettingController extends Controller
{
    private const DEFAULT_ACCOUNTS = [
        'inventory_asset_account_id' => '1130',
        'inventory_gain_account_id'  => '4100',
        'inventory_loss_account_id'  => '5200',
        'repair_account_id'          => '1131',
        'opening_balance_account_id' => '3000',
    ];

    public function edit()
    {
        $accounts = Account::orderBy('code')->get();
        $setting = InventoryAccountSetting::first();

        if (!$setting) {
            $codeToId = $accounts->pluck('id', 'code');
            $defaults = [];
            foreach (self::DEFAULT_ACCOUNTS as $field => $code) {
                if (isset($codeToId[$code])) {
                    $defaults[$field] = $codeToId[$code];
                }
            }
            $setting = InventoryAccountSetting::updateOrCreate(['id' => 1], $defaults);
        }

        return view('erp.inventory.settings', compact('accounts', 'setting'));
    }

    public function update(Request $request)
    {
        InventoryAccountSetting::updateOrCreate(
            ['id' => 1],
            [
                'inventory_asset_account_id' => $request->inventory_asset_account_id,
                'inventory_gain_account_id'  => $request->inventory_gain_account_id,
                'inventory_loss_account_id'  => $request->inventory_loss_account_id,
                'repair_account_id'          => $request->repair_account_id,
                'opening_balance_account_id' => $request->opening_balance_account_id,
            ]
        );

        return back()->with('success', 'Inventory setting saved');
    }
}
