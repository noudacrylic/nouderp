<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AnthropicSetting;
use App\Models\MidtransSetting;
use App\Models\PaymentSetting;
use App\Models\R2Setting;
use App\Models\ShippingSetting;
use App\Models\StorefrontSetting;
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
        $payment    = PaymentSetting::singleton();
        $rajaongkir = ShippingSetting::for('rajaongkir');
        $biteship   = ShippingSetting::for('biteship');
        $kiriminaja = ShippingSetting::for('kiriminaja');
        $jubelio    = JubelioSetting::singleton();
        $telegram   = TelegramSetting::current();
        $anthropic  = AnthropicSetting::current();
        $r2         = R2Setting::current();
        $storefront = StorefrontSetting::current();

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
                'name'        => 'Pembayaran Toko Online',
                'category'    => 'Payment',
                'description' => 'QRIS (QRISLY/Komerce) + transfer bank kode unik. Konfirmasi otomatis → eskalasi Telegram → auto-batal 24 jam.',
                'icon'        => '🏦',
                'active'      => $payment->isConfigured(),
                'mode'        => $payment->is_active ? ucfirst($payment->confirmation_provider) : 'Belum aktif',
                'url'         => route('settings.payment.edit'),
            ],
            [
                // Kartu terpisah supaya QRIS gampang dicari, isinya blok QRIS di halaman
                // Pembayaran (satu halaman, satu simpanan).
                'name'        => 'QRIS (QRISLY)',
                'category'    => 'Payment',
                'description' => 'QRIS dinamis dari QRIS statis milik sendiri. Dana masuk langsung ke rekening toko; deteksi via Mobile App Listener.',
                'icon'        => '🔳',
                'active'      => $payment->qrisReady(),
                'mode'        => $payment->conf('qris_enabled')
                    ? ucfirst((string) $payment->conf('qris_env', 'sandbox'))
                    : 'Belum aktif',
                'url'         => route('settings.payment.edit') . '#qris',
            ],
            [
                'name'        => 'RajaOngkir',
                'category'    => 'Kurir / Pengiriman',
                'description' => 'Cek ongkir (JNE, J&T, SiCepat, AnterAja, POS, TIKI, dll). Ramah perorangan, tanpa badan usaha. Booking manual.',
                'icon'        => '📦',
                'active'      => $rajaongkir->isConfigured(),
                'mode'        => 'Production',
                'url'         => route('settings.shipping.rajaongkir'),
            ],
            [
                'name'        => 'Biteship',
                'category'    => 'Kurir / Pengiriman',
                'description' => 'Aggregator kurir untuk cek ongkir & booking resi. DINONAKTIFKAN (wajib badan usaha).',
                'icon'        => '📦',
                'active'      => $biteship->isConfigured(),
                'mode'        => $biteship->is_production ? 'Production' : 'Sandbox',
                'url'         => route('settings.shipping.biteship'),
            ],
            [
                'name'        => 'KiriminAja',
                'category'    => 'Kurir / Pengiriman',
                'description' => 'Aggregator kurir (reguler & instant). DINONAKTIFKAN (verifikasi tertunda).',
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
            [
                'name'        => 'Claude AI',
                'category'    => 'Asisten / AI',
                'description' => 'Asisten pencatat keuangan via Telegram — catat pengeluaran & prive lewat chat (ngobrol bahasa natural).',
                'icon'        => '🤖',
                'active'      => (bool) $anthropic?->isConfigured(),
                'mode'        => $anthropic && $anthropic->is_active ? ($anthropic->model_text ?: 'Aktif') : 'Belum aktif',
                'url'         => route('settings.anthropic.edit'),
            ],
            [
                'name'        => 'Cloudflare R2',
                'category'    => 'Penyimpanan Media',
                'description' => 'Object storage untuk foto & video etalase (Produk Store) — disajikan cepat via CDN, hemat (egress gratis).',
                'icon'        => '🗄️',
                'active'      => (bool) $r2?->isConfigured(),
                'mode'        => $r2 && $r2->is_active ? ($r2->bucket ?: 'Aktif') : 'Belum aktif',
                'url'         => route('settings.r2.edit'),
            ],
            [
                'name'        => 'Etalase Website',
                'category'    => 'API / Storefront',
                'description' => 'Kunci API jembatan untuk website toko (noudakrilik.com) membaca katalog, stok live, & promosi dari ERP.',
                'icon'        => '🌐',
                'active'      => (bool) $storefront?->isConfigured(),
                'mode'        => $storefront && $storefront->is_active ? 'Aktif' : 'Belum aktif',
                'url'         => route('settings.storefront.edit'),
            ],
        ];

        return view('erp.settings.integrations.index', compact('integrations'));
    }
}
