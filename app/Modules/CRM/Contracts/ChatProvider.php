<?php

namespace App\Modules\CRM\Contracts;

/**
 * Kontrak adapter penyedia chat (api.co.id, dan penggantinya kelak).
 *
 * Alasan adanya lapis ini sama persis dengan ShippingProvider: sudah dua kali
 * penyedia luar mati di tengah jalan (Biteship wajib badan usaha, KiriminAja
 * macet verifikasi). Penyedia chat berpotensi sama — legalitas api.co.id pun
 * masih menggantung saat modul ini ditulis. Karena itu SELURUH modul CRM hanya
 * boleh mengenal antarmuka ini, tak pernah nama vendor.
 *
 * Kesepakatan bentuk kembalian: setiap method mengembalikan array dengan kunci
 * 'success' (bool) dan 'error' (?string). TIDAK ADA method yang melempar
 * exception karena kegagalan jaringan/API — kegagalan kirim notifikasi tidak
 * boleh menggagalkan transaksi penjualan yang memicunya.
 */
interface ChatProvider
{
    /** Kode provider, mis. 'apicoid'. */
    public function key(): string;

    /** Provider aktif & terkonfigurasi (API key terisi). */
    public function isReady(): bool;

    /**
     * Daftar nomor WhatsApp bisnis yang tersambung.
     *
     * @return array{success:bool, numbers:array<array{
     *   id:string, phone_number_id:?string, display:?string, verified_name:?string,
     *   quality_rating:?string, is_primary:bool, waba_id:?string
     * }>, error:?string}
     */
    public function phoneNumbers(): array;

    /**
     * Kirim pesan teks bebas. HANYA sah di dalam jendela 24 jam — pemanggil
     * wajib memeriksa windowStatus() lebih dulu, atau memakai sendTemplate().
     *
     * @param array $payload [
     *   'to' => string (nomor, bentuk apa pun — dinormalkan adapter),
     *   'text' => string,
     *   'reply_to' => ?string (wamid pesan yang dikutip),
     *   'phone_number_id' => ?string (nomor bisnis pengirim; kosong = utama),
     *   'channel' => ?string ('whatsapp' default),
     * ]
     * @return array{success:bool, message_id:?string, customer_id:?string, raw:array, error:?string}
     */
    public function sendText(array $payload): array;

    /**
     * Kirim media (gambar/dokumen/video/audio).
     *
     * @param array $payload [
     *   'to' => string, 'type' => 'image'|'document'|'video'|'audio',
     *   'media' => string (URL publik ATAU media_id hasil unggah),
     *   'caption' => ?string, 'phone_number_id' => ?string, 'channel' => ?string,
     * ]
     * @return array{success:bool, message_id:?string, customer_id:?string, raw:array, error:?string}
     */
    public function sendMedia(array $payload): array;

    /**
     * Kirim template yang sudah disetujui Meta. Ini satu-satunya jalan
     * menghubungi pelanggan di LUAR jendela 24 jam — dan berbayar.
     *
     * @param array $payload [
     *   'to' => string,
     *   'template' => string (nama template di Meta),
     *   'language' => ?string ('id' default),
     *   'body' => string[] (nilai {{1}}, {{2}}, … berurutan),
     *   'url_button_suffix' => ?string (potongan akhir URL tombol dinamis),
     *   'header_media' => ?array{type:'image'|'video'|'document', link:string},
     *   'phone_number_id' => ?string,
     * ]
     * @return array{success:bool, message_id:?string, customer_id:?string, raw:array, error:?string}
     */
    public function sendTemplate(array $payload): array;

    /**
     * Status jendela 24 jam pelanggan.
     *
     * 'is_open' false berarti hanya template yang boleh dikirim. Dipakai layar
     * Inbox untuk menandai thread SEBELUM admin mengetik panjang lebar —
     * tanpa itu admin akan mengetik, ditolak API, lalu kembali membalas dari HP.
     *
     * @return array{success:bool, is_open:bool, expires_at:?string, raw:array, error:?string}
     */
    public function windowStatus(string $identifier): array;
}
