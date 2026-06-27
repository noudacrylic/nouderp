<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AnthropicSetting;
use Illuminate\Http\Request;

/**
 * Pengaturan Claude AI (Anthropic) — Settings → Integrasi → Claude AI.
 * Menyimpan API key + model + ambang konfirmasi di DB (singleton) supaya
 * tidak perlu edit .env di server.
 */
class AnthropicSettingController extends Controller
{
    public function edit()
    {
        $setting = AnthropicSetting::singleton();

        // Fallback .env (kalau key belum diisi via UI) — sekadar info ke admin.
        $envKeySet = ! empty(config('services.anthropic.key'));

        return view('erp.settings.anthropic.edit', compact('setting', 'envKeySet'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'api_key'           => 'nullable|string|max:255',
            'model_text'        => 'required|string|max:64',
            'model_vision'      => 'required|string|max:64',
            'confirm_threshold' => 'required|integer|min:0',
            'is_active'         => 'nullable|boolean',
        ]);

        $setting = AnthropicSetting::singleton();
        $setting->fill([
            // Blank dibiarkan null → jatuh ke fallback .env.
            'api_key'           => $data['api_key'] ?: null,
            'model_text'        => $data['model_text'],
            'model_vision'      => $data['model_vision'],
            'confirm_threshold' => (int) $data['confirm_threshold'],
            'is_active'         => (bool) ($data['is_active'] ?? false),
        ]);
        $setting->save();

        return redirect()->route('settings.anthropic.edit')
            ->with('success', 'Pengaturan Claude AI disimpan.');
    }
}
