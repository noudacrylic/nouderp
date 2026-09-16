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
        'wa_opt_out_at',
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
        'wa_opt_out_at' => 'datetime',
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
     * Catat sikap pelanggan terhadap notifikasi WhatsApp.
     *
     * DUA fakta yang berbeda, dan keduanya disimpan:
     *
     *  - `wa_opt_in` + waktu + asalnya = persetujuan EKSPLISIT. Bukti terkuat
     *    bila Meta bertanya, jadi tetap dicatat meski bukan lagi syarat kirim.
     *  - `wa_opt_out_at` = KEBERATAN eksplisit. Inilah yang kini menghentikan
     *    pengiriman (OrderNotificationService::kelayakan).
     *
     * Yang TIDAK ada di antara keduanya — pelanggan yang tak pernah ditanya —
     * tetap dikabari soal pesanannya sendiri. Itu bedanya dengan aturan lama,
     * yang menyamakan "belum pernah ditanya" dengan "menolak" dan akibatnya
     * mendiamkan hampir seluruh daftar pelanggan.
     *
     * Menolak tidak menghapus jejak persetujuan lama: `wa_opt_in_at` terakhir
     * tetap berguna untuk menjelaskan urutan kejadiannya.
     */
    public function catatOptIn(bool $setuju, string $sumber = 'input_manual'): void
    {
        $sudahSama = (bool) $this->wa_opt_in === $setuju
            && ($setuju ? true : $this->wa_opt_out_at !== null);

        if ($sudahSama) {
            return;
        }

        $this->forceFill([
            'wa_opt_in'        => $setuju,
            'wa_opt_in_at'     => $setuju ? now() : $this->wa_opt_in_at,
            'wa_opt_in_source' => $setuju ? $sumber : $this->wa_opt_in_source,
            // Keberatan dicatat dengan waktunya; persetujuan menariknya kembali.
            'wa_opt_out_at'    => $setuju ? null : now(),
        ])->save();
    }

    /**
     * Keberatan yang dicatat ADMIN dari master pelanggan.
     *
     * Sengaja tidak memakai catatOptIn(): melepas centang "jangan kirim" bukan
     * pernyataan persetujuan dari pelanggan, dan menyimpannya sebagai opt-in
     * berarti setiap penyuntingan data pelanggan menambah satu bukti
     * persetujuan yang tidak pernah terjadi. Yang di sini hanya menyalakan atau
     * memadamkan keberatannya; `wa_opt_in` tetap milik persetujuan sungguhan.
     */
    public function catatKeberatan(bool $keberatan): void
    {
        if ($keberatan === ($this->wa_opt_out_at !== null)) {
            return;
        }

        $this->forceFill(['wa_opt_out_at' => $keberatan ? now() : null])->save();
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

    /**
     * Induk ini, dituangkan ke bentuk cabang — TIDAK disimpan ke basis data.
     *
     * Inilah yang membuat seluruh ERP cukup menghadapi satu bentuk tujuan
     * pengiriman. Tanpa ini, lima belas pembaca alamat masing-masing harus
     * menulis "pakai cabang kalau ada, kalau tidak induk" — lima belas salinan
     * aturan yang sama, yang pasti melenceng satu per satu seiring waktu.
     *
     * Alamat jalannya jatuh ke `address` bila `shipping_address` kosong, persis
     * seperti Customer::fullAddress(). Cabang sungguhan sengaja TIDAK punya
     * jatuh-balik itu — lihat CustomerBranch::fullAddress().
     */
    public function sebagaiCabang(): CustomerBranch
    {
        $bayangan = new CustomerBranch([
            'customer_id'        => $this->id,
            'name'               => $this->name,
            'phone'              => $this->phone,
            'recipient_phone'    => $this->recipient_phone,
            'shipping_address'   => $this->shipping_address ?: $this->address,
            'district'           => $this->district,
            'city'               => $this->city,
            'province'           => $this->province,
            'postal_code'        => $this->postal_code,
            'biteship_area_id'   => $this->biteship_area_id,
            'kiriminaja_area_id' => $this->kiriminaja_area_id,
            'jubelio_area_id'    => $this->jubelio_area_id,
            'latitude'           => $this->latitude,
            'longitude'          => $this->longitude,
            'is_active'          => true,
        ]);

        // Supaya rantai nomornya bisa menoleh ke induk tanpa memukul basis data
        // lagi — dan tanpa risiko menoleh ke pelanggan yang keliru.
        $bayangan->setRelation('customer', $this);

        return $bayangan;
    }

    /** Nomor tambahan yang ikut dikabari — lihat CustomerNotificationPhone. */
    public function notificationPhones()
    {
        return $this->hasMany(CustomerNotificationPhone::class);
    }

    /**
     * Nomor yang DIKABARI. Akar dari semua rantai jatuh-balik nomor.
     *
     * Sengaja bukan `recipient_phone ?: phone`. Nomor penerima barang itu milik
     * orang yang menunggu paket di lokasi; kabar pembayaran dan tagihan bukan
     * urusannya, dan mengirimkannya ke sana berarti tagihan purchasing mendarat
     * di tangan orang gudang.
     */
    public function nomorNotifikasi(): ?string
    {
        return trim((string) $this->phone) ?: null;
    }

    /** Nomor yang diberikan ke kurir & dicetak di label. Kosong = ikut nomor utama. */
    public function nomorPengiriman(): ?string
    {
        return trim((string) $this->recipient_phone) ?: $this->nomorNotifikasi();
    }

    /**
     * Semua nomor yang harus menerima satu kabar, tanpa kembar.
     *
     * Nomor utama (milik cabang bila pesanannya untuk cabang, kalau tidak milik
     * pusat) ditambah nomor tambahan perusahaan. Dinormalkan lebih dulu supaya
     * "0899…" dan "62899…" tidak terkirim dua kali ke orang yang sama.
     *
     * @return string[]
     */
    public function semuaNomorNotifikasi(?CustomerBranch $cabang = null): array
    {
        $nomor = [$cabang ? $cabang->nomorNotifikasi() : $this->nomorNotifikasi()];

        foreach ($this->notificationPhones as $tambahan) {
            $nomor[] = $tambahan->phone;
        }

        return collect($nomor)
            ->map(fn ($n) => \App\Modules\CRM\Support\PhoneNumber::normalize($n))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Alamat kirim tambahan milik pelanggan ini — cabang/gudang/outlet.
     *
     * Yang diarsipkan sengaja ikut terbawa: nota lama boleh saja menunjuk
     * cabang yang kini tak dipakai lagi, dan alamatnya harus tetap terbaca.
     * Penyaringan `aktif()` dikerjakan di tempat yang MEMILIH cabang untuk
     * dokumen baru, bukan di sini.
     */
    public function branches()
    {
        return $this->hasMany(CustomerBranch::class)->orderBy('name');
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
