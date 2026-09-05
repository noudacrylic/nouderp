<?php

namespace App\Models\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Membaca kolom ber-cast `encrypted` tanpa menjatuhkan halaman ketika isinya
 * TIDAK BISA didekripsi.
 *
 * Kapan itu terjadi: dump database server dipakai di lokal (APP_KEY berbeda),
 * atau APP_KEY pernah diganti tanpa mengenkripsi ulang. Nilainya memang hilang
 * — itu tak bisa ditolong — tapi konsekuensinya sekarang jauh melebihi sebabnya:
 * satu kredensial yang tak terbaca membuat SELURUH halaman Integrasi mati
 * dengan "The MAC is invalid", termasuk kartu-kartu yang sama sekali tidak
 * berhubungan dengannya.
 *
 * Jadi: yang tak terbaca dianggap KOSONG (integrasinya tampil "belum aktif",
 * yang memang benar — kredensialnya nyatanya tak terpakai), dicatat di log,
 * dan halamannya tetap terbuka.
 *
 * ⚠️ Sengaja hanya untuk MEMBACA. Menulis di atas nilai yang tak terbaca tetap
 * menimpa isi lama — itu keputusan pemanggil, bukan sesuatu yang boleh terjadi
 * diam-diam di sini.
 */
trait SafeEncryptedReads
{
    public function safeAttr(string $key, $default = null)
    {
        try {
            return $this->getAttribute($key) ?? $default;
        } catch (DecryptException $e) {
            Log::warning('[Setting] kolom terenkripsi tak terbaca (APP_KEY berbeda?)', [
                'model'  => static::class,
                'column' => $key,
            ]);

            return $default;
        }
    }
}
