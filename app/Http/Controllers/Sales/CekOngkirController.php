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
            'warehouse_id'          => 'required|exists:warehouses,id',
            'destination_area_id'   => 'nullable|string|max:255',
            'destination_kiriminaja_id' => 'nullable|string|max:255',
            'destination_provider'  => 'nullable|in:biteship,kiriminaja',
            'destination_label'     => 'nullable|string|max:255',
            'weight_gram'           => 'required|integer|min:1',
            'item_value'            => 'nullable|numeric|min:0',
            // Mode: 'instant' (kurir on-demand, butuh koordinat) | 'regular'/null (kurir biasa).
            'mode'                  => 'nullable|in:instant,regular',
            // Titik lokasi tujuan — wajib utk kurir instant (Grab/GoSend/Lalamove).
            'destination_latitude'  => 'nullable|numeric|between:-90,90',
            'destination_longitude' => 'nullable|numeric|between:-180,180',
            // Dimensi paket (cm) — volumetrik utk pilihan kendaraan instant & barang besar.
            'package_length'        => 'nullable|numeric|min:0|max:1000',
            'package_width'         => 'nullable|numeric|min:0|max:1000',
            'package_height'        => 'nullable|numeric|min:0|max:1000',
        ]);

        $mode     = $data['mode'] ?? null;
        $hasCoord = ($data['destination_latitude'] ?? null) !== null
                 && ($data['destination_longitude'] ?? null) !== null;

        // Provider tujuan (multi-provider). Area id KiriminAja (kecamatan) beda dgn area_id Biteship.
        $provider = $data['destination_provider'] ?? null;
        $destId   = $provider === 'kiriminaja'
            ? ($data['destination_kiriminaja_id'] ?? null)
            : ($data['destination_area_id'] ?? null);

        // Validasi tujuan sesuai mode.
        if ($mode === 'instant') {
            if (!$hasCoord) {
                return $this->render($data['warehouse_id'], $data, null, [
                    'Kurir instant butuh Titik Lokasi tujuan. Paste link Google Maps / koordinat lalu klik "Lacak Alamat".',
                ]);
            }
        } elseif (empty($destId)) {
            return $this->render($data['warehouse_id'], $data, null, [
                'Pilih alamat tujuan dari daftar pencarian (atau Lacak Alamat dari koordinat).',
            ]);
        }

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $origin    = $warehouse->shippingOriginPayload();

        if (empty($origin)) {
            return $this->render($warehouse->id, $data, null, [
                "Gudang \"{$warehouse->name}\" belum punya alamat pengiriman. Lengkapi dulu di Inventory → Warehouse → Edit.",
            ]);
        }

        if ($mode === 'instant' && (empty($origin['origin_latitude']) || empty($origin['origin_longitude']))) {
            return $this->render($warehouse->id, $data, null, [
                "Gudang \"{$warehouse->name}\" belum punya Titik Lokasi (koordinat). Lengkapi di Inventory → Warehouse → Edit untuk kurir instant.",
            ]);
        }

        $dest = array_filter([
            'destination_area_id'       => $provider === 'kiriminaja' ? null : ($data['destination_area_id'] ?? null),
            'destination_kiriminaja_id' => $provider === 'kiriminaja' ? ($data['destination_kiriminaja_id'] ?? null) : null,
            'destination_latitude'  => $hasCoord ? (float) $data['destination_latitude']  : null,
            'destination_longitude' => $hasCoord ? (float) $data['destination_longitude'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        $item = [
            'name'     => 'Paket',
            'value'    => (float) ($data['item_value'] ?? 0),
            'weight'   => (int) $data['weight_gram'],
            'quantity' => 1,
        ];
        foreach (['length' => 'package_length', 'width' => 'package_width', 'height' => 'package_height'] as $k => $f) {
            if (!empty($data[$f]) && (float) $data[$f] > 0) $item[$k] = (float) $data[$f];
        }

        $result = $manager->rates($origin + $dest + array_filter([
            'mode'     => $mode,
            'provider' => $provider,
            'items'    => [$item],
        ], fn ($v) => $v !== null));

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
