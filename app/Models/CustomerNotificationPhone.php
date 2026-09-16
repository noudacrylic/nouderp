<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nomor tambahan yang ikut dikabari — orang kedua, ketiga, di perusahaan yang sama.
 *
 * Satu pelanggan sering ditangani beberapa orang dengan posisi berbeda:
 * purchasing yang mengurus tagihan, tim lapangan yang menunggu barang. Mereka
 * mengikuti PERUSAHAANNYA, bukan alamat kirimnya — karena itu nomor-nomor ini
 * milik pelanggan, bukan cabang.
 *
 * Tiap nomor di sini menerima kabar yang sama dengan nomor utama. Perlu diingat
 * saat menambahkannya: "Jatuh Tempo" wajib lewat jalur resmi Meta yang dibayar
 * per pesan, jadi tiap nomor tambahan menambah ongkos kirim jenis itu.
 */
class CustomerNotificationPhone extends Model
{
    protected $fillable = [
        'customer_id',
        'label',
        'phone',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
