<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Satu versi pengetahuan seorang agen.
 *
 * Berversi, dan versi lama tidak pernah dihapus. Dua alasannya sama-sama praktis:
 * penajaman pengetahuan itu coba-coba, jadi harus bisa mundur satu langkah; dan
 * tiap baris run menunjuk versi yang berlaku saat itu, jadi balasan lama tetap
 * bisa dibaca bersama aturan yang menghasilkannya.
 */
class CrmAgentKnowledge extends Model
{
    protected $table = 'crm_agent_knowledge';

    protected $fillable = [
        'agent_id', 'versi', 'isi', 'catatan', 'sumber_berkas', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'versi'     => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CrmAgent::class, 'agent_id');
    }

    public function penulis(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Simpan versi baru dan jadikan ia yang berlaku.
     *
     * Dibungkus transaksi karena dua langkahnya harus jadi satu: kalau
     * penonaktifan versi lama berhasil tapi penyimpanan versi baru gagal, agen
     * kehilangan seluruh pengetahuannya tanpa ada yang menyadari — dan gejalanya
     * baru muncul sebagai jawaban yang tiba-tiba hambar.
     */
    public static function simpanVersiBaru(
        CrmAgent $agen,
        string $isi,
        ?string $catatan = null,
        ?string $sumberBerkas = null,
        ?int $userId = null
    ): self {
        return DB::transaction(function () use ($agen, $isi, $catatan, $sumberBerkas, $userId) {
            $versi = (int) static::where('agent_id', $agen->id)->max('versi') + 1;

            static::where('agent_id', $agen->id)->update(['is_active' => false]);

            return static::create([
                'agent_id'      => $agen->id,
                'versi'         => $versi,
                'isi'           => $isi,
                'catatan'       => $catatan,
                'sumber_berkas' => $sumberBerkas,
                'is_active'     => true,
                'created_by'    => $userId,
            ]);
        });
    }

    /**
     * Kembalikan versi ini jadi yang berlaku.
     *
     * Penyalaannya lewat kueri, BUKAN $this->save(). Penyebabnya jebakan
     * Eloquent yang tidak terlihat: baris ini dimatikan lewat mass-update di
     * baris sebelumnya, sedangkan salinan di memori masih mengira dirinya
     * aktif. `forceFill(['is_active' => true])` lalu tidak mengubah apa pun,
     * Eloquent menganggap tak ada yang kotor, dan save() diam saja.
     *
     * Akibatnya bukan sekadar tombol yang tak berfungsi: SEMUA versi berakhir
     * mati, dan agen kehilangan seluruh pengetahuannya tanpa satu pun pesan
     * galat. Gejalanya baru muncul sebagai jawaban yang tiba-tiba hambar.
     */
    public function pakai(): void
    {
        DB::transaction(function () {
            static::where('agent_id', $this->agent_id)->update(['is_active' => false]);
            static::whereKey($this->getKey())->update(['is_active' => true]);

            $this->refresh();
        });
    }
}
