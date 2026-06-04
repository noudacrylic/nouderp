<?php

namespace App\Http\Controllers\Shipping;

use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use App\Modules\Shipping\ShippingManager;
use Illuminate\Http\Request;

/**
 * Cek ongkir JSON untuk embed di form SO/Invoice.
 * Origin = gudang terpilih, tujuan = area_id customer.
 */
class RatesController extends Controller
{
    public function __invoke(Request $request, ShippingManager $manager)
    {
        $data = $request->validate([
            'warehouse_id'            => 'required|exists:warehouses,id',
            'destination_area_id'     => 'required|string|max:100',
            'weight_gram'             => 'nullable|integer|min:1',
            'item_value'              => 'nullable|numeric|min:0',
            // Koordinat tujuan (opsional) — wajib agar kurir instant muncul.
            'destination_latitude'    => 'nullable|numeric|between:-90,90',
            'destination_longitude'   => 'nullable|numeric|between:-180,180',
            // Filter kurir: 'instant' (hanya instant) | 'regular' (kecuali instant) | kosong (semua).
            'mode'                    => 'nullable|in:instant,regular',
            // Dimensi paket (cm) — diisi manual di panel; untuk volumetrik (kurir instant pilih Pickup/Van).
            'package_length'          => 'nullable|numeric|min:0|max:1000',
            'package_width'           => 'nullable|numeric|min:0|max:1000',
            'package_height'          => 'nullable|numeric|min:0|max:1000',
        ]);

        $warehouse = Warehouse::find($data['warehouse_id']);
        $origin    = $warehouse->shippingOriginPayload();

        if (empty($origin)) {
            return response()->json([
                'success' => false,
                'rates'   => [],
                'errors'  => ["Gudang \"{$warehouse->name}\" belum punya alamat. Lengkapi di Inventory → Warehouse."],
            ]);
        }

        $dest = array_filter([
            'destination_area_id'   => $data['destination_area_id'],
            'destination_latitude'  => isset($data['destination_latitude'])  ? (float) $data['destination_latitude']  : null,
            'destination_longitude' => isset($data['destination_longitude']) ? (float) $data['destination_longitude'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        // Kurir instant butuh koordinat DUA sisi (asal + tujuan). Beri pesan jelas bila kurang.
        if (($data['mode'] ?? null) === 'instant') {
            if (empty($origin['origin_latitude']) || empty($origin['origin_longitude'])) {
                return response()->json(['success' => false, 'rates' => [], 'errors' => [
                    "Gudang \"{$warehouse->name}\" belum punya Titik Lokasi. Set koordinat gudang di Inventory → Warehouse untuk kurir instant.",
                ]]);
            }
            if (empty($dest['destination_latitude']) || empty($dest['destination_longitude'])) {
                return response()->json(['success' => false, 'rates' => [], 'errors' => [
                    'Alamat customer belum punya Titik Lokasi. Isi di Edit / Tambah Alamat untuk kurir instant.',
                ]]);
            }
        }

        // Satu item paket: berat total + dimensi manual (bila diisi). Dimensi (volumetrik) bikin
        // Biteship menawarkan kendaraan instant yang tepat (Pickup/Van utk barang bulky).
        $item = [
            'name'     => 'Paket',
            'value'    => (float) ($data['item_value'] ?? 0),
            'weight'   => (int) ($data['weight_gram'] ?? 1000),
            'quantity' => 1,
        ];
        foreach (['length' => 'package_length', 'width' => 'package_width', 'height' => 'package_height'] as $k => $f) {
            if (!empty($data[$f]) && (float) $data[$f] > 0) $item[$k] = (float) $data[$f];
        }

        $result = $manager->rates($origin + $dest + [
            'mode'  => $data['mode'] ?? null,
            'items' => [$item],
        ]);

        return response()->json([
            'success' => $result['success'],
            'rates'   => $result['rates'],
            'errors'  => $result['errors'],
        ]);
    }
}
