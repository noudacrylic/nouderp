<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CrmSetting;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Providers\ApiCoIdProvider;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Settings → Integrasi → CRM WhatsApp (api.co.id Chat Gateway).
 *
 * Jalur notifikasi WAHA punya layarnya sendiri (WahaSettingController): vendor
 * berbeda, kunci berbeda, dan jatuh sendiri-sendiri. Yang TETAP tinggal di
 * sini adalah pengaman GLOBAL — saklar jangan-kirim & daftar putih penerima —
 * karena kedua jalur mematuhi config yang sama persis, dan menduplikasinya
 * akan melahirkan dua sumber kebenaran.
 *
 * Layar ini memegang dua hal yang sifatnya berbeda:
 *  1. KREDENSIAL vendor (api key, base url, rahasia webhook, nomor default) →
 *     kolom tabel crm_settings.
 *  2. PENGAMAN OPERASIONAL (saklar jangan-kirim, daftar putih penerima, jam
 *     toko, rem ukuran & masa simpan lampiran) → crm_settings.config, disiram
 *     ke atas config('crm.*') oleh CrmRuntimeConfig saat boot.
 *
 * Diagnostik (uji koneksi, daftar nomor, status webhook) SENGAJA memakai
 * adapter asli, bukan ChatManager. ChatManager menyerahkan driver palsu selama
 * saklar jangan-kirim menyala — benar untuk mengirim, tapi di layar ini justru
 * menyesatkan: yang ingin dijawab adalah "kredensial saya jalan atau tidak".
 * Aman karena ketiganya hanya MEMBACA; tak ada pesan yang bisa keluar dari sini.
 */
