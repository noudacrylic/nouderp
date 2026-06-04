<?php

namespace App\Http\Controllers\Shipping;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\ProductUnit;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Hitung total berat (gram) untuk item di form SO/Invoice → mengisi field Berat
 * pada panel Pengiriman. Expand bundle ke komponen, konversi qty unit ke base.
 */
class WeightController extends Controller
{
    public function __invoke(Request $request)
    {
        $items = $request->input('items', []);
        $total = 0.0;

        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            $qty = (float) ($it['qty'] ?? 0);
            if (!$pid || $qty <= 0) {
                continue;
            }

            $conv = 1.0;
            if (!empty($it['unit_id'])) {
                $conv = (float) (ProductUnit::where('id', $it['unit_id'])->value('conversion_to_base') ?? 1);
            }
            $baseQty = $qty * ($conv ?: 1);

            $product = Product::find($pid);
            if (!$product) {
                continue;
            }

            if ($product->sale_type === 'bundle') {
                $components = BundleComponent::where('bundle_product_id', $pid)->get();
                $qf = 'qty';
                if ($components->isEmpty()) {
                    $components = ProductBundle::where('bundle_product_id', $pid)->get();
                    $qf = 'qty_required';
                }
                foreach ($components as $c) {
                    $comp = Product::find($c->component_product_id);
                    if ($comp) {
                        $total += (int) ($comp->weight_gram ?? 0) * (float) ($c->{$qf} ?? 1) * $baseQty;
                    }
                }
            } else {
                $total += (int) ($product->weight_gram ?? 0) * $baseQty;
            }
        }

        return response()->json(['weight_gram' => (int) round($total)]);
    }
}
