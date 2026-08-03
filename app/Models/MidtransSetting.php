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
        'active_channels',
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
        'active_channels' => 'array',
    ];

    /**
     * Metode yang tidak butuh pengajuan terpisah ke Midtrans — aman jadi bawaan bila
     * pengaturannya belum pernah disimpan (instalasi lama, atau setelah migrasi).
     */
    public const DEFAULT_ACTIVE_CHANNELS = ['qris', 'va', 'ewallet'];

    /** Konfigurasi tarif/subsidi 1 channel (null bila belum diatur → pakai fallback lama). */
    public function channelFee(string $channel): ?array
    {
        $all = $this->channel_fees ?? [];

        return $all[$channel] ?? null;
    }

    /**
     * Metode bayar yang BOLEH dipilih pembeli.
     *
     * Beda dengan channel_fees yang selalu memuat SEMUA metode (tarifnya tetap
     * diperlukan untuk jurnal bila suatu saat ada transaksi lewat metode itu):
     * daftar ini menentukan apa yang tampil di halaman /pay. Metode yang belum
     * disetujui Midtrans tidak boleh ditawarkan — pembeli akan mentok di Snap.
     *
     * @return string[]
     */
    public function activeChannels(): array
    {
        $stored = array_values(array_filter(
            (array) ($this->active_channels ?? []),
            fn ($ch) => is_string($ch) && $ch !== ''
        ));

        return $stored ?: self::DEFAULT_ACTIVE_CHANNELS;
    }

    public function channelActive(string $channel): bool
    {
        return in_array($channel, $this->activeChannels(), true);
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

    /** Client key yang BERLAKU — pasangan resolvedServerKey(), .env hanya cadangan. */
    public static function resolvedClientKey(): string
    {
        return (string) (self::singleton()->client_key ?: config('services.midtrans.client_key'));
    }

    /**
     * Mode production yang BERLAKU — kolom ini saja, TANPA cadangan .env.
     *
     * MidtransService memilih endpoint (api.midtrans vs api.sandbox.midtrans) dari
     * kolom ini, jadi sisi browser yang memuat snap.js WAJIB memakai sumber yang sama.
     * Kalau tidak: token diterbitkan server production lalu dimuat snap.js sandbox,
     * popup gagal terbuka dan kasir tidak tahu kenapa.
     */
    public static function resolvedIsProduction(): bool
    {
        return (bool) self::singleton()->is_production;
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
