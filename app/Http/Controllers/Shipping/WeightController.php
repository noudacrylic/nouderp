<?php

namespace App\Http\Controllers\Shipping;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductUnit;
use App\Http\Controllers\Controller;
use App\Modules\Shipping\Services\PackageDefaults;
use Illuminate\Http\Request;

/**
 * Taksiran berat & dimensi paket untuk baris yang sedang diketik di form
 * SO/Faktur → mengisi panel Pengiriman pada dokumen BARU (yang belum tersimpan,
 * jadi PackageDefaults belum punya dokumen untuk dibaca).
 *
 * Aturannya TIDAK ditulis di sini. Endpoint ini hanya menerjemahkan baris form
 * jadi bentuk yang dimengerti PackageDefaults, lalu meneruskan jawabannya.
 *
 * Dulu di sini ada aturan sendiri — bundle dipecah jadi komponen — sementara
 * PackageDefaults memakai berat bundle apa adanya. Dua aturan untuk satu
 * pertanyaan, dan yang salah justru yang dipakai form: bundle Noud adalah produk
 * yang sudah dikemas (bubble, peti kayu), sedangkan komponen kemasannya kerap
 * tak punya berat tercatat — jadi berat kemasan menguap dan ongkirnya kurang
 * tagih. 89 dari 142 bundle terdampak, rata-rata 837 gram, terburuk 3.620 gram.
 *
 * Dimensi ikut dikembalikan karena alasan yang sama: sebelumnya endpoint ini
 * hanya menjawab berat, sehingga pada dokumen baru kolom P/L/T tak pernah terisi
 * dan dikirim sebagai 0 ke Jubelio. Padahal untuk 31 produk yang dijual, berat
 * volumetriknya justru MELEBIHI berat aslinya — itulah yang ditagih kurir.
 */
class WeightController extends Controller
{
    public function __construct(private PackageDefaults $defaults) {}

    public function __invoke(Request $request)
    {
        $items = [];

        foreach ($request->input('items', []) as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            $qty = (float) ($it['qty'] ?? 0);
            if (!$pid || $qty <= 0) {
                continue;
            }

            $product = Product::find($pid);
            if (!$product) {
                continue;
            }

            $conv = 1.0;
            if (!empty($it['unit_id'])) {
                $conv = (float) (ProductUnit::where('id', $it['unit_id'])->value('conversion_to_base') ?? 1);
            }

            // PackageDefaults membaca ->product, ->qty, ->conversion_to_base —
            // bentuk baris dokumen tersimpan. Baris form ditiru jadi bentuk itu
            // supaya aturannya tidak perlu ditulis dua kali.
            $items[] = (object) [
                'product'            => $product,
                'qty'                => $qty,
                'conversion_to_base' => $conv ?: 1,
            ];
        }

        $pkg = $this->defaults->for(null, $items);

        return response()->json([
            'weight_gram' => (int) ($pkg['weight_gram'] ?? 0),
            // null (bukan 0) bila produknya belum diukur — supaya JS tahu bedanya
            // "belum diukur" dan "diukur nol", dan tidak menimpa isian operator.
            'length'      => $pkg['length'],
            'width'       => $pkg['width'],
            'height'      => $pkg['height'],
        ]);
    }
}
