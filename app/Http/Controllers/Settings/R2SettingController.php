<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\R2Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pengaturan Cloudflare R2 — Settings → Integrasi → Cloudflare R2.
 * Menyimpan kredensial penyimpanan media etalase (foto/video Produk Store) di DB
 * (singleton). secret_access_key terenkripsi. Lihat StoreMediaService.
 */
class R2SettingController extends Controller
{
    public function edit()
    {
        $setting = R2Setting::singleton();
        return view('erp.settings.r2.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'is_active'         => 'nullable|boolean',
            'access_key_id'     => 'nullable|string|max:255',
            'secret_access_key' => 'nullable|string|max:255',
            'bucket'            => 'nullable|string|max:255',
            'endpoint'          => 'nullable|string|max:255',
            'public_url'        => 'nullable|string|max:255',
            'region'            => 'nullable|string|max:64',
            'use_path_style'    => 'nullable|boolean',
        ]);

        $setting = R2Setting::singleton();
        $setting->fill([
            'is_active'      => (bool) ($data['is_active'] ?? false),
            'access_key_id'  => $data['access_key_id'] ?: null,
            'bucket'         => $data['bucket'] ?: null,
            'endpoint'       => $data['endpoint'] ?: null,
            'public_url'     => $this->normalizeUrl($data['public_url'] ?? null),
            'region'         => $data['region'] ?: 'auto',
            'use_path_style' => (bool) ($data['use_path_style'] ?? false),
        ]);
        // Secret hanya diubah bila diisi (blank = pertahankan yang lama).
        if (!empty($data['secret_access_key'])) {
            $setting->secret_access_key = $data['secret_access_key'];
        }
        $setting->save();

        return redirect()->route('settings.r2.edit')
            ->with('success', 'Pengaturan Cloudflare R2 disimpan.');
    }

    /** Pastikan URL publik punya skema https:// supaya <img src> tidak jadi path relatif. */
    private function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') return null;
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }

    /** Uji koneksi: tulis + baca + hapus file kecil. */
    public function test()
    {
        $setting = R2Setting::current();
        if (!$setting || !$setting->isConfigured()) {
            return back()->with('error', 'Lengkapi & aktifkan kredensial R2 dulu sebelum menguji.');
        }

        try {
            $disk = Storage::build($setting->diskConfig());
            $key = 'store/_healthcheck/' . Str::uuid() . '.txt';
            $disk->put($key, 'ok', 'public');
            $ok = $disk->get($key) === 'ok';
            $disk->delete($key);

            return back()->with($ok ? 'success' : 'error',
                $ok ? 'Koneksi R2 berhasil (tulis/baca/hapus OK).' : 'Koneksi R2 gagal saat verifikasi isi file.');
        } catch (\Throwable $e) {
            $msg = Str::contains($e->getMessage(), ['flysystem', 'aws', 'S3Client', 'class'])
                ? 'Driver S3 belum terpasang. Jalankan: composer require league/flysystem-aws-s3-v3'
                : $e->getMessage();
            return back()->with('error', 'Uji koneksi gagal: ' . $msg);
        }
    }
}
