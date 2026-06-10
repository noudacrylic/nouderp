<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ShippingSetting;
use App\Modules\Shipping\Providers\BiteshipProvider;
use App\Modules\Shipping\Providers\KiriminAjaProvider;
use Illuminate\Http\Request;

class ShippingSettingController extends Controller
{
    public function biteship(BiteshipProvider $provider)
    {
        $setting   = ShippingSetting::for('biteship');
        $available = $provider->availableCouriers();
        $enabled   = $provider->enabledCourierCodes();

        // Belum ada pilihan tersimpan → anggap semua kurir aktif (pre-check semua).
        $selected = !empty($enabled) ? $enabled : array_keys($available['couriers']);

        return view('erp.settings.shipping.biteship', [
            'setting'          => $setting,
            'couriers'         => $available['couriers'],
            'couriersError'    => $available['success'] ? null : $available['error'],
            'selectedCouriers' => $selected,
            'courierCollection' => (array) ($setting->config['courier_collection'] ?? []),
        ]);
    }

    public function updateBiteship(Request $request)
    {
        $data = $request->validate([
            'is_enabled'    => 'nullable|boolean',
            'is_production' => 'nullable|boolean',
            'api_key'       => 'nullable|string|max:500',
            'base_url'      => 'nullable|string|max:255',
            'webhook_token' => 'nullable|string|max:255',
            'couriers'      => 'nullable|array',
            'couriers.*'    => 'string|max:50',
            'courier_collection'   => 'nullable|array',
            'courier_collection.*' => 'in:pickup,dropoff',
        ]);

        $setting = ShippingSetting::for('biteship');

        $config = $setting->config ?? [];
        $config['couriers'] = array_values(array_unique($request->input('couriers', [])));
        $config['courier_collection'] = $request->input('courier_collection', []);

        $setting->update([
            'is_enabled'    => $request->boolean('is_enabled'),
            'is_production' => $request->boolean('is_production'),
            'api_key'       => $data['api_key'] ?? null,
            'base_url'      => $data['base_url'] ?: ShippingSetting::DEFAULT_BASE_URL['biteship'],
            'webhook_token' => $data['webhook_token'] ?? null,
            'config'        => $config,
        ]);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Pengaturan Biteship tersimpan.');
    }

    // ───────────── KiriminAja (Fase 6) ─────────────

    public function kiriminaja(KiriminAjaProvider $provider)
    {
        $setting  = ShippingSetting::for('kiriminaja');
        $enabled  = $provider->enabledCourierCodes();
        $couriers = KiriminAjaProvider::DEFAULT_COURIERS;
        $selected = !empty($enabled) ? $enabled : array_keys($couriers);

        $balance = null;
        if ($setting->isConfigured()) {
            $res = $provider->balance();
            $balance = $res['success'] ? $res['balance'] : null;
        }

        return view('erp.settings.shipping.kiriminaja', [
            'setting'          => $setting,
            'couriers'         => $couriers,
            'instantServices'  => KiriminAjaProvider::INSTANT_SERVICES,
            'selectedCouriers' => $selected,
            'balance'          => $balance,
        ]);
    }

    public function updateKiriminaja(Request $request)
    {
        $data = $request->validate([
            'is_enabled'    => 'nullable|boolean',
            'is_production' => 'nullable|boolean',
            'api_key'       => 'nullable|string|max:500',
            'base_url'      => 'nullable|string|max:255',
            'webhook_token' => 'nullable|string|max:255',
            'couriers'      => 'nullable|array',
            'couriers.*'    => 'string|max:50',
        ]);

        $setting = ShippingSetting::for('kiriminaja');

        $config = $setting->config ?? [];
        $config['couriers'] = array_values(array_unique($request->input('couriers', [])));

        $setting->update([
            'is_enabled'    => $request->boolean('is_enabled'),
            'is_production' => $request->boolean('is_production'),
            'api_key'       => $data['api_key'] ?? null,
            // base_url kosong → biarkan null supaya provider auto-pilih sandbox/prod.
            'base_url'      => $data['base_url'] ?: null,
            'webhook_token' => $data['webhook_token'] ?? null,
            'config'        => $config,
        ]);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Pengaturan KiriminAja tersimpan.');
    }
}
