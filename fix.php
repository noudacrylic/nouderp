<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (\App\Models\SalesQuotation::all() as $q) {
    $sub = \App\Models\SalesQuotationItem::where('quotation_id', $q->id)->sum('subtotal');
    $discount = $q->discount_global ?? 0;
    $shipping = $q->shipping_charge ?? 0;
    $service = $q->service_charge ?? 0;
    if ($q->global_discount_type === 'percent') {
        $discount = ($sub * $discount) / 100;
    }
    $grand = $sub - $discount + $shipping + $service;
    $q->update(['subtotal' => $sub, 'grand_total' => $grand]);
}
echo "Done";
