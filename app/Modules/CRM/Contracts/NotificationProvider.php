<?php

namespace App\Modules\CRM\Contracts;

/**
 * Kontrak jalur NOTIFIKASI keluar — sengaja jauh lebih sempit dari ChatProvider.
 *
 * Kenapa dipisah, bukan menambah method ke ChatProvider: chat dan notifikasi
 * dipisah berdasarkan PERAN, bukan vendor. Chat butuh seluruh permukaan Meta
 * (jendela 24 jam, media, webhook, daftar template) dan tetap di jalur resmi
 * berbayar. Notifikasi cuma butuh satu hal: "kirim kalimat ini ke nomor itu" —
 * dan justru itulah yang volumenya paling besar, sehingga paling mahal kalau
 * dibayar per pesan.
 *
 * Kontrak yang sempit ini yang membuat WAHA (self-host, gratis, tanpa konsep
 * template sama sekali) bisa berdiri sejajar dengan jalur resmi tanpa harus
 * berpura-pura punya windowStatus(), phoneNumbers(), atau daftar template.
 *
 * Kesepakatan bentuk kembalian sama dengan ChatProvider: selalu array dengan
 * 'success' dan 'error', TIDAK PERNAH melempar exception. Gagalnya kabar ke
 * pelanggan tidak boleh menggagalkan transaksi penjualan yang memicunya.
 */
interface NotificationProvider
{
    /** Kode jalur, mis. 'waha' | 'resmi' | 'fake'. */
    public function key(): string;

    /** Jalur terkonfigurasi & siap dipakai. */
    public function isReady(): bool;

    /**
     * Kirim satu notifikasi.
     *
     * Pemanggil menyerahkan NAMA template beserta variabelnya, bukan kalimat
     * jadi — adapter yang memutuskan: jalur resmi mengirim nama template ke
     * Meta, jalur WAHA merangkai teksnya dari bunyi template yang sama
     * (Support\TemplateResmi). Pemanggil tidak boleh tahu bedanya.
     *
     * @param array $payload [
     *   'to'        => string (nomor, bentuk apa pun — dinormalkan adapter),
     *   'template'  => string (nama template, mis. 'pesanan_dikirim'),
     *   'language'  => ?string ('id' default),
     *   'body'      => string[] (nilai {{1}}, {{2}}, … berurutan),
     *   'url_lacak' => ?string (URL penuh halaman lacak; jalur resmi memakai
     *                  potongan akhirnya sebagai isi tombol URL dinamis),
     * ]
     * @return array{success:bool, message_id:?string, tahan:bool, error:?string}
     *
     * 'tahan' = jangan tandai gagal, coba lagi nanti. Bedanya penting: gagal
     * berarti pesan ini tak akan pernah berangkat lagi tanpa campur tangan
     * manusia, sedangkan sesi WhatsApp yang sedang mati itu keadaan sementara
     * dan lumrah — antreannya harus menunggu, bukan hangus.
     */
    public function kirimNotifikasi(array $payload): array;

    /**
     * Kesehatan jalur, untuk pita peringatan di layar & heartbeat.
     *
     * @return array{siap:bool, status:string, keterangan:?string}
     */
    public function statusJalur(): array;
}
