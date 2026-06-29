<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\Request;

/**
 * Pengaturan jembatan API etalase — Settings → Integrasi → Etalase Website.
 * Kelola kunci API (yang dipakai VPS/etalase) + aktif/nonaktif endpoint baca.
 */
class StorefrontSettingController extends Controller
{
    public function edit()
    {
        $setting = StorefrontSetting::singleton();
        return view('erp.settings.storefront.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $data = $request->validate(['is_active' => 'nullable|boolean']);

        $setting = StorefrontSetting::singleton();
        $setting->is_active = (bool) ($data['is_active'] ?? false);
        if (empty($setting->api_key)) {
            $setting->api_key = StorefrontSetting::generateKey();
        }
        $setting->save();

        return redirect()->route('settings.storefront.edit')->with('success', 'Pengaturan Etalase disimpan.');
    }

    public function generate()
    {
        $setting = StorefrontSetting::singleton();
        $setting->api_key = StorefrontSetting::generateKey();
        $setting->save();

        return redirect()->route('settings.storefront.edit')
            ->with('success', 'Kunci API baru dibuat. Perbarui kunci di sisi etalase/VPS.');
    }
}
