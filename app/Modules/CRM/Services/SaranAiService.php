<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Agents\AgenRunner;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Karyawan virtual CS Shopee, mode SARAN: agen `cs-shopee` mengusulkan balasan untuk pesan
 * pembeli Shopee yang belum dibalas manusia; admin memilih Pakai / Abaikan di layar chat.
 *
 * Tidak ada yang dikirim ke pembeli dari kelas ini. Yang dicatat — dipakai utuh, dipakai
 * diubah, diabaikan, beserta teks yang sebenarnya dikirim admin — adalah bahan evaluasi per
 * butir daftar tanya-jawab sebelum butirnya dilepas jadi otomatis (rencana Tahap 3).
 */
class SaranAiService
{
    /** Pembeli sering mengetik beberapa pesan beruntun — tunggu dulu sebelum menyarankan. */
    public const JEDA_DETIK = 30;

    /** Pesan lebih tua dari ini tidak lagi diberi saran (chat malam tetap dapat saran pagi). */
    public const BATAS_JAM = 24;

    public function __construct(private AgenRunner $runner) {}

    public function aktif(): bool
    {
        return (bool) config('crm.agen.saran_aktif', false) && CrmAgent::kode(CrmAgent::CS_SHOPEE) !== null;
    }

    /**
     * Percakapan Shopee yang menunggu saran: pesan masuk terakhir 30 detik – 24 jam lalu,
     * belum dibalas manusia, dan pesan itu belum punya saran.
     *
     * @return Collection<int, CrmConversation>
     */
    public function calon(int $batas = 10): Collection
    {
        return CrmConversation::where('channel', CrmConversation::KANAL_SHOPEE)
            ->whereBetween('last_inbound_at', [now()->subHours(self::BATAS_JAM), now()->subSeconds(self::JEDA_DETIK)])
            ->where(fn ($q) => $q->whereNull('last_outbound_at')->orWhereColumn('last_outbound_at', '<', 'last_inbound_at'))
            ->orderByDesc('last_inbound_at')
            ->limit($batas * 3)
            ->get()
            ->filter(fn (CrmConversation $c) => ($m = $this->pesanTerakhir($c)) && !CrmSaranAi::where('untuk_message_id', $m->id)->exists())
            ->take($batas)
            ->values();
    }

    /** Buat saran untuk pesan masuk terakhir percakapan ini; null bila tak perlu / sudah ada. */
    public function buat(CrmConversation $c): ?CrmSaranAi
    {
        $agen  = CrmAgent::kode(CrmAgent::CS_SHOPEE);
        $pesan = $this->pesanTerakhir($c);
        if (!$agen || !$pesan || CrmSaranAi::where('untuk_message_id', $pesan->id)->exists()) {
            return null;
        }

        // Saran lama yang belum diputus jadi basi: pembeli sudah menulis lagi.
        CrmSaranAi::where('conversation_id', $c->id)->where('status', CrmSaranAi::BARU)
            ->update(['status' => CrmSaranAi::KEDALUWARSA]);

        $hasil = $this->runner->jalankan($agen,
            'Susun saran balasan untuk pesan terakhir pembeli di atas, lalu panggil usulkan_balasan.',
            $c, CrmAgentRun::MODE_SARAN);

        $usul = collect($hasil['jejak'])->firstWhere('alat', 'usulkan_balasan')['input'] ?? null;

        if ($hasil['galat'] || !$usul) {
            Log::warning('[CRM] saran AI gagal', ['conversation' => $c->id, 'galat' => $hasil['galat']]);

            return CrmSaranAi::create([
                'conversation_id' => $c->id, 'untuk_message_id' => $pesan->id, 'agent_run_id' => $hasil['run']?->id,
                'status' => CrmSaranAi::GAGAL, 'catatan' => mb_substr($hasil['galat'] ?: 'Agen tidak mengusulkan balasan.', 0, 255),
            ]);
        }

        $teks   = trim((string) ($usul['teks'] ?? ''));
        $lempar = in_array($usul['lempar_ke'] ?? null, ['admin', 'owner'], true) ? $usul['lempar_ke'] : null;
        $butir  = mb_substr(trim((string) ($usul['butir'] ?? '')), 0, 10) ?: null;

        return CrmSaranAi::create([
            'conversation_id'  => $c->id,
            'untuk_message_id' => $pesan->id,
            'agent_run_id'     => $hasil['run']?->id,
            'butir'            => $butir === '-' ? null : $butir,
            'teks'             => $teks !== '' ? $teks : null,
            'lempar_ke'        => $lempar,
            'alasan'           => mb_substr(trim((string) ($usul['alasan'] ?? '')), 0, 255) ?: null,
            'status'           => $teks === '' && !$lempar ? CrmSaranAi::TAK_PERLU : CrmSaranAi::BARU,
        ]);
    }

    /** Saran yang sedang berlaku untuk ditampilkan di layar chat. */
    public function berlaku(CrmConversation $c): ?CrmSaranAi
    {
        return CrmSaranAi::where('conversation_id', $c->id)->where('status', CrmSaranAi::BARU)->latest('id')->first();
    }

    /**
     * Dipanggil sesudah admin berhasil membalas. $saranId = saran yang di-Pakai (dari kotak
     * ketik); tanpa itu, saran yang masih berlaku dicatat diabaikan — beserta apa yang admin
     * kirim sebagai gantinya, bahan evaluasi paling berharga.
     */
    public function catatBalasan(CrmConversation $c, ?int $saranId, string $teksTerkirim, ?User $u): void
    {
        $saran = $this->berlaku($c);
        if (!$saran) {
            return;
        }

        $dipakai = $saranId && $saranId === $saran->id;
        $saran->update([
            'status'        => $dipakai ? CrmSaranAi::DIPAKAI : CrmSaranAi::DIABAIKAN,
            'teks_terkirim' => trim($teksTerkirim) ?: null,
            'diubah'        => $dipakai ? $this->rapikan($teksTerkirim) !== $this->rapikan((string) $saran->teks) : null,
            'catatan'       => $dipakai ? null : 'Admin membalas tanpa memakai saran.',
            'diputus_oleh'  => $u?->id,
            'diputus_at'    => now(),
        ]);
    }

    public function abaikan(CrmSaranAi $saran, ?User $u, ?string $catatan): void
    {
        if ($saran->status !== CrmSaranAi::BARU) {
            return;
        }
        $saran->update([
            'status' => CrmSaranAi::DIABAIKAN, 'catatan' => mb_substr(trim((string) $catatan), 0, 255) ?: null,
            'diputus_oleh' => $u?->id, 'diputus_at' => now(),
        ]);
    }

    private function pesanTerakhir(CrmConversation $c): ?CrmMessage
    {
        // Balasan otomatis Shopee (sambutan, luar jam) bukan jawaban — lewati.
        $m = CrmMessage::where('conversation_id', $c->id)
            ->where(fn ($q) => $q->where('direction', CrmMessage::MASUK)
                ->orWhereNull('source')->orWhere('source', '!=', CrmMessage::SOURCE_SHOPEE_OTOMATIS))
            ->latest('sent_at')->latest('id')->first();

        return $m && $m->direction === CrmMessage::MASUK ? $m : null;
    }

    private function rapikan(string $t): string
    {
        return preg_replace('/\s+/u', ' ', trim($t));
    }
}
