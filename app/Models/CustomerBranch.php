<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu alamat kirim milik pelanggan — cabang, gudang, atau outlet.
 *
 * Bukan pelanggan. `customer_id` selalu menunjuk induk, sehingga piutang,
 * termin tempo, saldo DP, dan riwayat pembelian tetap terkumpul di satu tempat
 * betapa pun banyak cabang yang dikirimi. Yang berbeda antar cabang cuma
 * alamat dan orangnya, dan hanya itulah yang disimpan di sini.
 *
 * Nama kolomnya sama persis dengan milik Customer — lihat alasannya di migrasi
 * `create_customer_branches_table`. Konsekuensi yang perlu dijaga: begitu
 * `Customer` menambah kolom alamat baru, tabel ini harus ikut, kalau tidak
 * cabang bayangan dari induk akan kehilangan kolom itu diam-diam.
 */
class CustomerBranch extends Model
{
    protected $fillable = [
        'customer_id',
        'name',
        'pic_name',
        'phone',
        'recipient_phone',
        'shipping_address',
        'district',
        'city',
        'province',
        'postal_code',
        'biteship_area_id',
        'kiriminaja_area_id',
        'jubelio_area_id',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Tujuan pengiriman dari sepasang id — cabang bila sah, kalau tidak induk.
     *
     * Dipakai jalur yang hanya memegang angka, bukan model: cek ongkir, panel
     * alamat, pemesanan resi. Kembaliannya selalu berbentuk CustomerBranch,
     * sama seperti PunyaTujuanKirim::tujuan() pada dokumen.
     *
     * Cabang yang bukan milik `$customerId` diabaikan — bukan dianggap galat.
     * Bentuk itu muncul tiap kali pelanggan diganti di form tanpa memilih ulang
     * cabangnya, dan jawabannya memang "berarti atas nama induk". Yang penting:
     * ongkir tidak boleh pernah dihitung ke alamat perusahaan lain.
     */
    public static function tujuanUntuk($customerId, $branchId): ?self
    {
        if ($branchId) {
            $cabang = static::with('customer')->find($branchId);

            if ($cabang && (! $customerId || (int) $cabang->customer_id === (int) $customerId)) {
                return $cabang->denganAlamatPusat();
            }
        }

        return $customerId ? Customer::find($customerId)?->sebagaiCabang() : null;
    }

    /**
     * Nomor yang DIKABARI untuk pesanan cabang ini. Kosong = ikut nomor pusat.
     *
     * Dua peran nomor yang sama dengan di pusat, dan rantainya selalu menoleh
     * ke atas: tidak pernah ada pesanan yang kehilangan penerima kabar hanya
     * karena kolom cabangnya belum sempat diisi.
     */
    public function nomorNotifikasi(): ?string
    {
        return trim((string) $this->phone) ?: $this->customer?->nomorNotifikasi();
    }

    /**
     * Nomor untuk kurir & label cabang ini.
     *
     * Urutannya: penerima cabang, lalu nomor utama cabang, baru turun ke pusat.
     * Nomor cabang mana pun didahulukan atas nomor pusat — kurir yang kesasar
     * harus menelepon orang yang ada DI LOKASI, bukan purchasing di kota lain.
     */
    public function nomorPengiriman(): ?string
    {
        return trim((string) $this->recipient_phone)
            ?: (trim((string) $this->phone) ?: $this->customer?->nomorPengiriman());
    }

    /**
     * Blok alamat — diwarisi dari pusat SEBAGAI SATU KESATUAN, tidak per kolom.
     * Titik lokasi ikut di blok ini: koordinat cabang ditambah jalan pusat
     * membuat kurir instant & reguler berangkat ke dua tempat berbeda.
     */
    public const KOLOM_ALAMAT = [
        'shipping_address', 'district', 'city', 'province', 'postal_code',
        'biteship_area_id', 'kiriminaja_area_id', 'jubelio_area_id',
        'latitude', 'longitude',
    ];

    /** True pada salinan yang alamatnya dipinjam dari pusat — lihat denganAlamatPusat(). */
    public bool $alamatIkutPusat = false;

    /**
     * Cabang sudah punya alamat sendiri? Titik lokasi saja tidak dihitung:
     * kurir reguler butuh jalan & kode pos, bukan koordinat.
     */
    public function punyaAlamatSendiri(): bool
    {
        foreach (array_diff(self::KOLOM_ALAMAT, ['latitude', 'longitude']) as $kolom) {
            if (trim((string) $this->{$kolom}) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Tujuan kirim cabang ini: dirinya sendiri, atau — bila blok alamatnya kosong
     * seluruhnya — SALINAN dengan blok alamat pusat. Nama & nomor tetap milik cabang,
     * jadi nota tetap atas nama cabang dan kurir tetap menelepon orang cabang.
     *
     * Semua-atau-tidak: cabang yang baru mengisi sebagian alamatnya TIDAK ditambal dari
     * pusat, supaya tidak lahir alamat campuran (jalan cabang + kode pos pusat).
     */
    public function denganAlamatPusat(): self
    {
        if (! $this->exists || $this->punyaAlamatSendiri() || ! $this->customer) {
            return $this;
        }

        $pusat = $this->customer->sebagaiCabang();
        $salinan = clone $this;
        foreach (self::KOLOM_ALAMAT as $kolom) {
            $salinan->setAttribute($kolom, $pusat->getAttribute($kolom));
        }
        $salinan->alamatIkutPusat = true;

        return $salinan;
    }

    /** Salinan berisi alamat pusat tidak boleh tersimpan ke baris cabang. */
    public function save(array $options = [])
    {
        if ($this->alamatIkutPusat) {
            throw new \LogicException('Cabang dengan alamat pinjaman pusat tidak boleh disimpan — ambil ulang barisnya dari basis data.');
        }

        return parent::save($options);
    }

    /**
     * Alamat lengkap, format yang sama persis dengan Customer::fullAddress().
     *
     * Hanya membaca kolom baris ini. Pewarisan alamat pusat untuk cabang yang
     * alamatnya kosong terjadi di denganAlamatPusat(), yang dipanggil resolver
     * tujuan kirim — bukan di sini.
     */
    public function fullAddress(): string
    {
        return collect([
            $this->shipping_address,
            $this->district,
            $this->city,
            $this->province,
            $this->postal_code,
        ])->map(fn ($v) => trim((string) $v))->filter()->implode(', ');
    }
}
