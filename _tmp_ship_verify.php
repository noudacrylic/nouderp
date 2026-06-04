<?php
use App\Models\ShippingSetting;
use App\Modules\Shipping\Providers\BiteshipProvider;
use App\Modules\Shipping\ShippingManager;
use Illuminate\Support\Facades\Http;

echo '--- Routes ---'.PHP_EOL;
foreach (['settings.shipping.biteship','settings.shipping.biteship.update','sales.cek-ongkir','sales.cek-ongkir.check'] as $r) {
    echo "  {$r}: ".(\Route::has($r)?'OK':'MISSING').PHP_EOL;
}

echo PHP_EOL.'--- ShippingSetting ---'.PHP_EOL;
$s = ShippingSetting::for('biteship');
echo "  provider={$s->provider} base=".$s->effectiveBaseUrl()." configured=".var_export($s->isConfigured(),true).PHP_EOL;

// Aktifkan sementara + fake API untuk tes parsing rates
$s->update(['is_enabled'=>true,'api_key'=>'biteship_test_DUMMY']);

Http::fake([
    '*/v1/rates/couriers' => Http::response([
        'success' => true,
        'pricing' => [
            ['courier_code'=>'jne','courier_name'=>'JNE','courier_service_code'=>'reg','courier_service_name'=>'Reguler','price'=>12000,'duration'=>'2-3 days'],
            ['courier_code'=>'jnt','courier_name'=>'J&T','courier_service_code'=>'ez','courier_service_name'=>'EZ','price'=>10000,'duration'=>'2-4 days'],
        ],
    ], 200),
]);

$mgr = app(ShippingManager::class);
echo PHP_EOL.'--- rates (mocked) ---'.PHP_EOL;
$res = $mgr->rates([
    'origin_postal_code'=>'40111','destination_postal_code'=>'10110',
    'items'=>[['name'=>'Paket','value'=>50000,'weight'=>1000,'quantity'=>1]],
]);
echo '  success='.var_export($res['success'],true).' errors='.json_encode($res['errors']).PHP_EOL;
foreach ($res['rates'] as $r) {
    echo "    {$r['courier_code']} {$r['service_name']} Rp ".number_format($r['price'])." | {$r['etd']}".PHP_EOL;
}

// kembalikan setting ke nonaktif (jangan tinggalkan dummy key aktif)
$s->update(['is_enabled'=>false,'api_key'=>null]);
echo PHP_EOL.'  (setting dikembalikan nonaktif)'.PHP_EOL;

echo PHP_EOL.'--- Blade compile ---'.PHP_EOL;
foreach ([
    'resources/views/erp/settings/shipping/biteship.blade.php',
    'resources/views/erp/sales/cek-ongkir/index.blade.php',
    'resources/views/erp/inventory/products/_shipping_dims.blade.php',
] as $f) {
    try { \Illuminate\Support\Facades\Blade::compileString(file_get_contents(base_path($f))); echo "  OK: {$f}".PHP_EOL; }
    catch (\Throwable $e) { echo "  ERR {$f}: ".$e->getMessage().PHP_EOL; }
}
