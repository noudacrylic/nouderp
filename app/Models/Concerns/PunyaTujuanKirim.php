<?php

namespace App\Models\Concerns;

use App\Models\CustomerBranch;

/**
 * Dokumen penjualan yang punya tujuan pengiriman — induk atau salah satu cabang.
 *
 * Satu pintu untuk pertanyaan "nota ini ke mana, dan atas nama siapa?".
 * `tujuan()` SELALU mengembalikan CustomerBranch, entah baris cabang sungguhan
 * atau cabang bayangan dari induk, sehingga pemanggilnya tidak pernah perlu
 * membedakan keduanya:
 *
 *     $tujuan = $so->tujuan();
 *     $tujuan->name;  $tujuan->fullAddress();  $tujuan->nomorPengiriman();
 *
 * Yang dijaga di sini bukan kerapian belaka. Aturan "pakai cabang kalau ada"
 * yang disalin ke lima belas pembaca alamat akan melenceng satu per satu, dan
 * melencengnya tidak bersuara: ongkir tetap keluar, resi tetap tercetak, cuma
 * alamatnya milik kota yang salah.
 */
trait PunyaTujuanKirim
{
    public function customerBranch()
    {
        return $this->belongsTo(CustomerBranch::class);
    }

    /**
     * Pelanggan yang memiliki tujuan dokumen ini.
     *
     * Dokumen yang tidak menyimpan `customer_id` sendiri — surat jalan —
     * menimpa method ini dan menurunkannya dari pesanannya.
     */
    protected function pelangganTujuan()
    {
        return $this->customer;
    }

    /**
     * Cabang yang diwarisi dari dokumen induknya, bila kolomnya sendiri kosong.
     *
     * Pagar kedua, bukan pengganti penyalinan saat dokumen dibuat. Surat jalan
     * dan faktur yang lahir SEBELUM fitur cabang ada — atau lewat jalur yang
     * terlewat disambungkan — tetap mencetak alamat cabang yang benar, bukan
     * diam-diam kembali ke alamat pusat.
     */
    protected function cabangWarisan(): ?CustomerBranch
    {
        return null;
    }

    /** Cabang tujuan, atau induk yang dituangkan jadi cabang bayangan. */
    public function tujuan(): ?CustomerBranch
    {
        // Cabang yang alamatnya belum diisi mengirim ke alamat pusat (keputusan 22 Sep
        // 2026) — lihat CustomerBranch::denganAlamatPusat().
        if ($this->customer_branch_id && $this->customerBranch) {
            return $this->customerBranch->denganAlamatPusat();
        }

        if ($warisan = $this->cabangWarisan()) {
            return $warisan->denganAlamatPusat();
        }

        return $this->pelangganTujuan()?->sebagaiCabang();
    }

    /** Nama yang dicetak di nota: cabang bila dipilih, kalau tidak nama induk. */
    public function namaTujuan(): string
    {
        return (string) ($this->tujuan()?->name ?? '');
    }

    /** Alamat yang dicetak di nota & dipakai menghitung ongkir. */
    public function alamatTujuan(): string
    {
        return (string) ($this->tujuan()?->fullAddress() ?? '');
    }
}
