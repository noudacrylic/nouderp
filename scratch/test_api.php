<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req = new Illuminate\Http\Request(['customer_id' => 2]); 
$resp = app(App\Modules\Sales\Controllers\SalesReturnController::class)->getSalesOrders($req); 
print_r($resp->getContent());
