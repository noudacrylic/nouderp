<?php
use App\Modules\Sales\Models\SalesDelivery;
use App\Models\InventoryCostLayer;
use App\Core\Inventory\StockLayer;
use App\Models\InventoryLedger;

$del = SalesDelivery::with('items')->find(371);
echo "Delivery {$del->delivery_number} wh={$del->warehouse_id}".PHP_EOL;
foreach ($del->items as $i) {
    echo "  item p={$i->product_id} qty={$i->qty} cogs_total={$i->cogs_total}".PHP_EOL;
}

echo PHP_EOL.'=== InventoryCostLayer reference warranty_delivery 371 (konsumsi saat SJ) ==='.PHP_EOL;
foreach (InventoryCostLayer::where('reference_type','warranty_delivery')->where('reference_id',371)->get() as $c) {
    echo "  p={$c->product_id} qty_out={$c->qty_out} unit_cost={$c->unit_cost} => ".number_format((float)$c->qty_out*(float)$c->unit_cost).PHP_EOL;
}

echo PHP_EOL.'=== StockLayer product 1 wh 2 (semua, untuk lihat asal cost) ==='.PHP_EOL;
foreach (StockLayer::where('product_id',1)->where('warehouse_id',2)->orderBy('id')->get() as $s) {
    echo "  layer#{$s->id} source={$s->source_type} src_id={$s->source_id} qty_in={$s->qty_in} remaining={$s->qty_remaining} unit_cost={$s->unit_cost} created={$s->created_at}".PHP_EOL;
}

echo PHP_EOL.'=== InventoryLedger product 1 wh 2 (riwayat) ==='.PHP_EOL;
foreach (InventoryLedger::where('product_id',1)->where('warehouse_id',2)->orderBy('id')->get() as $l) {
    echo "  led#{$l->id} {$l->transaction_type} txid={$l->transaction_id} in={$l->qty_in} out={$l->qty_out} bal={$l->balance} created={$l->created_at}".PHP_EOL;
}
