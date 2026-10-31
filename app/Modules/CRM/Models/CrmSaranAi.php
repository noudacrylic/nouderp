<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu saran balasan dari agen CS Shopee untuk satu pesan pembeli (mode saran).
 * Lihat migrasi 2026_11_01_100000 untuk alur & alasannya.
 */
class CrmSaranAi extends Model
{
    public const BARU        = 'baru';
    public const DIPAKAI     = 'dipakai';
    public const DIABAIKAN   = 'diabaikan';
    public const KEDALUWARSA = 'kedaluwarsa';   // pembeli mengirim pesan baru sebelum diputus
    public const TAK_PERLU   = 'tak_perlu';     // ucapan terima kasih, stiker, dll.
    public const GAGAL       = 'gagal';

    protected $table = 'crm_saran_ai';

    protected $fillable = [
        'conversation_id', 'untuk_message_id', 'agent_run_id', 'butir', 'teks', 'lempar_ke', 'alasan',
        'status', 'teks_terkirim', 'diubah', 'catatan', 'diputus_oleh', 'diputus_at',
    ];

    protected $casts = [
        'diubah'     => 'boolean',
        'diputus_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CrmConversation::class, 'conversation_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrmAgentRun::class, 'agent_run_id');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh');
    }
}
