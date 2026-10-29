<?php

namespace App\Modules\Marketplace\Shopee\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris mutasi Saldo Penjual Shopee (akun ERP 1104) + hasil pencocokannya dengan jurnal.
 */
class ShopeeWalletTransaction extends Model
{
    public const COCOK   = 'cocok';
    public const SELISIH = 'selisih';
    public const BELUM   = 'belum';

    public const LABEL_COCOK = [
        self::COCOK   => 'Cocok',
        self::SELISIH => 'Selisih',
        self::BELUM   => 'Belum dijurnal',
    ];

    public const LABEL_KATEGORI = [
        'pesanan'             => 'Penghasilan pesanan',
        'penyesuaian_pesanan' => 'Penyesuaian pesanan (retur, komisi, premi)',
        'penarikan'           => 'Penarikan dana',
        'iklan'               => 'Isi ulang iklan / koin',
        'pembayaran'          => 'Pembayaran dengan saldo',
        'kompensasi'          => 'Kompensasi / penggantian',
        'pinjaman'            => 'SPinjam (pinjaman)',
        'penyesuaian'         => 'Penyesuaian lain',
    ];

    protected $fillable = [
        'shop_id', 'sumber', 'kunci', 'transaction_id', 'waktu', 'urutan', 'tipe', 'deskripsi', 'order_sn',
        'jumlah', 'saldo_akhir', 'status', 'kategori', 'cocok', 'erp_jumlah', 'journal_ids', 'cocok_catatan',
        'diimpor_oleh', 'dokumen_type', 'dokumen_id', 'dokumen_catatan',
    ];

    /**
     * Kategori dari transaction_type API (v2.payment.get_wallet_transaction_list).
     */
    public static function kategoriApi(string $tipe, string $deskripsi, ?string $orderSn): string
    {
        $d = mb_strtolower($deskripsi);

        return match (true) {
            $tipe === 'ESCROW_VERIFIED_ADD'           => 'pesanan',
            str_starts_with($tipe, 'WITHDRAWAL')      => 'penarikan',
            str_contains($tipe, 'SPINJAM') || str_contains($tipe, 'LOAN') || str_contains($d, 'spinjam') => 'pinjaman',
            $tipe === 'SPM_DEDUCT' || str_contains($tipe, 'PAID_BY_SELLER_BALANCE')
                                                      => str_contains($d, 'iklan') || str_contains($d, 'koin') ? 'iklan' : 'pembayaran',
            str_contains($tipe, 'COMPENSAT') || str_contains($d, 'kompensasi') => 'kompensasi',
            $orderSn !== null                         => 'penyesuaian_pesanan',
            default                                   => 'penyesuaian',
        };
    }

    protected $casts = [
        'waktu'       => 'datetime',
        'jumlah'      => 'float',
        'saldo_akhir' => 'float',
        'erp_jumlah'  => 'float',
        'journal_ids' => 'array',
    ];

    /**
     * Kategori dari tipe & deskripsi mutasi (bahasa Seller Centre Indonesia; tipe API menyusul).
     */
    public static function kategoriDari(string $tipe, string $deskripsi, ?string $orderSn): string
    {
        $t = mb_strtolower($tipe);
        $d = mb_strtolower($deskripsi);

        return match (true) {
            str_contains($t, 'penghasilan dari pesanan') => 'pesanan',
            str_contains($t, 'penarikan')                => 'penarikan',
            str_contains($t, 'spinjam') || str_contains($d, 'spinjam') => 'pinjaman',
            str_contains($t, 'pembayaran dengan saldo')  => str_contains($d, 'iklan') || str_contains($d, 'koin') ? 'iklan' : 'pembayaran',
            str_contains($d, 'kompensasi') || str_contains($d, 'penggantian dana') || str_contains($d, 'pengembalian biaya') => 'kompensasi',
            $orderSn !== null                            => 'penyesuaian_pesanan',
            default                                      => 'penyesuaian',
        };
    }
}
