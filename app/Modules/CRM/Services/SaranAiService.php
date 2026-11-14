<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Agents\AgenPrompt;
use App\Modules\CRM\Agents\AgenRunner;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Karyawan virtual CS Shopee, mode SARAN: agen `cs-shopee` mengusulkan balasan untuk pesan
 * pembeli Shopee yang belum dibalas manusia; admin memilih Pakai / Abaikan di layar chat.
 *
 * Di jam kerja tidak ada yang dikirim ke pembeli dari kelas ini. Yang dicatat — dipakai utuh,
 * dipakai diubah, diabaikan, beserta teks yang sebenarnya dikirim admin — adalah bahan evaluasi.
 *
 * Tahap 3 (keputusan owner 14 Nov 2026): DI LUAR JAM KERJA saran langsung dikirim sendiri bila
 * agen cs-shopee bermode kirim `otomatis` — jawaban apa adanya, lemparan dengan kabar kapan
 * admin aktif. Mode `bayangan` hanya menandai saran yang akan terkirim. Istirahat siang tetap
 * admin. Yang terkirim dicek admin pagi harinya di Periksa Saran AI (filter "Terkirim AI").
 */
class SaranAiService
{
    /** Pembeli sering mengetik beberapa pesan beruntun — tunggu dulu sebelum menyarankan. */
    public const JEDA_DETIK = 30;

    /** Pesan lebih tua dari ini tidak lagi diberi saran (chat malam tetap dapat saran pagi). */
    public const BATAS_JAM = 24;

    private const TUNGGU_LAMPIRAN_MENIT = 5;

    /** Pesan yang lebih tua dari ini tidak dijawab otomatis (mis. penjadwal sempat mati). */
    public const BATAS_OTOMATIS_MENIT = 60;

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
            ->filter(fn (CrmConversation $c) => ($m = $this->pesanTerakhir($c))
                && !CrmSaranAi::where('untuk_message_id', $m->id)->exists()
                && !$this->lampiranMasihDiunduh($m))
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
        $lempar = in_array($usul['lempar_ke'] ?? null, ['admin', 'desain', 'owner'], true) ? $usul['lempar_ke'] : null;
        $butir  = mb_substr(trim((string) ($usul['butir'] ?? '')), 0, 10) ?: null;

        $saran = CrmSaranAi::create([
            'conversation_id'  => $c->id,
            'untuk_message_id' => $pesan->id,
            'agent_run_id'     => $hasil['run']?->id,
            'butir'            => $butir === '-' ? null : $butir,
            'teks'             => $teks !== '' ? $teks : null,
            'lempar_ke'        => $lempar,
            'alasan'           => mb_substr(trim((string) ($usul['alasan'] ?? '')), 0, 255) ?: null,
            'status'           => $teks === '' && !$lempar ? CrmSaranAi::TAK_PERLU : CrmSaranAi::BARU,
        ]);

        try {
            $this->arahkanKePeran($c, $lempar);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $this->kirimOtomatis($c->refresh(), $pesan, $saran, $agen);
        } catch (\Throwable $e) {
            report($e);
        }

