<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ManualCourier;
use Illuminate\Http\Request;

/**
 * Pengaturan "Jasa Kirim" — kelola daftar kurir manual (ekspedisi non-API).
 * Daftar ini muncul di kartu Pengiriman SO/Invoice; ongkir diisi manual.
 */
class ManualCourierController extends Controller
{
    public function index()
    {
        $couriers = ManualCourier::orderBy('sort_order')->orderBy('name')->get();
        return view('erp.settings.shipping-couriers.index', compact('couriers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        ManualCourier::create([
            'name'       => $data['name'],
            'code'       => ManualCourier::makeCode($data['name']),
            'is_active'  => true,
            'sort_order' => (int) (ManualCourier::max('sort_order') ?? 0) + 1,
        ]);

        return redirect()->route('settings.shipping-couriers.index')
            ->with('success', 'Jasa kirim "' . $data['name'] . '" ditambahkan.');
    }

    public function update(Request $request, ManualCourier $manualCourier)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $manualCourier->update([
            'name'      => $data['name'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('settings.shipping-couriers.index')
            ->with('success', 'Jasa kirim diperbarui.');
    }

    public function toggle(ManualCourier $manualCourier)
    {
        $manualCourier->update(['is_active' => !$manualCourier->is_active]);

        return redirect()->route('settings.shipping-couriers.index')
            ->with('success', $manualCourier->is_active
                ? 'Jasa kirim "' . $manualCourier->name . '" diaktifkan.'
                : 'Jasa kirim "' . $manualCourier->name . '" dinonaktifkan.');
    }

    public function destroy(ManualCourier $manualCourier)
    {
        $name = $manualCourier->name;
        $manualCourier->delete();

        return redirect()->route('settings.shipping-couriers.index')
            ->with('success', 'Jasa kirim "' . $name . '" dihapus.');
    }
}
