<?php

namespace App\Modules\SDM\Services;

use App\Models\User;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Notifications\Services\TelegramNotifier;
use App\Modules\Notifications\Services\WebPushNotifier;
use App\Modules\SDM\Models\IzinRequest;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\TugasOwner\TugasOwnerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keputusan pengajuan izin — HANYA owner. Satu pintu untuk halaman SDM, kartu Meja Owner, dan
 * tombol Telegram, supaya ketiganya memberi tahu karyawan dan menutup kartu dengan cara yang sama.
 */
class IzinKeputusanService
{
    public const KUNCI_SKILL = 'owner.pengajuan-izin';

    public function __construct(
        private IzinRequestService $izin,
        private IzinReviewService $review,
        private WebPushNotifier $push,
        private TelegramNotifier $telegram,
        private KaryawanVirtualService $kv,
    ) {}

    public static function kunciKartu(IzinRequest $req): string
    {
        return 'izin:' . $req->id;
    }

    /**
     * @return string pesan untuk owner
     * @throws \RuntimeException belum boleh diputus / bukan owner
     */
    public function putuskan(IzinRequest $req, bool $setuju, User $u, ?string $catatan = null): string
    {
        if (!app(TugasOwnerService::class)->owner($u)) {
            throw new \RuntimeException('Hanya owner yang bisa memutuskan pengajuan izin.');
        }

        // Kunci baris: dua klik (mis. web & Telegram) tidak bisa memutus pengajuan yang sama dua kali.
        DB::transaction(function () use ($req, $setuju, $u, $catatan) {
            $terkunci = IzinRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            $setuju ? $this->izin->approve($terkunci, $u->name) : $this->izin->reject($terkunci, $u->name, $catatan);
        });
        $req->refresh();

        $this->beriTahuKaryawan($req, $setuju, $catatan);
        $this->tutupKartu($req, $u, $setuju ? 'Disetujui' : 'Ditolak');
        $this->perbaruiTelegram($req, ($setuju ? '✅ <b>Disetujui</b>' : '❌ <b>Ditolak</b>') . ' oleh ' . e($u->name));

        return $setuju ? 'Pengajuan disetujui & diterapkan.' : 'Pengajuan ditolak. Karyawan sudah diberi tahu untuk mengajukan ulang.';
    }

    /** Batalkan persetujuan: pengajuan kembali menunggu, jadi kartu & tombolnya dibuat lagi. */
    public function batalkan(IzinRequest $req): void
    {
        if (!app(TugasOwnerService::class)->owner(auth()->user())) {
            throw new \RuntimeException('Hanya owner yang bisa membatalkan persetujuan izin.');
        }
        $this->izin->cancelApproval($req);

        // Kartu yang sudah selesai melepas kuncinya supaya skill membuat kartu baru.
        $kunci = self::kunciKartu($req);
        Task::where('kunci_sumber', $kunci)->whereIn('status', ['done', 'cancelled'])
            ->update(['kunci_sumber' => $kunci . ':batal:' . now()->timestamp]);

        $this->kirimTelegram($req);
    }

    /**
     * Kabari owner lewat Telegram. Tombol Setujui/Tolak hanya saat skillnya Otomatis; selain itu
     * tautan ke halaman tinjau. Gagal mengirim tidak boleh menggagalkan pengajuan karyawan.
     */
    public function kirimTelegram(IzinRequest $req): void
    {
        try {
            if (!$this->telegram->enabled()) return;

            $opsi = $this->kv->mode(self::KUNCI_SKILL) === Skill::OTOMATIS
                ? ['reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '✅ Setujui', 'callback_data' => "iz:ok:{$req->id}"],
                    ['text' => '❌ Tolak',   'callback_data' => "iz:no:{$req->id}"],
                ]]])]
                : [];

            $terkirim = [];
            foreach ($this->telegram->ownerChatIds() as $chatId) {
                if ($mid = $this->telegram->sendAndGetMessageId($chatId, $this->teks($req), $opsi)) {
                    $terkirim[] = [(string) $chatId, $mid];
                }
            }
            $req->update(['telegram' => $terkirim ?: null]);
        } catch (\Throwable $e) {
            Log::warning('Telegram pengajuan izin gagal: ' . $e->getMessage());
        }
    }

    /** @return string[] */
    public function peringatan(IzinRequest $req): array
    {
        return array_column($this->review->build($req)['warnings'] ?? [], 'text');
    }

    private function teks(IzinRequest $req): string
    {
        $peringatan = collect($this->peringatan($req))->map(fn ($p) => "\n⚠️ " . e($p))->implode('');

        return "🔔 <b>Pengajuan izin baru</b>\n"
            . 'Karyawan: ' . e((string) $req->karyawan?->name) . "\n"
            . 'Tipe: ' . e($req->typeLabel()) . "\n"
            . 'Tanggal: ' . $this->tanggal($req) . "\n"
            . 'Alasan: ' . e((string) $req->alasan) . "\n"
            . $peringatan
            . "\n\n👉 <a href=\"" . e(route('sdm.pengajuan-izin.show', $req->id)) . '">Tinjau pengajuan</a>';
    }

    private function tanggal(IzinRequest $req): string
    {
        return $req->tanggal->translatedFormat('d M Y')
            . ($req->tanggal_akhir ? ' s/d ' . $req->tanggal_akhir->translatedFormat('d M Y') : '');
    }

    private function beriTahuKaryawan(IzinRequest $req, bool $setuju, ?string $catatan): void
    {
        $pemohon = User::where('karyawan_id', $req->karyawan_id)->where('role', 'karyawan')->first();
        $opsi = ['url' => route('me.izin'), 'tag' => 'izin-' . $req->id];

        if ($setuju) {
            $this->push->notifyUser($pemohon, '✅ Izin disetujui', "{$req->typeLabel()} ({$this->tanggal($req)}) telah disetujui.", $opsi);
        } else {
            $this->push->notifyUser($pemohon, '❌ Izin ditolak',
                "{$req->typeLabel()} ({$req->tanggal->translatedFormat('d M Y')}) ditolak." . ($catatan ? " Catatan: {$catatan}" : ''), $opsi);
        }
    }

    /** Kartu langsung hilang dari Meja Owner, tanpa menunggu sinkron 2 menit. */
    private function tutupKartu(IzinRequest $req, User $u, string $catatan): void
    {
        Task::where('kunci_sumber', self::kunciKartu($req))->whereIn('status', ['open', 'in_progress', 'pending'])->get()
            ->each(fn (Task $t) => app(MejaKerjaService::class)->ubahStatus($t, 'done', $u, $catatan));
    }

    private function perbaruiTelegram(IzinRequest $req, string $hasil): void
    {
        foreach ($req->telegram ?? [] as [$chatId, $messageId]) {
            try {
                $this->telegram->editMessageText($chatId, (int) $messageId, $this->teks($req) . "\n\n" . $hasil,
                    ['reply_markup' => json_encode(['inline_keyboard' => []])]);
            } catch (\Throwable $e) {
                Log::warning('Telegram pengajuan izin gagal diperbarui: ' . $e->getMessage());
            }
        }
    }
}