class CrmSettingController extends Controller
{
    public function edit(ChatManager $chat)
    {
        $setting = CrmSetting::for('apicoid');

        $numbers  = null;
        $webhooks = null;

        if ($setting->isConfigured()) {
            $provider = new ApiCoIdProvider($setting);
            $numbers  = $provider->phoneNumbers();
            $webhooks = $provider->webhooks();
        }

        return view('erp.settings.crm.edit', [
            'setting'    => $setting,
            'numbers'    => $numbers,
            'webhooks'   => $webhooks,
            'webhookUrl'      => url('/crm/webhook'),
            'webhookTokenUrl' => url('/crm/webhook/' . $setting->webhookToken()),
            'dryRun'     => $chat->isDryRun(),
            'nilai'      => $this->nilaiEfektif(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'is_enabled'              => 'nullable|boolean',
            'api_key'                 => 'nullable|string|max:255',
            'base_url'                => 'nullable|url|max:255',
            'webhook_secret'          => 'nullable|string|max:255',
            'default_phone_number_id' => 'nullable|string|max:64',

            'dry_run'                   => 'nullable|boolean',
            'allowed_recipients'        => 'nullable|string|max:2000',
            'store_hours_text'          => 'nullable|string|max:120',
            'store_open_hour'           => 'nullable|integer|min:0|max:23',
            'store_close_hour'          => 'nullable|integer|min:1|max:24',
            'max_media_mb'              => 'nullable|integer|min:1|max:200',
            'attachment_retention_days' => 'nullable|integer|min:30|max:3650',
        ]);

        $setting = CrmSetting::for('apicoid');

        // Rahasia dibiarkan kosong = JANGAN diubah. Nilainya tak pernah
        // ditampilkan balik ke layar, jadi menimpanya dengan string kosong
        // akan mematikan integrasi tanpa disadari siapa pun.
        $setting->is_enabled = (bool) ($data['is_enabled'] ?? false);
        $setting->base_url   = $data['base_url'] ?: null;
        $setting->default_phone_number_id = $data['default_phone_number_id'] ?: null;

        if (filled($data['api_key'] ?? null)) {
            $setting->api_key = trim($data['api_key']);
        }
        if (filled($data['webhook_secret'] ?? null)) {
            $setting->webhook_secret = trim($data['webhook_secret']);
        }

        $setting->config = array_merge((array) $setting->config, [
            'dry_run'                   => (bool) ($data['dry_run'] ?? false),
            'allowed_recipients'        => $this->uraikanNomor($data['allowed_recipients'] ?? ''),
            'store_hours_text'          => $data['store_hours_text'] ?: config('crm.store_hours_text'),
            'store_open_hour'           => (int) ($data['store_open_hour'] ?? 8),
            'store_close_hour'          => (int) ($data['store_close_hour'] ?? 16),
            'max_media_bytes'           => (int) ($data['max_media_mb'] ?? 25) * 1024 * 1024,
            'attachment_retention_days' => (int) ($data['attachment_retention_days'] ?? 180),
        ]);

        $setting->save();

        CrmRuntimeConfig::forget();
        CrmRuntimeConfig::apply();

        $pesan = 'Pengaturan CRM disimpan.';

        if (! ($setting->config['dry_run'] ?? true)) {
            $daftar = $setting->config['allowed_recipients'] ?? [];
            $pesan .= $daftar
                ? ' Pengiriman NYATA aktif, terbatas ' . count($daftar) . ' nomor di daftar putih.'
                : ' PERHATIAN: pengiriman NYATA aktif TANPA daftar putih — semua pelanggan bisa menerima pesan.';
        }

        return redirect()->route('settings.crm.edit')->with('success', $pesan);
    }

    /**
     * Bikin ulang token URL webhook. URL lama langsung mati — pasang yang baru
     * di dasbor vendor sebelum menutup layar, kalau tidak pesan berhenti masuk.
     */
    public function regenerateToken()
    {
        CrmSetting::for('apicoid')->regenerateWebhookToken();

        return redirect()->route('settings.crm.edit')
            ->with('success', 'Token URL webhook dibuat ulang. URL lama sudah mati — pasang URL baru di dasbor api.co.id sekarang.');
    }

    /** Uji kredensial dengan panggilan baca-saja (daftar nomor bisnis). */
    public function uji()
    {
        $setting = CrmSetting::for('apicoid');

        if (! $setting->isConfigured()) {
            return back()->with('error', 'Isi API Key dan centang "Aktifkan" dulu, lalu simpan.');
        }

        $res = (new ApiCoIdProvider($setting))->phoneNumbers();

        if (! $res['success']) {
            return back()->with('error', 'Gagal terhubung ke api.co.id: ' . $res['error']);
        }

        $jumlah = count($res['numbers']);
        $daftar = implode(', ', array_filter(array_map(
            fn ($n) => $n['display'] ?: $n['phone_number_id'],
            $res['numbers']
        )));

        return back()->with('success', "Terhubung. {$jumlah} nomor bisnis: {$daftar}");
    }

    /**
     * Nyalakan ulang endpoint webhook yang dimatikan vendor.
     *
     * Ini bukan kenyamanan: vendor mematikan endpoint setelah gagal beruntun
     * dan tidak memberi tahu siapa pun. Tanpa tombol ini, satu jam ERP mati
     * berarti pesan pelanggan berhenti masuk selamanya sampai ada yang sadar.
     */
    public function aktifkanWebhook()
    {
        $setting = CrmSetting::for('apicoid');

        if (! $setting->isConfigured()) {
            return back()->with('error', 'Isi API Key dan centang "Aktifkan" dulu, lalu simpan.');
        }

        $provider = new ApiCoIdProvider($setting);
        $daftar   = $provider->webhooks();

        if (! $daftar['success']) {
            return back()->with('error', 'Gagal membaca daftar webhook: ' . $daftar['error']);
        }

        $mati = array_values(array_filter($daftar['endpoints'], fn ($e) => ! $e['is_active']));

        if (! $mati) {
            return back()->with('success', 'Semua endpoint webhook sudah aktif.');
        }

        $berhasil = 0;
        $gagal    = [];

        foreach ($mati as $endpoint) {
            $res = $provider->enableWebhook($endpoint['id']);

            if ($res['success']) {
                $berhasil++;
            } else {
                $gagal[] = $endpoint['id'] . ': ' . $res['error'];
            }
        }

        if ($gagal) {
            return back()->with('error', "{$berhasil} endpoint diaktifkan, sisanya gagal — " . implode('; ', $gagal));
        }

        return back()->with('success', "{$berhasil} endpoint webhook diaktifkan kembali.");
    }

    /** Nilai efektif yang sedang dipakai modul (DB bila ada, kalau tidak config/env). */
    private function nilaiEfektif(): array
    {
        CrmRuntimeConfig::apply(true);

        return [
            'dry_run'                   => (bool) config('crm.dry_run', true),
            'allowed_recipients'        => implode("\n", (array) config('crm.allowed_recipients', [])),
            'store_hours_text'          => (string) config('crm.store_hours_text'),
            'store_open_hour'           => (int) config('crm.store_open_hour', 8),
            'store_close_hour'          => (int) config('crm.store_close_hour', 16),
            'max_media_mb'              => (int) round(((int) config('crm.max_media_bytes', 26214400)) / 1024 / 1024),
            'attachment_retention_days' => (int) config('crm.attachment_retention_days', 180),
            'notifikasi_driver'         => (string) config('crm.notifikasi.driver', 'resmi'),
        ];
    }

    /**
     * Uraikan daftar putih (baris/koma) jadi nomor E.164 tanpa '+'.
     * Dinormalkan DI SINI, bukan saat memeriksa: kalau tidak, nomor yang
     * diketik '0855-777-4446' tak akan pernah cocok dengan '62855…' yang
     * dipakai adapter, dan daftar putihnya diam-diam memblokir semuanya.
     */
    private function uraikanNomor(string $raw): array
    {
        $pecah = preg_split('/[\s,;]+/', $raw) ?: [];

        $nomor = array_filter(array_map(
            fn ($n) => PhoneNumber::normalize($n),
            $pecah
        ));

        return array_values(array_unique($nomor));
    }
}
