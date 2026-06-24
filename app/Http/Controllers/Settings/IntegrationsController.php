<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MidtransSetting;
use App\Models\ShippingSetting;
use App\Models\TelegramSetting;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;

/**
 * Hub Integrasi: daftar aplikasi yang terhubung dengan Noud ERP.
 * Tiap entri = satu kartu dengan status aktif/nonaktif + tombol konfigurasi.
 * Untuk menambah integrasi baru (mis. Kirimin Aja, Jubelio, WooCommerce),
 * cukup tambah satu entri di array $integrations.
 */
class IntegrationsController extends Controller
{
    public function index()
    {
        $midtrans   = MidtransSetting::singleton();
        $biteship   = ShippingSetting::for('biteship');
        $kiriminaja = ShippingSetting::for('kiriminaja');
        $jubelio    = JubelioSetting::singleton();
        $telegram   = TelegramSetting::current();

        $integrations = [
            [
                'name'        => 'Midtrans',
                'category'    => 'Payment Gateway',
                'description' => 'Payment link, QRIS & Virtual Account untuk terima pembayaran customer otomatis.',
                'icon'        => '💳',
                'active'      => !empty($midtrans->server_key) && !empty($midtrans->client_key),
                'mode'        => $midtrans->is_production ? 'Production' : 'Sandbox',
                'url'         => route('settings.midtrans.edit'),
            ],
            [
                'name'        => 'Biteship',
                'category'    => 'Kurir / Pengiriman',
                'description' => 'Aggregator kurir (JNE, J&T, SiCepat, AnterAja, dll) untuk cek ongkir & booking resi.',
                'icon'        => '📦',
                'active'      => $biteship->isConfigured(),
                'mode'        => $biteship->is_production ? 'Production' : 'Sandbox',
                'url'         => route('settings.shipping.biteship'),
            ],
            [
                'name'        => 'KiriminAja',
                'category'    => 'Kurir / Pengiriman',
                'description' => 'Aggregator kurir (reguler & instant GoSend/Grab) untuk cek ongkir & booking resi.',
                'icon'        => '🚚',
                'active'      => $kiriminaja->isConfigured(),
                'mode'        => $kiriminaja->is_production ? 'Production' : 'Sandbox',
                'url'         => route('settings.shipping.kiriminaja'),
            ],
            [
                'name'        => 'Jubelio',
                'category'    => 'Marketplace / Omnichannel',
                'description' => 'Sinkron pesanan marketplace (Shopee, Tokopedia, dll) jadi SO→SJ→Invoice & stok ERP sebagai sumber kebenaran.',
                'icon'        => '🛒',
                'active'      => $jubelio->isConfigured(),
                'mode'        => $jubelio->is_production ? 'Production' : 'Sandbox',
                'url'         => route('settings.jubelio.edit'),
            ],
            [
                'name'        => 'Telegram',
                'category'    => 'Notifikasi',
                'description' => 'Noud Bot — notifikasi pengajuan izin (approver) & status approval (karyawan) via Telegram.',
                'icon'        => '💬',
                'active'      => (bool) $telegram?->isConfigured(),
                'mode'        => $telegram && $telegram->webhook_secret ? 'Webhook' : 'Belum aktif',
                'url'         => route('settings.telegram.edit'),
            ],
        ];

        return view('erp.settings.integrations.index', compact('integrations'));
    }
}
