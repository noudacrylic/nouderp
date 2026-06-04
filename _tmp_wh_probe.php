<?php
use App\Models\WarrantyOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Core\Inventory\Warehouse;

// Simulasi repairSourceWarehouseId
$warrantyWh = WarrantyOrder::find(1)?->warehouse_id;
echo "Warranty 1 warehouse_id = {$warrantyWh} (".(Warehouse::find($warrantyWh)?->name).")".PHP_EOL;

// SalesReturn punya warehouse_id?
$srCols = \Illuminate\Support\Facades\Schema::getColumnListing('sales_returns');
echo 'sales_returns punya kolom warehouse_id? '.(in_array('warehouse_id',$srCols)?'YA':'TIDAK').PHP_EOL;
$sr = SalesReturn::find(11);
echo "SalesReturn 11 warehouse_id = ".var_export($sr?->warehouse_id, true).PHP_EOL;

// blade compile
try {
    \Illuminate\Support\Facades\Blade::compileString(file_get_contents(base_path('resources/views/erp/production/orders/create.blade.php')));
    echo 'BLADE OK'.PHP_EOL;
} catch (\Throwable $e) { echo 'BLADE ERROR: '.$e->getMessage().PHP_EOL; }
