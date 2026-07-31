<?php

namespace App\Models;

use App\Core\Accounting\Account;
use Illuminate\Database\Eloquent\Model;

class MidtransSetting extends Model
{
    protected $fillable = [
        'server_key',
        'client_key',
        'merchant_id',
        'is_production',
        'show_payment_method',
        'pos_qris_enabled',
        'link_expiry_days',
        'qris_expiry_minutes',
        'va_fee',
        'qris_fee_percent',
        'customer_fee_threshold',
        'customer_fee_amount',
        'channel_fees',
        'cash_account_id',
        'fee_account_id',
    ];

    protected $casts = [
        'is_production' => 'boolean',
        'show_payment_method' => 'boolean',
        'pos_qris_enabled' => 'boolean',
        'link_expiry_days' => 'integer',
        'qris_expiry_minutes' => 'integer',
        'va_fee' => 'decimal:2',
        'qris_fee_percent' => 'decimal:3',
        'customer_fee_threshold' => 'decimal:2',
        'customer_fee_amount' => 'decimal:2',
        'channel_fees' => 'array',
    ];

    /** Konfigurasi tarif/subsidi 1 channel (null bila belum diatur → pakai fallback lama). */
    public function channelFee(string $channel): ?array
    {
        $all = $this->channel_fees ?? [];

        return $all[$channel] ?? null;
    }

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /**
     * Server key yang BERLAKU — satu-satunya sumber untuk seluruh aplikasi.
     *
     * Pengaturan → Midtrans menyimpan kunci ke tabel ini, bukan ke .env. Kalau ada
     * bagian aplikasi yang membaca .env sendiri, ia akan memakai kunci lama begitu
     * kunci diganti lewat UI — dan yang paling berbahaya adalah verifikasi tanda
     * tangan webhook: pelanggan membayar, notifikasinya ditolak 403, tidak ada tanda
     * apa pun di layar. Karena itu resolusinya dikumpulkan di sini.
     *
     * .env hanya cadangan untuk instalasi yang belum pernah membuka halaman Pengaturan.
     */
    public static function resolvedServerKey(): string
    {
        return (string) (self::singleton()->server_key ?: config('services.midtrans.server_key'));
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function feeAccount()
    {
        return $this->belongsTo(Account::class, 'fee_account_id');
    }
}
