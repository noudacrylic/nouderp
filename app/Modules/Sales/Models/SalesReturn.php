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
        // Baris jurnal DANA yang diketik sendiri; NULL = pakai hitungan sistem.
        'journal_override',
        // Piutang faktur yang dihapus retur ini, dan jurnal pencairan (Saldo Ditahan →
        // Saldo Penjualan) yang dipicunya — keduanya dibatalkan saat retur di-void.
        'ar_credited',
        'settlement_journal_id',
        'settlement_ar_applied',
        // Jurnal tiga blok (lihat SalesReturnService::jurnalBawaan): Pembalikan & Penyelesaian
        // yang boleh diubah admin. Blok HPP tak pernah disimpan — selalu dari kondisi barang.
        'appeal_result',
        'return_case',
        'journal_reversal',
        'journal_settlement',
        'reversed_amount',
        'settlement_fee',
    ];

    /**
     * Kasus di bawah tiap jenis retur — pilihan "Kasus" di form, sama dengan daftar Panduan
     * Jurnal. Kodenya dipakai bersama form & panduan. Dua kasus panduan sengaja BUKAN pilihan
     * karena ditentukan data: K5 (dana pesanan sudah cair) & K7 (pelanggan non-marketplace).
     */
    public const CASES = [
        'paket_hilang' => [
            'PH1' => 'Klaim menang — dana diganti ke kita',
            'PH2' => 'Klaim ditolak — dana kembali ke pembeli',
        ],
        'gagal_kirim' => [
            'GK1' => 'Kalah / tidak banding — dana kembali ke pembeli',
            'GK2' => 'Banding menang — dana diganti ke kita',
        ],
        'diajukan_konsumen' => [
            'K1' => 'Barang kembali, dana penuh ke pembeli',
            'K2' => 'Sebagian barang kembali, dana sebagian ke pembeli',
            'K3' => 'Barang tidak dikirim balik, dana penuh ke pembeli',
            'K4' => 'Barang tetap di pembeli, dana sebagian ke pembeli',
            'K6' => 'Banding menang — dana tetap ke kita',
        ],
    ];

    /** Hasil banding yang tersirat dari kasus — dipakai SalesReturnService::jurnalBawaan. */
    public const CASE_APPEAL = ['PH2' => 'kalah', 'GK2' => 'menang', 'K6' => 'menang'];

    public function caseLabel(): ?string
    {
        return self::CASES[$this->return_type][$this->return_case] ?? null;
    }

    /** Hasil banding ke marketplace. NULL = tidak dibanding / belum ada hasil. */
    public const APPEAL_RESULTS = [
        'menang' => 'Menang — dana tetap milik kita',
        'kalah'  => 'Kalah — dana dikembalikan ke pembeli',
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

    /**
     * Tahap penanganan retur — terpisah dari `status` yang mengurus akuntansi.
     *
     * DUA tahap yang menuntut pekerjaan, bukan tiga. Tahap lama `diproses` dihapus
     * karena ia tak pernah menjawab pertanyaan apa pun: "baru" dan "diproses"
     * sama-sama berarti retur yang belum selesai, dan memisahkannya cuma memaksa
     * CS menebak sedang di kotak mana sebuah kasus duduk. Yang benar-benar beda
     * nasibnya adalah retur yang sedang DISENGKETAKAN ke marketplace — itu yang
     * kini punya tempat sendiri.
     *
     * `selesai` & `batal` bukan tab: keduanya keadaan akhir. Retur yang selesai
     * pindah ke tab "Selesai" di Pemrosesan Pesanan bersama pesanannya.
     */
    public const STAGES = [
        'baru'    => 'Retur Baru',
        'banding' => 'Banding',
        'selesai' => 'Retur Selesai',
        'batal'   => 'Batal',
    ];

    /** Tahap yang masih menuntut pekerjaan — dua tab di Pemrosesan Pesanan. */
    public const STAGES_AKTIF = ['baru', 'banding'];

    /*
     * Jenis kasus retur. NULL = belum didefinisikan → retur menunggu di tahap "baru".
     *
     * TIGA jenis, dibedakan oleh DUA pertanyaan yang benar-benar mengubah nasib kasus:
     * barangnya kembali atau tidak, dan uangnya jadi milik kita atau tidak. Enam jenis
     * lama ("Barang Tidak Sesuai", "Barang Rusak", "Ingin Mengembalikan seperti Semula",
     * "Lainnya") tidak pernah menjawab keduanya — semuanya sama-sama berarti pembeli
     * mengajukan retur, dan alasannya baru diketahui setelah paketnya dibuka. Alasan
     * rinci tempatnya di Catatan Penanganan, bukan jadi cabang alur.
     */
    public const RETURN_TYPES = [
        // Paket tak pernah sampai & tak pernah kembali; marketplace mengganti penuh.
        // Uangnya sah jadi milik kita → seluruh baris otomatis `tidak_kembali`.
        'paket_hilang'       => 'Paket Hilang',
        // Paket kembali ke kita TANPA penggantian uang: penjualannya batal seutuhnya.
        // Barangnya sering sudah rusak di jalan — kondisinya WAJIB dicek saat paket
        // datang, dan kalau rusak kasusnya pantas dibawa ke Banding.
        'gagal_kirim'        => 'Gagal Kirim',
        // Pembeli yang mengajukan. Hasilnya bermacam-macam — barang bisa utuh, rusak,
        // atau hanya sebagian dana yang dikembalikan → tak ada yang boleh ditebak.
        'diajukan_konsumen'  => 'Diajukan Konsumen',
    ];

    /**
     * Kondisi barang retur — HANYA menentukan nasib BARANG (jurnal HPP & stok). Keputusan 30 Sep
     * 2026: nasib UANG (penjualan dibalik atau tidak) dipisah sepenuhnya ke Jenis Retur + Hasil
     * Banding + Nilai dibalik (lihat SalesReturnService::jurnalBawaan). Dulu kondisi memikul
     * keduanya ("Tidak Kembali (dana diganti)" vs "(dana dikembalikan)" vs "Tetap di Pembeli")
     * dan admin gampang salah pilih.
     *
     *   good          : kembali utuh              → Dr Persediaan / Cr HPP, stok masuk
     *   repair        : kembali perlu perbaikan   → Dr Persediaan Perbaikan / Cr HPP, stok ke Gudang Perbaikan
     *   damaged       : kembali tak terpakai      → Dr Beban Kerugian Retur / Cr HPP
     *   tidak_kembali : barang tak sampai ke kita → Dr Beban Kerugian Retur / Cr HPP (= rusak)
     *
     * `hilang` & `tetap` adalah kondisi LAMA (hanya ada di dokumen lama) — diperlakukan sama
     * dengan `tidak_kembali` dan tak lagi ditawarkan di form.
     */
    public const CONDITION_NO_RETURN = 'tidak_kembali';

    public const CONDITIONS = [
        'good'                    => 'Utuh',
        'repair'                  => 'Perbaikan',
        'damaged'                 => 'Rusak',
        self::CONDITION_NO_RETURN => 'Tidak Kembali',
    ];

    /** Kondisi lama yang hanya dibaca dari dokumen lama → diperlakukan sebagai `tidak_kembali`. */
    public const CONDITIONS_LAMA = ['hilang', 'tetap'];

    protected $casts = [
        'return_date'      => 'date',
        'grand_total'      => 'decimal:2',
        'journal_override' => 'array',
        'journal_reversal'   => 'array',
        'journal_settlement' => 'array',
        'reversed_amount'    => 'decimal:2',
    ];

    /**
     * Nilai yang benar-benar MEMBALIK penjualan = seluruh baris KECUALI `tidak_kembali`.
     *
     * Baris `tidak_kembali` dananya diganti marketplace, jadi tidak mengurangi omzet. Dipakai
     * jurnal retur maupun rekonsiliasi marketplace agar keduanya memakai angka yang sama.
     */
    public function reversedAmount(): float
    {
        // Retur jurnal tiga blok mencatat angkanya sendiri — nilai pembalikan bisa hasil
        // negosiasi (refund sebagian) atau nol (banding menang) apa pun kondisi barangnya.
        if ($this->reversed_amount !== null) {
            return round((float) $this->reversed_amount, 2);
        }

        return round((float) $this->items()
            ->where('condition', '!=', self::CONDITION_NO_RETURN)
            ->sum('subtotal'), 2);
    }

    /** Seluruh baris berkondisi `tidak_kembali` → retur ini tidak membalik apa pun. */
    public function skipsReversal(): bool
    {
        if ($this->reversed_amount !== null) {
            return (float) $this->reversed_amount < 0.005;
        }

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
