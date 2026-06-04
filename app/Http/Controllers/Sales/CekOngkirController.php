<?php

namespace App\Http\Controllers\Sales;

use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use App\Modules\Shipping\ShippingManager;
use Illuminate\Http\Request;

class CekOngkirController extends Controller
{
    public function index()
    {
        return view('erp.sales.cek-ongkir.index', [
            'warehouses' => $this->originWarehouses(),
            'selectedWarehouseId' => Warehouse::defaultId(),
            'rates'  => null,
            'errors' => [],
            'input'  => [],
        ]);
    }

    public function check(Request $request, ShippingManager $manager)
    {
        $data = $request->validate([
            'warehouse_id'         => 'required|exists:warehouses,id',
            'destination_area_id'  => 'required|string|max:255',
            'destination_label'    => 'nullable|string|max:255',
            'weight_gram'          => 'required|integer|min:1',
            'item_value'           => 'nullable|numeric|min:0',
        ], [
            'destination_area_id.required' => 'Pilih alamat tujuan dari daftar pencarian.',
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $origin    = $warehouse->shippingOriginPayload();

        if (empty($origin)) {
            return $this->render($warehouse->id, $data, null, [
                "Gudang \"{$warehouse->name}\" belum punya alamat pengiriman. Lengkapi dulu di Inventory → Warehouse → Edit.",
            ]);
        }

        $result = $manager->rates($origin + [
            'destination_area_id' => $data['destination_area_id'],
            'items' => [[
                'name'     => 'Paket',
                'value'    => (float) ($data['item_value'] ?? 0),
                'weight'   => (int) $data['weight_gram'],
                'quantity' => 1,
            ]],
        ]);

        return $this->render($warehouse->id, $data, $result['rates'], $result['errors']);
    }

    private function render(int $warehouseId, array $input, ?array $rates, array $errors)
    {
        return view('erp.sales.cek-ongkir.index', [
            'warehouses' => $this->originWarehouses(),
            'selectedWarehouseId' => $warehouseId,
            'rates'  => $rates,
            'errors' => $errors,
            'input'  => $input,
        ]);
    }

    /** Gudang aktif yang bisa jadi asal pengiriman. */
    private function originWarehouses()
    {
        return Warehouse::where('is_active', 1)->orderBy('name')->get();
    }
}
