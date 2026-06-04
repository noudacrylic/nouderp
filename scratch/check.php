<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$so = App\Modules\Sales\Models\SalesOrder::where('order_number', 'SO/2026/04/00005')->first(); 
if($so) { 
    print_r([
        'advances' => $so->advances()->get(['id','amount','credit_used','status'])->toArray(), 
        'payments' => $so->payments()->get(['id','amount','customer_payment_id'])->toArray()
    ]); 
} else { 
    echo 'SO not found'; 
}
