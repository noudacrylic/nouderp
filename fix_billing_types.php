<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Fix billing_type based on items
$billings = DB::table('customer_billings')->get();

foreach ($billings as $billing) {
    // Check if any item in this billing is a Sales Order
    $hasSO = DB::table('customer_billing_items')
        ->where('customer_billing_id', $billing->id)
        ->whereNotNull('sales_order_id')
        ->exists();

    if ($hasSO) {
        DB::table('customer_billings')
            ->where('id', $billing->id)
            ->update(['billing_type' => 'sales_order']);
        echo "Updated Billing #{$billing->billing_number} to 'sales_order'\n";
    } else {
        DB::table('customer_billings')
            ->where('id', $billing->id)
            ->update(['billing_type' => 'invoice']);
        echo "Updated Billing #{$billing->billing_number} to 'invoice'\n";
    }
}
echo "Done.\n";
