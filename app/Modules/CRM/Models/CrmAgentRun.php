<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kali agen dijalankan — di layar uji maupun (kelak) untuk pelanggan.
 *
 * Ini bukan log untuk kerapian. Ia menjawab dua pertanyaan yang tidak bisa
 * dijawab dari mana pun lagi:
 *
 *  1. "Berapa sebenarnya biayanya?" — token & rupiah tiap run, jadi setelah
 *     sepekan memakai layar uji angkanya terukur, bukan ditebak.
 *  2. "Kenapa agen menjawab begitu?" — versi pengetahuan yang berlaku saat itu
 *     plus jejak tool yang dipanggil. Tanpa jejak, "agen menjawab salah" dan
 *     "agen menerima data salah" terlihat sama persis, padahal obatnya berbeda.
 */
class CrmAgentRun extends Model
{
    public const MODE_UJI      = 'uji';
    public const MODE_LANGSUNG = 'langsung';

    public const STATUS_SUKSES   = 'sukses';
    public const STATUS_GAGAL    = 'gagal';
    public const STATUS_DILEMPAR = 'dilempar';   // agen menyerahkan ke manusia

    protected $fillable = [
        'agent_id', 'conversation_id', 'knowledge_id', 'mode', 'status',
        'masukan', 'keluaran', 'jejak',
        'token_masuk', 'token_cache', 'token_keluar',
        'biaya_rp', 'durasi_ms', 'galat', 'created_by',
    ];

    protected $casts = [
        'jejak'        => 'array',
        'token_masuk'  => 'integer',
        'token_cache'  => 'integer',
        'token_keluar' => 'integer',
        'biaya_rp'     => 'decimal:2',
        'durasi_ms'    => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CrmAgent::class, 'agent_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CrmConversation::class, 'conversation_id');
    }

    public function pengetahuan(): BelongsTo
    {
        return $this->belongsTo(CrmAgentKnowledge::class, 'knowledge_id');
    }

    public function penjalan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Ubah pemakaian token jadi rupiah, memakai tarif model YANG DIPAKAI.
     *
     * Model yang tarifnya belum terdaftar menghasilkan 0, bukan tebakan. Angka
     * karangan di kolom biaya lebih buruk daripada kolom kosong: ia terbaca
     * seperti fakta dan dipakai mengambil keputusan.
     */
    public static function hitungBiaya(string $model, int $masuk, int $cache, int $keluar): float
    {
        $tarif = (array) config('crm.agen.tarif.' . $model, []);

        if (! $tarif) {
            return 0.0;
        }

        $usd = ($masuk / 1_000_000) * (float) ($tarif['masuk'] ?? 0)
             + ($cache / 1_000_000) * (float) ($tarif['cache'] ?? 0)
             + ($keluar / 1_000_000) * (float) ($tarif['keluar'] ?? 0);

        return round($usd * (float) config('crm.agen.kurs_usd', 16400), 2);
    }
}
