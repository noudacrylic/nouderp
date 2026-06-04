<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$soId = 5; // Assuming ID for SO/2026/04/00005. Or let's query by number.
$so = App\Modules\Sales\Models\SalesOrder::where('order_number', 'SO/2026/04/00005')->first();

if (!$so) {
    echo "SO Not Found!\n";
    exit;
}

echo "SO found: {$so->order_number}\n";
echo "Customer ID: {$so->customer_id}\n";
echo "Paid Amount: {$so->paid_amount}\n";
echo "Grand Total: {$so->grand_total}\n";

$hasDelivery = $so->deliveries()->where('status', 'posted')->exists();
echo "Has Posted Delivery: " . ($hasDelivery ? 'Yes' : 'No') . "\n";

$hasReturns = $so->returns()->exists();
echo "Has Returns: " . ($hasReturns ? 'Yes' : 'No') . "\n";

$isPaid = $so->paid_amount >= $so->grand_total;
echo "Is Paid (paid >= grand): " . ($isPaid ? 'Yes' : 'No') . "\n";

$q1 = App\Modules\Sales\Models\SalesOrder::where('id', $so->id)
    ->whereHas('deliveries', function ($q) {
        $q->where('status', 'posted');
    })->exists();
echo "Filter 1 (Deliveries): " . ($q1 ? 'Pass' : 'Fail') . "\n";

$q2 = App\Modules\Sales\Models\SalesOrder::where('id', $so->id)
    ->whereRaw('COALESCE(paid_amount, 0) >= grand_total')->exists();
echo "Filter 2 (Paid): " . ($q2 ? 'Pass' : 'Fail') . "\n";

$q3 = App\Modules\Sales\Models\SalesOrder::where('id', $so->id)
    ->whereDoesntHave('returns')->exists();
echo "Filter 3 (Returns): " . ($q3 ? 'Pass' : 'Fail') . "\n";

