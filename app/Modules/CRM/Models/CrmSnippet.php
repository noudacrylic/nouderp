<?php

namespace App\Modules\CRM\Models;

use App\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Potongan balasan siap pakai ("template teks") — milik kita, gratis, hanya
 * sah di DALAM jendela 24 jam. Bukan template Meta; lihat komentar migrasinya.
 */
class CrmSnippet extends Model
{
    protected $fillable = ['title', 'body', 'category', 'sort_order', 'is_active', 'created_by'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'used_count' => 'integer',
    ];

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
