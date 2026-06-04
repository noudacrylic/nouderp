<?php
use Illuminate\Support\Facades\DB;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Production\Models\ProductionOrder;

$soId = 361;

DB::beginTransaction();
try {
    ProductionOrder::where('sales_order_id', $soId)->where('created_via','auto_preorder')->delete();
    SalesOrderItem::where('sales_order_id', $soId)->delete();

    $mk = function($pid, $qty) use ($soId) {
        SalesOrderItem::create([
            'sales_order_id' => $soId, 'product_id' => $pid, 'unit_name' => 'pcs',
            'conversion_to_base' => 1, 'qty' => $qty, 'unit_price' => 0,
            'discount_type' => 'percent', 'discount_value' => 0, 'discount_per_unit' => 0,
            'net_unit_price' => 0, 'line_subtotal' => 0, 'line_discount' => 0, 'line_total' => 0,
        ]);
    };
    $mk(12, 2);  // preorder, BOM auto → harus jadi OP cycles 2
    $mk(18, 1);  // preorder, BOM auto → harus jadi OP cycles 1
    $mk(1, 5);   // ready stock → harus di-skip

    $so = SalesOrder::with('items.product','customer')->find($soId);
    echo "Items SO: ";
    echo $so->items->map(fn($i)=>"{$i->product->name}({$i->product->sale_type}) x{$i->qty}")->join(' | ')."\n\n";

    $res = app(App\Modules\Production\Services\PreorderAutoProductionService::class)->runForSalesOrder($so);
    echo "Hasil runForSalesOrder:\n";
    foreach ($res as $r) {
        echo "  produk {$r['product_id']}: created=".($r['created']?'YA':'tidak')." | {$r['reason']}\n";
    }

    $ops = ProductionOrder::where('sales_order_id',$soId)->where('created_via','auto_preorder')->with('outputs')->get();
    echo "\nOP yang dibuat: {$ops->count()}\n";
    foreach ($ops as $op) {
        $main = $op->outputs->firstWhere('output_type','main');
        echo "  {$op->order_number} status={$op->status} planned_cycles={$op->planned_cycles} main_output_product={$main?->product_id}\n";
    }
} catch (Throwable $e) {
    echo "ERROR: ".$e->getMessage()."\n  at ".basename($e->getFile()).":".$e->getLine()."\n";
} finally { DB::rollBack(); echo "\n(rollback)\n"; }
