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
 * Karyawan virtual CS, mode SARAN: agen `cs-shopee` (chat Shopee) dan `cs-whatsapp` (nomor
 * utama WAHA, sejak 16 Nov 2026) mengusulkan balasan untuk pesan yang belum dibalas manusia;
 * admin memilih Pakai / Abaikan di layar chat.
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
    /** AI tidak mengirim sendiri ke chat yang dibalas manusia dalam rentang ini. */
    public const JAM_MANUSIA_MEMEGANG = 2;

    /**
     * Di luar jam kerja AI hanya mundur bila orang membalas SESUDAH toko tutup dan masih dalam
     * 1 jam (keputusan owner 20 Nov 2026): balasan sore tak lagi membuat chat malam tak terjawab.
     */
    public const MENIT_MANUSIA_MEMEGANG_LUAR_JAM = 60;

    /** Pembeli sering mengetik beberapa pesan beruntun — tunggu dulu sebelum menyarankan. */
    public const JEDA_DETIK = 30;

    /** Pesan lebih tua dari ini tidak lagi diberi saran (chat malam tetap dapat saran pagi). */
    public const BATAS_JAM = 24;

    private const TUNGGU_LAMPIRAN_MENIT = 5;

    /** Pesan yang lebih tua dari ini tidak dijawab otomatis (mis. penjadwal sempat mati). */
    public const BATAS_OTOMATIS_MENIT = 60;

    /** Kanal → agen yang menyarankan balasannya. */
    public const AGEN_PER_KANAL = [
        CrmConversation::KANAL_SHOPEE => CrmAgent::CS_SHOPEE,
        CrmConversation::KANAL_CERMIN => CrmAgent::CS_WHATSAPP,
    ];

    /**
     * Sambutan otomatis WhatsApp Business: dikirim aplikasi HP 1–4 detik setelah pesan pertama,
     * tercatat sebagai pesan keluar `whatsapp_app`. Itu bukan jawaban — tanpa pengecualian ini
     * setiap chat baru terbaca sudah dibalas dan sarannya langsung basi.
     */
    public const SAMBUTAN_WA = 'Terima kasih sudah menghubungi Noud Acrylic';

    public function __construct(private AgenRunner $runner) {}

    public function aktif(): bool
    {
        return (bool) config('crm.agen.saran_aktif', false) && $this->kanalAktif() !== [];
    }

    /** @return string[] kanal yang agennya tersedia */
    public function kanalAktif(): array
    {
        return array_keys(array_filter(self::AGEN_PER_KANAL, fn (string $kode) => $this->agenKode($kode) !== null));
    }

    /** Agen untuk percakapan ini; null bila kanalnya tidak diberi saran. */
    public function agenUntuk(CrmConversation $c): ?CrmAgent
    {
        $kode = self::AGEN_PER_KANAL[$c->channel] ?? null;

        return $kode ? $this->agenKode($kode) : null;
    }

    /**
     * CS Shopee sudah berjalan sebelum saklar per agen dipakai di sini, jadi ia hanya butuh ada.
     * Agen lain (CS WhatsApp) wajib aktif — bisa dimatikan dari layar Agen tanpa deploy.
     */
    private function agenKode(string $kode): ?CrmAgent
    {
        $agen = CrmAgent::kode($kode);

        return $agen && ($kode === CrmAgent::CS_SHOPEE || $agen->is_active) ? $agen : null;
    }

    /**
     * Percakapan yang menunggu saran: pesan masuk terakhir 30 detik – 24 jam lalu,
     * belum dibalas manusia, dan pesan itu belum punya saran.
     *
     * WhatsApp (WAHA) tidak mengisi last_inbound_at, dan last_outbound_at-nya ikut terisi sambutan
     * otomatis — jadi di sana yang dipakai last_message_at, dan yang memutuskan sudah dibalas
     * atau belum adalah pesanTerakhir(). Dua kueri terpisah, bukan satu OR bersyarat.
     *
     * @return Collection<int, CrmConversation>
     */
    public function calon(int $batas = 10): Collection
    {
        $rentang = [now()->subHours(self::BATAS_JAM), now()->subSeconds(self::JEDA_DETIK)];
        $kanal   = $this->kanalAktif();

        $shopee = in_array(CrmConversation::KANAL_SHOPEE, $kanal, true)
            ? CrmConversation::where('channel', CrmConversation::KANAL_SHOPEE)
                ->whereBetween('last_inbound_at', $rentang)
                ->where(fn ($q) => $q->whereNull('last_outbound_at')->orWhereColumn('last_outbound_at', '<', 'last_inbound_at'))
                ->orderByDesc('last_inbound_at')->limit($batas * 3)->get()
            : collect();

        $wa = in_array(CrmConversation::KANAL_CERMIN, $kanal, true)
            ? CrmConversation::where('channel', CrmConversation::KANAL_CERMIN)
                ->whereBetween('last_message_at', $rentang)
                ->orderByDesc('last_message_at')->limit($batas * 3)->get()
            : collect();

        return $shopee->concat($wa)
            ->sortByDesc(fn (CrmConversation $c) => ($c->last_inbound_at ?? $c->last_message_at)?->timestamp)
            ->filter(fn (CrmConversation $c) => ($m = $this->pesanTerakhir($c))
                && !CrmSaranAi::where('untuk_message_id', $m->id)->exists()
                && !$this->lampiranMasihDiunduh($m))
            ->take($batas)
            ->values();
    }

    /** Buat saran untuk pesan masuk terakhir percakapan ini; null bila tak perlu / sudah ada. */
    public function buat(CrmConversation $c): ?CrmSaranAi
    {
        $agen  = $this->agenUntuk($c);
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

        // Chat yang sudah dipegang orang tak butuh saran "saya teruskan ke admin…" (keputusan owner
        // 21 Nov 2026): yang membaca saran justru orang yang dituju lemparan itu.
        if ($saran->status === CrmSaranAi::BARU && $saran->lempar_ke && $c->refresh()->owner_user_id) {
            $saran->update(['status' => CrmSaranAi::TAK_PERLU, 'catatan' => 'Dilempar; chat sudah dipegang admin.']);
        }

        return $saran;
    }

    /**
     * Mode otomatis/bayangan: di luar jam kerja saran dikirim sendiri (atau ditandai saja).
     * Mode penuh (keputusan owner 16 Nov 2026): 24 jam — admin hanya menangani yang dilempar.
     * Dijaga ulang tepat sebelum kirim: pembeli belum menulis lagi, belum ada yang membalas,
     * pesannya masih segar, dan tidak ada manusia yang sedang memegang obrolan ini.
     */
    private function kirimOtomatis(CrmConversation $c, CrmMessage $pesan, CrmSaranAi $saran, CrmAgent $agen): void
    {
        $mode     = $agen->kirim_otomatis ?: CrmAgent::KIRIM_MATI;
        $luarJam  = AgenPrompt::diLuarJamKerja();
        if ($mode === CrmAgent::KIRIM_MATI || $saran->status !== CrmSaranAi::BARU
            || (!$luarJam && $mode !== CrmAgent::KIRIM_PENUH)) {
            return;
        }

        $teks = $this->teksOtomatis($saran, $luarJam);
        if ($teks === null
            || $pesan->sent_at?->lt(now()->subMinutes(self::BATAS_OTOMATIS_MENIT))
            || $this->pesanTerakhir($c)?->id !== $pesan->id
            || $this->sudahDibalas($c, $pesan)
            || $this->dipegangManusia($c, $luarJam)) {
            return;
        }

        // Chat yang sudah dipegang admin & pertanyaannya tak bisa dijawab AI: tidak dilempar lagi,
        // cukup info kapan admin aktif — sekali semalam. Yang bisa dijawab tetap dijawab AI.
        // Custom (owner, 22 Nov 2026): pertanyaan AI yang mengumpulkan referensi/tebal/ukuran/jumlah
        // tetap dikirim supaya pagi harinya admin produksi tinggal menghitung; kabar jam ikut sekali.
        $kabarSudah = $luarJam && $saran->lempar_ke && $c->owner_user_id && AgenPrompt::kabarJamSudahDisampaikan($c->id);
        if ($luarJam && $saran->lempar_ke === 'owner' && $c->owner_user_id && str_contains($teks, '?')) {
            if (!$kabarSudah && !AgenPrompt::menyebutJam($teks)) {
                $teks .= "\nadmin produksi aktif lagi " . AgenPrompt::adminAktifLagi() . ' ya kak 🙏';
            }
        } elseif ($luarJam && $saran->lempar_ke && $c->owner_user_id) {
            if ($kabarSudah) {
                $this->tandaiMenungguAdmin($c);

                return;
            }
            $teks = self::teksInfoAdminAktif();
        }

        // Kabar "admin aktif lagi" cukup sekali semalam: lemparan yang isinya hanya kabar itu
        // (teks cadangan atau butir A8) tidak dikirim lagi bila chat ini sudah menerimanya.
        if ($luarJam && $saran->lempar_ke && (trim((string) $saran->teks) === '' || $saran->butir === 'A8')
            && AgenPrompt::kabarJamSudahDisampaikan($c->id)) {
            $this->tandaiMenungguAdmin($c);
            return;
        }

        // Jam kerja: chat yang sudah diteruskan sedang menunggu admin — kalimat penahan tidak diulang.
        if (!$luarJam && $saran->lempar_ke && $this->baruDiteruskan($c)) {
            $this->tandaiMenungguAdmin($c);
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

        // Membalas mengosongkan tanda belum dibaca. Chat yang dilempar masih menunggu manusia:
        // ditandai "Menunggu admin" — tanda yang hanya hilang oleh balasan orang atau chat ditutup.
        // (unread saja tidak cukup: 17 Nov ucapan penutup AI & penyelarasan baca Seller Centre
        // menolkannya, lemparan semalam lenyap dari daftar admin.)
        if ($saran->lempar_ke) {
            $this->tandaiMenungguAdmin($c);
        }
    }

    private function tandaiMenungguAdmin(CrmConversation $c): void
    {
        $c->refresh();
        if ($c->menunggu_admin_at === null) {
            $c->forceFill(['menunggu_admin_at' => now()])->save();
        }
    }

    /** Teks yang dikirim otomatis; lemparan tanpa balasan sementara diberi kalimat penahan. */
    private function teksOtomatis(CrmSaranAi $saran, bool $luarJam = true): ?string
    {
        $teks = trim((string) $saran->teks);
        if ($teks !== '') {
            return $teks;
        }
        if ($saran->lempar_ke && !$luarJam) {
            return 'saya teruskan ke admin yang menangani ya kak, ditunggu sebentar 🙏';
        }

        return $saran->lempar_ke ? self::teksPenahanLuarJam() : null;
    }

    private static function teksPenahanLuarJam(): string
    {
        return 'Pesan kakak sudah kami terima & diteruskan ke admin yang menangani 🙏 admin aktif lagi ' . AgenPrompt::adminAktifLagi() . ' ya kak.';
    }

    private static function teksInfoAdminAktif(): string
    {
        return 'Mohon maaf kak, admin yang menangani sedang offline 🙏 admin aktif lagi ' . AgenPrompt::adminAktifLagi() . ', nanti langsung dibantu ya kak.';
    }

    /**
     * Desain produk yang ada ke Admin Desain, produk custom ke owner, lainnya ke Admin Layanan.
     * Layar chat hanya mengizinkan pemegang membalas, jadi pemegangnya ikut berpindah.
     * Chat yang dipegang orang di luar meja layanan & desain (mis. owner) tidak digeser otomatis.
     */
    private function arahkanKePeran(CrmConversation $c, ?string $lempar): void
    {
        // WhatsApp ikut dioper dengan pola yang sama sejak 21 Nov 2026 (keputusan owner).
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

        // Dibalas dari Seller Centre / HP: pertanyaannya sudah terjawab, sarannya tidak perlu tampil lagi.
        $pesan = CrmMessage::find($saran->untuk_message_id);
        if ($pesan && $this->sudahDibalas($c, $pesan)) {
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

    /**
     * Manusia sedang memegang chat ini — AI mundur, cukup memberi saran. Jam kerja: ada balasan
     * orang dalam 2 jam terakhir. Luar jam: balasan orang sesudah toko tutup, dalam 1 jam terakhir.
     */
    private function dipegangManusia(CrmConversation $c, bool $luarJam = false): bool
    {
        $sejak = $luarJam
            ? now()->subMinutes(self::MENIT_MANUSIA_MEMEGANG_LUAR_JAM)->max(AgenPrompt::awalLuarJam())
            : now()->subHours(self::JAM_MANUSIA_MEMEGANG);

        return CrmMessage::where('conversation_id', $c->id)
            ->where('direction', CrmMessage::KELUAR)
            ->where('sent_at', '>=', $sejak)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', CrmMessage::SOURCE_AI))
            ->where(fn ($q) => self::bukanOtomatis($q))
            ->exists();
    }

    /**
     * Ada balasan sungguhan sesudah pesan ini? Shopee memakai last_outbound_at (balasan otomatis
     * Shopee tidak mengisinya); WhatsApp membaca pesannya, karena sambutan otomatis ikut mengisi.
     */
    private function sudahDibalas(CrmConversation $c, CrmMessage $pesan): bool
    {
        if (!$pesan->sent_at) {
            return false;
        }
        if ($c->shopee()) {
            return $c->last_outbound_at && $c->last_outbound_at->gt($pesan->sent_at);
        }

        return CrmMessage::where('conversation_id', $c->id)
            ->where('direction', CrmMessage::KELUAR)
            ->where('sent_at', '>', $pesan->sent_at)
            ->where(fn ($q) => self::bukanOtomatis($q))
            ->exists();
    }

    /** Pesan keluar yang bukan balasan otomatis: sambutan Shopee & sambutan WhatsApp Business. */
    private static function bukanOtomatis($q): void
    {
        $q->where(fn ($w) => $w->whereNull('source')->orWhere('source', '!=', CrmMessage::SOURCE_SHOPEE_OTOMATIS))
            ->where(fn ($w) => $w->whereNull('source')->orWhere('source', '!=', CrmMessage::SOURCE_WHATSAPP_APP)
                ->orWhereNull('content')->orWhere('content', 'not like', '%' . self::SAMBUTAN_WA . '%'));
    }

    /** AI sudah meneruskan chat ini ke admin dalam 2 jam terakhir. */
    private function baruDiteruskan(CrmConversation $c): bool
    {
        return CrmSaranAi::where('conversation_id', $c->id)
            ->whereNotNull('lempar_ke')
            ->where('otomatis', CrmSaranAi::OTO_TERKIRIM)
            ->where('diputus_at', '>=', now()->subHours(self::JAM_MANUSIA_MEMEGANG))
            ->exists();
    }

    private function pesanTerakhir(CrmConversation $c): ?CrmMessage
    {
        // Balasan otomatis (sambutan Shopee / WhatsApp Business) bukan jawaban — lewati.
        $m = CrmMessage::where('conversation_id', $c->id)
            ->where(fn ($q) => $q->where('direction', CrmMessage::MASUK)->orWhere(fn ($k) => self::bukanOtomatis($k)))
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
