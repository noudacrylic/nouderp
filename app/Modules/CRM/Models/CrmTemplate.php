<?php

namespace App\Modules\CRM\Models;

use App\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Template pesan yang DIPILIH MANUSIA saat chat.
 *
 * Bedanya dengan notifikasi bukan harga, melainkan siapa yang mengirim:
 * notifikasi berangkat sendiri dari kode (dan bunyinya memang harus terkunci di
 * sana), sedangkan yang di sini dipilih admin dari rail kanan.
 *
 * Satu daftar, dua macam, dibedakan oleh `meta_name`:
 *  - NULL  : milik kita sendiri. Gratis, bebas diubah, TAPI hanya sah di dalam
 *            jendela 24 jam. Nomor rekening, alamat, jam buka.
 *  - terisi: sudah/sedang diajukan ke Meta. Satu-satunya yang boleh dipakai
 *            MEMBUKA chat ke nomor yang belum menghubungi kita.
 */
class CrmTemplate extends Model
{
    protected $fillable = [
        'title', 'body', 'category', 'sort_order', 'is_active', 'created_by',
        'meta_name', 'meta_status', 'meta_id', 'meta_error', 'meta_submitted_at', 'meta_variables',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'sort_order'        => 'integer',
        'used_count'        => 'integer',
        'meta_variables'    => 'array',
        'meta_submitted_at' => 'datetime',
    ];

    public const META_PENDING  = 'PENDING';
    public const META_APPROVED = 'APPROVED';
    public const META_REJECTED = 'REJECTED';

    /** Didaftarkan ke Meta (apa pun statusnya)? */
    public function keMeta(): bool
    {
        return (bool) $this->meta_name;
    }

    /**
     * Boleh dipakai MEMBUKA chat ke nomor dingin?
     *
     * Hanya yang sudah APPROVED. Yang masih PENDING ditolak API dengan alasan
     * yang tak terbaca admin — jadi lebih baik ia tak muncul sebagai pilihan
     * sama sekali daripada muncul lalu gagal.
     */
    public function bisaBukaChat(): bool
    {
        return $this->meta_status === self::META_APPROVED;
    }

    /**
     * Jumlah {{n}} di dalam body.
     *
     * Dihitung dari bodynya, bukan disimpan: dua angka untuk satu hal selalu
     * berakhir berbeda, dan yang salah di sini berarti pengajuan ditolak vendor.
     */
    public function jumlahVariabel(): int
    {
        preg_match_all('~\{\{\s*\d+\s*\}\}~', (string) $this->body, $cocok);

        return count(array_unique($cocok[0]));
    }

    public function scopeKeMeta($query)
    {
        return $query->whereNotNull('meta_name');
    }

    public function scopeMilikSendiri($query)
    {
        return $query->whereNull('meta_name');
    }

    /**
     * Isian yang boleh dipakai di dalam body. Sengaja SEDIKIT dan semuanya
     * bisa dijawab tanpa bertanya ke admin — begitu sebuah isian butuh
     * konfirmasi manusia, ia berhenti menghemat waktu dan mulai menyesatkan.
     */
    public const ISIAN = [
        '{nama}'    => 'Nama pelanggan (atau nomornya bila belum dikenal)',
        '{nomor}'   => 'Nomor WhatsApp pelanggan',
        '{pesanan}' => 'Nomor SO terakhir pelanggan ini',
        '{admin}'   => 'Nama admin yang sedang login',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Susun teks siap kirim untuk satu percakapan.
     *
     * Isian yang tak punya jawaban DIKOSONGKAN, bukan dibiarkan sebagai
     * '{pesanan}' — kalimat yang bocor kurung kurawal ke pelanggan jauh lebih
     * memalukan daripada kalimat yang kehilangan satu keterangan.
     */
    public function render(?CrmConversation $percakapan = null, ?string $namaAdmin = null): string
    {
        $pelanggan = $percakapan?->customer;

        return strtr($this->body, [
            '{nama}'    => $pelanggan?->name ?? $percakapan?->display_name ?? $percakapan?->contact_key ?? '',
            '{nomor}'   => $percakapan?->contact_key ?? '',
            '{pesanan}' => $this->pesananTerakhir($pelanggan) ?? '',
            '{admin}'   => $namaAdmin ?? '',
        ]);
    }

    private function pesananTerakhir(?Customer $pelanggan): ?string
    {
        if (! $pelanggan) {
            return null;
        }

        return SalesOrder::where('customer_id', $pelanggan->id)
            ->latest('id')
            ->value('order_number');
    }
}
