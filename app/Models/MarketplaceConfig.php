<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'admin_fee_percent',
        'admin_fee_fixed',
        'is_active',

        'account_receivable_hold_id',
        'account_fee_id',
        'account_wallet_id',
        // Pajak yang dipotong marketplace (PPh final UMKM 0,5%) atas nilai kotor — dipisah dari
        // Beban Admin supaya terlihat. Premi / Program Hemat Biaya Kirim tetap di Beban Admin.
        'account_tax_id',
        'tax_percent',
        // Marketplace memotong pajak (saat selesai) hanya atas pesanan yang MASUK sejak tanggal ini.
        'tax_start_date',
    ];

    public function holdAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_receivable_hold_id');
    }

    public function feeAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_fee_id');
    }

    public function walletAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_wallet_id');
    }

    public function taxAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_tax_id');
    }

    /** Pajak yang dipotong marketplace atas nilai kotor pesanan, dibulatkan ke rupiah. */
    public function pajakDari(float $kotor): float
    {
        return $kotor > 0 ? round($kotor * (float) $this->tax_percent / 100, 0) : 0.0;
    }

    /**
     * Apakah marketplace memotong pajak atas pesanan yang MASUK pada $tanggalPesanan. Patokannya
     * tanggal pesanan dibuat, bukan tanggal selesai: pesanan 30 Sep yang selesai 5 Okt tetap
     * disetor sendiri (pemotongannya sendiri baru terjadi saat pesanan selesai).
     * Tanpa tanggal mulai = selalu (perilaku lama); tanpa tanggal pesanan = hari ini.
     */
    public function pajakBerlakuPada($tanggalPesanan): bool
    {
        if ((float) $this->tax_percent <= 0) {
            return false;
        }

        return $this->pesananKenaPungut($tanggalPesanan);
    }

    /** Pesanan yang masuk pada tanggal ini termasuk yang dipungut (terlepas dari % yang diisi). */
    public function pesananKenaPungut($tanggalPesanan): bool
    {
        if (!$this->tax_start_date) {
            return true;
        }

        $tgl = $tanggalPesanan ? \Carbon\Carbon::parse($tanggalPesanan) : now();

        return $tgl->startOfDay()->gte($this->tax_start_date->copy()->startOfDay());
    }

    /**
     * Tanggal pesanan MASUK di marketplace untuk sebuah faktur: tanggal pesanan Jubelio, lalu
     * tanggal SO, lalu tanggal faktur.
     */
    public static function tanggalPesananFaktur($invoice): ?string
    {
        $soId = $invoice->sales_order_id ?? null;
        $tgl = $soId
            ? (\Illuminate\Support\Facades\DB::table('jubelio_order_links')->where('sales_order_id', $soId)->min('snap_order_date')
                ?? \Illuminate\Support\Facades\DB::table('sales_orders')->where('id', $soId)->value('order_date'))
            : null;

        return $tgl ? (string) $tgl : (isset($invoice->invoice_date) ? (string) $invoice->invoice_date : null);
    }

    /** Pemungut PPh 22 (punya tanggal mulai pungut), terlepas dari % yang sudah diisi atau belum. */
    public function isPemungutPajak(): bool
    {
        return $this->tax_start_date !== null;
    }

    protected $casts = [
        'tax_start_date'    => 'date',
        'tax_percent'       => 'float',
        'admin_fee_percent' => 'float',
        'admin_fee_fixed' => 'float',
        'is_active' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
