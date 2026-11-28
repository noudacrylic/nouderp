<?php

namespace App\Modules\Analysis\CustomPrice;

/**
 * Resep satu jenis produk custom: menerjemahkan ukuran + pilihan customer menjadi daftar panel,
 * aksesoris, dan panjang sambungan lem.
 *
 * Resep TIDAK menghitung harga maupun waktu. Rumus itu satu untuk semua jenis produk dan tinggal
 * di CustomPriceService — supaya Tempat Obat, Box Charger, dan seterusnya cukup menambah resep,
 * tanpa ada rumus kedua yang bisa berbeda pendapat dengan yang pertama.
 */
interface Recipe
{
    public function code(): string;

    public function label(): string;

    /** Blade berisi field input resep ini (dipakai layar Hitung dan form sampel). */
    public function formView(): string;

    /**
     * Parameter yang bisa diubah di Pengaturan.
     *
     * @return array<string,array{label:string,default:float,unit:string}>
     */
    public function params(): array;

    /**
     * Bagian yang bahannya boleh dibedakan dari bahan utama.
     *
     * @return array<string,string> role => label
     */
    public function materialRoles(): array;

    /** Rapikan input mentah (request) jadi angka & pilihan yang pasti. */
    public function normalize(array $raw): array;

    /** @return string[] pesan kesalahan; kosong = input sah */
    public function validate(array $input): array;

    /**
     * @return array{
     *   panels: array<int,array{bagian:string,role:string,p:float,l:float,qty:float}>,
     *   accessories: array<int,array{name:string,qty:float,unit_price:?float,product_id:?int}>,
     *   lem_cm: float,
     *   komponen: float,
     *   accessory_count: float,
     *   outer: array{p:float,l:float,t:float},
     *   description: string,
     * }
     *
     * `komponen` = jumlah potongan yang DILEM (tutup lepas tidak ikut). Tiap komponen butuh
     * waktu persiapan memposisikan sebelum dilem — suku tetap di rumus rakit.
     */
    public function build(array $input, array $params): array;

    /**
     * SKU produk Ready yang ukurannya sama dengan input — untuk cek silang "kalau ukuran ini
     * ready, HPP-nya berapa". Kosong kalau resep ini tidak punya padanan ready.
     *
     * @return string[]
     */
    public function readySkus(array $input): array;
}
