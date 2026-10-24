<?php

namespace App\Modules\KaryawanVirtual\Services;

use App\Models\User;
use App\Modules\KaryawanVirtual\Contracts\BisaDiusulkan;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\Notifications\Services\TelegramNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Usulan skill bermode "Minta persetujuan": diajukan, dikirim ke Telegram,
 * lalu disetujui/ditolak (Telegram atau halaman web).
 *
 * Penjagaan:
 * - Yang berhak memutus = user yang punya akses menu divisi karyawannya
 *   (User::denganAksesMenu — definisi yang sama dengan penerima notifikasi).
 * - Anti dobel klik: status dipindah menunggu → diproses dengan UPDATE bersyarat;
 *   hanya satu klik yang lolos, sisanya diberi tahu "sudah diputus".
 * - Kedaluwarsa: usulan yang tak diputus sampai batasnya tidak bisa disetujui.
 * - Saat disetujui, skill memeriksa ulang syaratnya (BisaDiusulkan::kerjakanUsulan)
 *   — keadaan bisa berubah sejak diusulkan.
 * - Pesan dari template; tidak ada AI di jalur ini.
 */
class UsulanService
{
    public const LEWAT_WEB      = 'web';
    public const LEWAT_TELEGRAM = 'telegram';
    public const LEWAT_SISTEM   = 'sistem';

