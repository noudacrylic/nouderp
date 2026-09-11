<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'web_order_pin',
        'address',
        'shipping_address',
        'city',
        'province',
        'district',
        'postal_code',
        'recipient_phone',
        'wa_opt_in',
        'wa_opt_in_at',
        'wa_opt_in_source',
        'biteship_area_id',
        'jubelio_area_id',
        'kiriminaja_area_id',
        'latitude',
        'longitude',
        'customer_type',
        'is_marketplace',
        'marketplace_code',
        'is_active',
        'admin_percent',
        'admin_nominal',
        'account_bank_id',
        'account_revenue_id',
        'account_admin_expense_id',
        'account_recon_plus_id',
        'account_recon_minus_id',
    ];

    protected $casts = [
        'wa_opt_in'    => 'boolean',
        'wa_opt_in_at' => 'datetime',
    ];

    protected $appends = [
        'marketplace_hold_name',
        'credit_balance',
        'picker_label',
    ];

    /**
     * Hanya pelanggan yang belum diarsipkan. Dipakai SEMUA kotak cari pelanggan.
     *
     * Sengaja tidak dipasang sebagai global scope: dokumen lama boleh saja milik
     * pelanggan yang kini diarsipkan, dan namanya harus tetap muncul saat dibuka.
     * Yang disaring adalah PENCARIAN (memilih untuk dokumen baru), bukan penyelesaian
     * nama yang sudah terlanjur tersimpan.
     */
    public function scopeAktif($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Catat persetujuan menerima notifikasi WhatsApp.
     *
     * Meta menuntut BUKTI opt-in, bukan sekadar kolom bernilai true — karena itu
     * waktu & asal persetujuan ikut disimpan. Mencabut centang tidak menghapus
     * jejaknya: `wa_opt_in_at` terakhir tetap berguna saat Meta bertanya.
     */
    public function catatOptIn(bool $setuju, string $sumber = 'input_manual'): void
    {
        if ((bool) $this->wa_opt_in === $setuju) {
            return;
        }

        $this->forceFill([
            'wa_opt_in'        => $setuju,
            'wa_opt_in_at'     => $setuju ? now() : $this->wa_opt_in_at,
            'wa_opt_in_source' => $setuju ? $sumber : $this->wa_opt_in_source,
        ])->save();
    }

    /**
     * Nama yang tampil di kotak pilih & daftar hasil cari: nama, lalu No. HP.
     *
     * Dua gunanya. Pertama, membedakan orang yang namanya sama persis — dan itu
     * nyata: ada tiga "Dhita Maharani" di data. Kedua, kehadiran ekor itu sendiri
     * menandai "ini pelanggan yang SUDAH ADA", bukan nama yang baru saja diketik dan
     * belum tersimpan.
     *
     * No. HP dipilih ketimbang kode karena itulah yang dikenali admin ("CUST-17…"
     * tak berarti apa-apa bagi siapa pun). Pelanggan tanpa HP jatuh ke kode, supaya
     * dua gunanya di atas tetap berlaku.
     */
    public function getPickerLabelAttribute(): string
    {
        $ekor = trim((string) $this->phone) ?: trim((string) $this->code);

        return $ekor !== '' ? $this->name . ' · ' . $ekor : (string) $this->name;
    }

    public function marketplaceIntegration()
    {
        return $this->hasOne(MarketplaceIntegration::class);
    }

    public function marketplace()
    {
        return $this->hasOne(MarketplaceConfig::class);
    }

    public function getMarketplaceHoldNameAttribute()
    {
        if (!$this->is_marketplace) return null;
        $config = $this->marketplace()->with('holdAccount')->first();
        return $config?->holdAccount?->name ?? 'Saldo Ditahan Marketplace';
    }

    public function overpayments()
    {
        return $this->hasMany(CustomerOverpayment::class);
    }

    public function getCreditBalanceAttribute()
    {
        return $this->overpayments_sum_amount ?? 0;
    }

    /**
     * Alamat lengkap gaya alamat pengiriman: jalan + kelurahan/kecamatan + kota + provinsi + kode pos.
     * Komposisinya identik dengan kartu Pengiriman di form (CustomerController::shippingPayload):
     * jalan dari `shipping_address` (fallback `address`), lalu district, city, province, postal_code.
     * Dipakai di nota cetak (Penawaran/Pesanan/Faktur) supaya formatnya seragam.
     */
    public function fullAddress(): string
    {
        $street = trim((string) ($this->shipping_address ?: $this->address ?: ''));

        return collect([$street, $this->district, $this->city, $this->province, $this->postal_code])
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->implode(', ');
    }
}
