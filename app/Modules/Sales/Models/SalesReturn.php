<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Models\SalesInvoice;

class SalesReturn extends Model
{
    protected $fillable = [
        'return_number',
        'customer_id',
        'invoice_id',
        'sales_order_id',
        'return_date',
        'grand_total',
        'status',
        'stage',
        'return_type',
        'external_return_number',
        'notes',
        // Ke mana uang dikembalikan & berapa — lihat SalesReturnService::hitungUang().
        'refund_target',
        'refund_account_id',
        'refund_customer_id',
        'refund_amount',
        'fee_reversed',
    ];

    /**
     * Ke mana uang retur dikembalikan.
     *
     * Bukan sekadar pilihan akun: tiap tujuan mencerminkan keadaan dana yang berbeda, dan
     * salah memilihnya membuat akun saldo ditahan atau dompet marketplace jadi minus.
     */
    public const REFUND_TARGETS = [
        'hold'   => 'Saldo Ditahan Marketplace (dana belum cair)',
        'wallet' => 'Saldo Penjualan Marketplace (dipotong dari dompet)',
        'bank'   => 'Transfer dari Kas/Bank',
        'credit' => 'Jadi Kredit Pelanggan (tidak ada uang keluar)',
    ];

    /** Tahap penanganan retur — terpisah dari `status` yang mengurus akuntansi. */
    public const STAGES = [
        'baru'     => 'Retur Baru',
        'diproses' => 'Retur Diproses',
        'selesai'  => 'Retur Selesai',
        'batal'    => 'Batal',
    ];

    /**
     * Jenis kasus retur. NULL = belum didefinisikan → retur menunggu di tahap "baru".
     * Jenis menentukan perlakuan barang & uang saat diselesaikan (lihat defaultCondition()).
     */
    public const RETURN_TYPES = [
        'paket_hilang'   => 'Paket Hilang',
        'gagal_kirim'    => 'Gagal Kirim',
        'kembali_semula' => 'Ingin Mengembalikan seperti Semula',
        'tidak_sesuai'   => 'Barang Tidak Sesuai',
        'rusak'          => 'Barang Rusak',
        'lainnya'        => 'Lainnya',
    ];

    /**
     * Kondisi barang retur — menentukan nasib BARANG sekaligus nasib UANG.
     *
     *   utuh          : barang kembali utuh    → masuk persediaan;        dana dikembalikan
     *   perbaikan     : barang kembali rusak   → Gudang Perbaikan;        dana dikembalikan
     *   rusak         : barang kembali tapi tak terpakai → Beban Kerugian Retur
     *   hilang        : barang TIDAK kembali & dana DIKEMBALIKAN → Beban Kerugian Retur
     *   tidak_kembali : barang TIDAK kembali TAPI DANANYA DIGANTI → tidak membalik apa pun
     *
     * DUA KEADAAN "barang tidak kembali" yang gampang tertukar, padahal jurnalnya berlawanan:
     *
     *   - `tidak_kembali` — paket hilang, klaim MENANG, uangnya tetap kita terima. Penjualannya
     *     sah dan tuntas, jadi omzet & HPP dibiarkan persis seperti penjualan normal. Inilah
     *     satu-satunya kondisi yang tidak membalik apa pun.
     *   - `hilang` — paket hilang, tapi dananya dikembalikan ke pembeli (klaim kalah, atau kita
     *     memilih mengganti). Penjualannya batal, jadi modal barangnya bukan lagi HPP sebuah
     *     penjualan melainkan KERUGIAN: direklas ke 6105.
     *
     * Sebelum ada `hilang`, keadaan kedua tidak bisa diungkapkan sama sekali — CS terpaksa
     * memakai `tidak_kembali` dan omzet yang batal tetap tercatat sebagai penjualan.
     * Dipasang PER BARIS supaya satu pesanan bisa sebagian diganti & sebagian tidak.
     */
    public const CONDITION_NO_RETURN = 'tidak_kembali';

    public const CONDITIONS = [
        'good'                   => 'Utuh',
        'repair'                 => 'Perbaikan',
        'damaged'                => 'Tidak Dapat Diperbaiki',
        'hilang'                 => 'Tidak Kembali (dana dikembalikan)',
        self::CONDITION_NO_RETURN => 'Tidak Kembali (dana diganti)',
    ];

    protected $casts = [
        'return_date' => 'date',
        'grand_total' => 'decimal:2',
    ];

    /**
     * Nilai yang benar-benar MEMBALIK penjualan = seluruh baris KECUALI `tidak_kembali`.
     *
     * Baris `tidak_kembali` dananya diganti marketplace, jadi tidak mengurangi omzet. Dipakai
     * jurnal retur maupun rekonsiliasi marketplace agar keduanya memakai angka yang sama.
     */
    public function reversedAmount(): float
    {
        return round((float) $this->items()
            ->where('condition', '!=', self::CONDITION_NO_RETURN)
            ->sum('subtotal'), 2);
    }

    /** Seluruh baris berkondisi `tidak_kembali` → retur ini tidak membalik apa pun. */
    public function skipsReversal(): bool
    {
        return $this->items()->exists()
            && !$this->items()->where('condition', '!=', self::CONDITION_NO_RETURN)->exists();
    }

    /** Label jenis retur untuk tampilan; '—' bila belum didefinisikan. */
    public function returnTypeLabel(): string
    {
        return self::RETURN_TYPES[$this->return_type] ?? '—';
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function items()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function canBeVoided(): bool
    {
        if ($this->status !== 'posted') return false;

        $overpay = \App\Models\CustomerOverpayment::where('reference', $this->return_number)->first();
        if ($overpay) {
            $original = (float) $this->grand_total;
            $remaining = (float) $overpay->amount;
            if ($remaining + 0.0001 < $original) return false;
        }

        return true;
    }
}