    public function __construct(
        private KaryawanVirtualService $kv,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * Ajukan usulan hasil satu pemeriksaan. Yang kuncinya masih menunggu tidak
     * diajukan ulang; yang menunggu tapi tak diusulkan lagi berarti keadaannya
     * sudah berubah → dibatalkan.
     *
     * @param  array<int, array{kunci: string, judul: string, alasan: ?string, data: array}>  $usulan
     * @return array<int, array{id: int, baru: bool}> di-key indeks baris hasil
     */
    public function ajukan(string $skillKey, array $usulan): array
    {
        $this->kedaluwarsakan();

        $skill = $this->kv->skill($skillKey);
        if (!$skill instanceof BisaDiusulkan) return [];

        $menunggu = Usulan::menunggu()->where('skill', $skillKey)->get()->keyBy('kunci');
        $hasil    = [];

        foreach ($usulan as $i => $u) {
            if ($lama = $menunggu->get($u['kunci'])) {
                $hasil[$i] = ['id' => $lama->id, 'baru' => false];
                continue;
            }
            $baru = Usulan::create([
                'karyawan'       => $this->kv->pemilik($skillKey),
                'skill'          => $skillKey,
                'kunci'          => mb_substr($u['kunci'], 0, 120),
                'judul'          => mb_substr($u['judul'], 0, 190),
                'alasan'         => $u['alasan'] ?? null,
                'data'           => $u['data'],
                'status'         => Usulan::MENUNGGU,
                'kedaluwarsa_at' => now()->addHours($skill->masaBerlakuJam()),
            ]);
            $this->kirimTelegram($baru);
            $hasil[$i] = ['id' => $baru->id, 'baru' => true];
        }

        $kunciKini = array_column($usulan, 'kunci');
        foreach ($menunggu as $kunci => $lama) {
            if (!in_array($kunci, $kunciKini, true)) {
                $this->tutup($lama, Usulan::MENUNGGU, Usulan::BATAL,
                    'Keadaan berubah: pemeriksaan terbaru tidak lagi mengusulkan ini.');
            }
        }

        return $hasil;
    }

    /**
     * Setujui / tolak satu usulan.
     *
     * @return array{ok: bool, pesan: string}
     */
    public function putuskan(int $id, bool $setuju, User $user, string $lewat): array
    {
        $usulan = Usulan::find($id);
        if (!$usulan) {
            return ['ok' => false, 'pesan' => 'Usulan tidak ditemukan.'];
        }
        if (!$this->bolehMemutus($usulan, $user)) {
            $nama = $this->kv->karyawan($usulan->karyawan)['nama'] ?? $usulan->karyawan;
            return ['ok' => false, 'pesan' => "Anda tidak punya akses ke divisi {$nama}."];
        }
        if ($usulan->status !== Usulan::MENUNGGU) {
            return ['ok' => false, 'pesan' => $this->sudahDiputus($usulan)];
        }
        if ($usulan->kedaluwarsa_at->isPast()) {
            $this->tutup($usulan, Usulan::MENUNGGU, Usulan::KEDALUWARSA, 'Tidak diputus sampai batas waktu.');
            return ['ok' => false, 'pesan' => 'Usulan ini sudah kedaluwarsa.'];
        }

        // Klaim: hanya satu klik yang memindahkan menunggu → diproses.
        $diklaim = Usulan::whereKey($usulan->id)->where('status', Usulan::MENUNGGU)->update([
            'status'        => Usulan::DIPROSES,
            'diputus_oleh'  => $user->id,
            'diputus_at'    => now(),
            'diputus_lewat' => $lewat,
            'updated_at'    => now(),
        ]);
        if ($diklaim !== 1) {
            return ['ok' => false, 'pesan' => $this->sudahDiputus($usulan->fresh())];
        }
        $usulan->refresh();
        $oleh = "{$user->name} (" . ($lewat === self::LEWAT_TELEGRAM ? 'Telegram' : 'web') . ')';

        if (!$setuju) {
            $jurnal = KaryawanVirtualJurnal::create([
                'karyawan'          => $usulan->karyawan,
                'skill'             => $usulan->skill,
                'mode'              => $this->kv->mode($usulan->skill),
                'pemicu'            => KaryawanVirtualService::PEMICU_PERSETUJUAN,
                'keterangan_pemicu' => mb_substr("Usulan #{$usulan->id} ditolak oleh {$oleh}", 0, 190),
                'user_id'           => $user->id,
                'ringkasan'         => mb_substr("Usulan ditolak: {$usulan->judul}", 0, 255),
                'jumlah_tindakan'   => 0,
                'jumlah_diperiksa'  => 1,
                'rincian'           => [[
                    'status' => Skill::DITOLAK, 'judul' => $usulan->judul, 'alasan' => $usulan->alasan,
                    'dokumen' => null, 'tautan' => null, 'usulan_id' => $usulan->id,
                ]],
            ]);
            $this->tutup($usulan, Usulan::DIPROSES, Usulan::DITOLAK, "Ditolak oleh {$oleh}.", ['jurnal_id' => $jurnal->id]);
            return ['ok' => true, 'pesan' => 'Usulan ditolak.'];
        }

        if ($this->kv->mode($usulan->skill) !== Skill::PERSETUJUAN) {
            $this->tutup($usulan, Usulan::DIPROSES, Usulan::BATAL, 'Mode skill sudah bukan "Minta persetujuan".');
            return ['ok' => false, 'pesan' => 'Dibatalkan: mode skill sudah bukan "Minta persetujuan".'];
        }

        $skill = $this->kv->skill($usulan->skill);
        try {
            $hasil = $this->kv->sebagai($usulan->karyawan, fn () => $skill->kerjakanUsulan($usulan->data));
        } catch (\Throwable $e) {
            Log::error('[karyawan-virtual] usulan gagal dikerjakan', ['usulan' => $usulan->id, 'error' => $e->getMessage()]);
            $this->tutup($usulan, Usulan::DIPROSES, Usulan::GAGAL, 'Gagal dikerjakan: ' . $e->getMessage());
            return ['ok' => false, 'pesan' => 'Gagal dikerjakan: ' . $e->getMessage()];
        }

        $catat  = $this->kv->catat($usulan->skill, $hasil, KaryawanVirtualService::PEMICU_PERSETUJUAN,
            "Usulan #{$usulan->id} disetujui oleh {$oleh}", $user->id, Skill::PERSETUJUAN);
        $baris  = $catat['rincian'][0] ?? null;
        $status = match ($baris['status'] ?? null) {
            Skill::DIBUAT => Usulan::DISETUJUI,
            Skill::GAGAL  => Usulan::GAGAL,
            default       => Usulan::BATAL,
        };
        $catatan = $baris['alasan'] ?? 'Tidak ada hasil.';
        if ($status === Usulan::BATAL) {
            $catatan = 'Diperiksa ulang saat disetujui, tidak jadi: ' . $catatan;
        }

        $this->tutup($usulan, Usulan::DIPROSES, $status, "Disetujui oleh {$oleh}. {$catatan}", [
            'dokumen'   => $baris['dokumen'] ?? null,
            'tautan'    => $baris['tautan'] ?? null,
            'jurnal_id' => $catat['jurnal']?->id,
        ]);

        return ['ok' => $status === Usulan::DISETUJUI, 'pesan' => $catatan];
    }

    public function bolehMemutus(Usulan $usulan, User $user): bool
    {
        $cfg = $this->kv->karyawan($usulan->karyawan);
        return $cfg !== null && !$user->is_virtual
            && User::denganAksesMenu($cfg['akses'])->whereKey($user->id)->exists();
    }

    /** Usulan menunggu yang lewat batas → kedaluwarsa. */
    public function kedaluwarsakan(): int
    {
        $n = 0;
        foreach (Usulan::menunggu()->where('kedaluwarsa_at', '<', now())->get() as $u) {
            $n += (int) $this->tutup($u, Usulan::MENUNGGU, Usulan::KEDALUWARSA, 'Tidak diputus sampai batas waktu.');
        }
        return $n;
    }

    public function batalkanSemua(string $skillKey, string $catatan): int
    {
        $n = 0;
        foreach (Usulan::menunggu()->where('skill', $skillKey)->get() as $u) {
            $n += (int) $this->tutup($u, Usulan::MENUNGGU, Usulan::BATAL, $catatan);
        }
        return $n;
    }

    /** Pindahkan status (bersyarat status asal) lalu perbarui pesan Telegram-nya. */
    private function tutup(Usulan $usulan, string $dari, string $ke, string $catatan, array $tambahan = []): bool
    {
        $ubah = array_merge(['status' => $ke, 'catatan_hasil' => $catatan], $tambahan);
        if ($dari === Usulan::MENUNGGU) {
            $ubah += ['diputus_at' => now(), 'diputus_lewat' => self::LEWAT_SISTEM];
        }

        $n = Usulan::whereKey($usulan->id)->where('status', $dari)->update($ubah + ['updated_at' => now()]);
        if ($n !== 1) return false;

        $usulan->refresh();
        $this->perbaruiTelegram($usulan);
        return true;
    }

    private function sudahDiputus(Usulan $usulan): string
    {
        $label = Usulan::LABEL_STATUS[$usulan->status] ?? $usulan->status;
        $oleh  = $usulan->pemutus?->name;
        return "Usulan ini sudah {$label}" . ($oleh ? " oleh {$oleh}" : '') . '.';
    }

    private function kirimTelegram(Usulan $usulan): void
    {
        if (!$this->telegram->enabled()) return;

        $cfg = $this->kv->karyawan($usulan->karyawan);
        $penerima = User::denganAksesMenu($cfg['akses'])
            ->where('is_virtual', false)
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id')->unique();

        $tombol = ['inline_keyboard' => [[
            ['text' => '✅ Setujui', 'callback_data' => "kv:ok:{$usulan->id}"],
            ['text' => '❌ Tolak',   'callback_data' => "kv:no:{$usulan->id}"],
        ]]];

        $terkirim = [];
        foreach ($penerima as $chatId) {
            $mid = $this->telegram->sendAndGetMessageId($chatId, $this->teks($usulan),
                ['reply_markup' => json_encode($tombol)]);
            if ($mid) $terkirim[] = [(string) $chatId, $mid];
        }

        if ($terkirim) $usulan->update(['telegram' => $terkirim]);
    }

    /** Setelah diputus: ganti pesan di semua penerima, tombolnya hilang. */
    private function perbaruiTelegram(Usulan $usulan): void
    {
        $ikon = [
            Usulan::DISETUJUI => '✅', Usulan::DITOLAK => '❌', Usulan::KEDALUWARSA => '⌛',
            Usulan::BATAL => '➖', Usulan::GAGAL => '⚠️',
        ][$usulan->status] ?? 'ℹ️';
        $teks = $this->teks($usulan, false) . "\n\n{$ikon} <b>" . (Usulan::LABEL_STATUS[$usulan->status] ?? $usulan->status)
            . '</b> — ' . e((string) $usulan->catatan_hasil);

        foreach ($usulan->telegram ?? [] as [$chatId, $messageId]) {
            $this->telegram->editMessageText($chatId, (int) $messageId, $teks,
                ['reply_markup' => json_encode(['inline_keyboard' => []])]);
        }
    }

    private function teks(Usulan $usulan, bool $denganBatas = true): string
    {
        $cfg   = $this->kv->karyawan($usulan->karyawan) ?? [];
        $skill = $this->kv->skill($usulan->skill);

        return ($cfg['ikon'] ?? '🤖') . ' <b>' . e($cfg['nama'] ?? $usulan->karyawan) . "</b> minta persetujuan\n\n"
            . '<b>' . e($usulan->judul) . "</b>\n"
            . ($usulan->alasan ? e($usulan->alasan) . "\n" : '')
            . "\nSkill: " . e($skill->label()) . " · usulan #{$usulan->id}"
            . ($denganBatas ? "\nBerlaku sampai " . $usulan->kedaluwarsa_at->format('d/m H:i') : '')
            . "\n<a href=\"" . e(route('karyawan-virtual.show', $usulan->karyawan)) . '">Buka jurnal kerja</a>';
    }
}
