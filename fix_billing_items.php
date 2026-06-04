<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$items = DB::table('customer_billing_items')->whereNull('document_number')->get();

foreach ($items as $item) {
    if ($item->invoice_id) {
        $inv = DB::table('sales_invoices')->find($item->invoice_id);
        if ($inv) {
            DB::table('customer_billing_items')->where('id', $item->id)->update([
                'document_number' => $inv->invoice_number,
                'document_date' => $inv->invoice_date
            ]);
            echo "Updated item #{$item->id} with Invoice {$inv->invoice_number}\n";
        }
    } elseif ($item->sales_order_id) {
        $so = DB::table('sales_orders')->find($item->sales_order_id);
        if ($so) {
            DB::table('customer_billing_items')->where('id', $item->id)->update([
                'document_number' => $so->order_number,
                'document_date' => $so->order_date
            ]);
            echo "Updated item #{$item->id} with SO {$so->order_number}\n";
        }
    }
}
echo "Done.\n";