        return $saran;
    }

    /**
     * Di luar jam kerja: saran yang berisi balasan dikirim sendiri (mode otomatis) atau
     * ditandai saja (mode bayangan). Dijaga ulang tepat sebelum kirim: pembeli belum menulis
     * lagi, belum ada yang membalas, dan pesannya masih segar.
     */
    private function kirimOtomatis(CrmConversation $c, CrmMessage $pesan, CrmSaranAi $saran, CrmAgent $agen): void
    {
        $mode = $agen->kirim_otomatis ?: CrmAgent::KIRIM_MATI;
        if ($mode === CrmAgent::KIRIM_MATI || $saran->status !== CrmSaranAi::BARU || !AgenPrompt::diLuarJamKerja()) {
            return;
        }

        $teks = $this->teksOtomatis($saran);
        if ($teks === null
            || $pesan->sent_at?->lt(now()->subMinutes(self::BATAS_OTOMATIS_MENIT))
            || $this->pesanTerakhir($c)?->id !== $pesan->id
            || ($c->last_outbound_at && $pesan->sent_at && $c->last_outbound_at->gt($pesan->sent_at))) {
            return;
        }

        if ($mode === CrmAgent::KIRIM_BAYANGAN) {
            $saran->update(['otomatis' => CrmSaranAi::OTO_BAYANGAN]);

            return;
        }

        $hasil = app(CrmReplyService::class)->balas($c, $teks);
        if (!$hasil['success']) {
            Log::warning('[CRM] kirim otomatis saran AI gagal', ['conversation' => $c->id, 'error' => $hasil['error'] ?? null]);
            $saran->update(['otomatis' => CrmSaranAi::OTO_GAGAL, 'catatan' => mb_substr('Kirim otomatis gagal: ' . ($hasil['error'] ?? '?'), 0, 255)]);

            return;
        }

        $hasil['message']?->update(['source' => CrmMessage::SOURCE_AI]);
        $saran->update([
            'status'        => CrmSaranAi::DIPAKAI,
            'otomatis'      => CrmSaranAi::OTO_TERKIRIM,
            'teks_terkirim' => $teks,
            'diubah'        => $this->rapikan($teks) !== $this->rapikan((string) $saran->teks),
            'diputus_at'    => now(),
        ]);

        // Membalas mengosongkan tanda belum dibaca. Chat yang dilempar masih menunggu manusia,
        // jadi tandanya dikembalikan supaya tampil di daftar admin pagi harinya.
        if ($saran->lempar_ke) {
            $c->refresh()->forceFill(['unread_count' => max(1, (int) $c->unread_count)])->save();
        }
    }

    /** Teks yang dikirim otomatis; lemparan tanpa balasan sementara diberi kabar jam admin. */
    private function teksOtomatis(CrmSaranAi $saran): ?string
    {
        $teks = trim((string) $saran->teks);
        if ($teks !== '') {
            return $teks;
        }

        return $saran->lempar_ke
            ? 'Pesan kakak sudah kami terima 🙏 admin aktif lagi ' . AgenPrompt::adminAktifLagi() . ' ya kak, nanti kami bantu cek.'
            : null;
    }

    /**
     * Desain produk yang ada ke Admin Desain, produk custom ke owner, lainnya ke Admin Layanan.
     * Layar chat hanya mengizinkan pemegang membalas, jadi pemegangnya ikut berpindah.
     * Chat yang dipegang orang di luar meja layanan & desain (mis. owner) tidak digeser otomatis.
     */
    private function arahkanKePeran(CrmConversation $c, ?string $lempar): void
    {
        $peran = ['desain' => 'desain', 'admin' => 'layanan', 'owner' => 'owner'][$lempar] ?? null;
        if (!$peran) {
            return;
        }

        $penerimaId = app(MejaKerjaService::class)->pilihPengerja($peran);
        $penerima   = $penerimaId ? User::bisaChatCrm()->find($penerimaId) : null;
        $pemegang   = $c->owner_user_id ? (int) $c->owner_user_id : null;
        if (!$penerima || $pemegang === $penerima->id) {
            return;
        }

        $anggotaMeja = MejaKerjaPeran::whereIn('peran', ['layanan', 'desain'])->pluck('user_id')->map(fn ($id) => (int) $id);
        if ($pemegang && !$anggotaMeja->contains($pemegang)) {
            return;
        }

        $c->forceFill([
            'owner_user_id' => $penerima->id,
            'queue_state'   => $c->labelSaatDioperKe($penerima->id),
            'unread_count'  => max(1, (int) $c->unread_count),
        ])->save();

        app(NotifikasiChatService::class)->dioper($c, $penerima);
    }

    /** Saran yang sedang berlaku untuk ditampilkan di layar chat. */
    public function berlaku(CrmConversation $c): ?CrmSaranAi
    {
        $saran = $this->saranBaru($c);
        if (!$saran) {
            return null;
        }

        // Dibalas dari Seller Centre: pertanyaannya sudah terjawab, sarannya tidak perlu tampil lagi.
        $pesan = CrmMessage::find($saran->untuk_message_id);
        if ($pesan && $c->last_outbound_at && $c->last_outbound_at->gt($pesan->sent_at)) {
            $saran->update(['status' => CrmSaranAi::KEDALUWARSA, 'catatan' => 'Sudah dibalas tanpa lewat saran.']);

            return null;
        }

        return $saran;
    }

    private function saranBaru(CrmConversation $c): ?CrmSaranAi
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
        $saran = $this->saranBaru($c);
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

    /**
     * Lampiran baru diunduh penjadwal tiap menit. Saran ditunda sampai gambarnya ada, dengan
     * batas 5 menit supaya unduhan yang macet tidak menahan sarannya.
     */
    private function lampiranMasihDiunduh(CrmMessage $m): bool
    {
        return $m->sent_at?->gt(now()->subMinutes(self::TUNGGU_LAMPIRAN_MENIT))
            && $m->attachments()->belumTerunduh()->exists();
    }

    private function rapikan(string $t): string
    {
        return preg_replace('/\s+/u', ' ', trim($t));
    }
}
