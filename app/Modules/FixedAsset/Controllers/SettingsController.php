<?php

namespace App\Modules\FixedAsset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAsset\Models\FixedAssetSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        $setting = FixedAssetSetting::instance();
        $setting->load(['openingOffsetAccount', 'defaultDisposalProceedsAccount', 'disposalGainAccount', 'disposalLossAccount']);
        return view('erp.fixed-assets.settings.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'opening_offset_account_id' => 'required|exists:accounts,id',
            'default_disposal_proceeds_account_id' => 'nullable|exists:accounts,id',
            'disposal_gain_account_id' => 'required|exists:accounts,id',
            'disposal_loss_account_id' => 'required|exists:accounts,id',
            'auto_run_depreciation_on_close' => 'nullable|boolean',
        ]);

        $setting = FixedAssetSetting::instance();
        $setting->fill([
            'opening_offset_account_id' => $data['opening_offset_account_id'],
            'default_disposal_proceeds_account_id' => $data['default_disposal_proceeds_account_id'] ?? null,
            'disposal_gain_account_id' => $data['disposal_gain_account_id'],
            'disposal_loss_account_id' => $data['disposal_loss_account_id'],
            'auto_run_depreciation_on_close' => (bool) ($data['auto_run_depreciation_on_close'] ?? false),
        ]);
        $setting->save();

        return redirect()->route('fixed-assets.settings.edit')->with('success', 'Settings Aset Tetap tersimpan.');
    }
}
