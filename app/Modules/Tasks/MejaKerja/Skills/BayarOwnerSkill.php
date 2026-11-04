<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Models\User;
use App\Modules\Purchasing\Models\PengajuanBayarOwner;
use App\Modules\Purchasing\Services\PembayaranOwnerService;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\PunyaJawaban;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Pembelian yang diserahkan admin → kartu Bayar di Meja Owner. "Bayar tempo" memindahkannya ke
 * Pending sebagai daftar hutang; kartu mengikuti sisa tagihan dan selesai sendiri saat lunas.
 */
class BayarOwnerSkill implements SkillOwner, PunyaJawaban
{
    use JadiSkillManajer;

    public function __construct(private PembayaranOwnerService $bayar) {}

    public function key(): string { return 'bayar-owner'; }

    public function label(): string { return 'Pembayaran pembelian'; }

    public function deskripsi(): string
    {
        return 'Pembelian yang diserahkan admin lewat tombol Bayar owner → kartu Bayar di Meja Owner; tempo menjadi daftar hutang di Pending sampai lunas.';
    }

    /** Sudah diserahkan manusia dan ditunggu: nonaktif/bayangan sama dengan tagihan hilang. */
    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 7; }

    public function slaMenitBawaan(): int { return 1440; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'tagihan'; }

    private function pengajuan(Task $t): ?PengajuanBayarOwner
    {
        return PengajuanBayarOwner::with(['purchaseOrder.supplier', 'purchaseInvoice.supplier', 'pengaju:id,name'])->find($t->taskable_id);
    }

    private function judul(PengajuanBayarOwner $p): string
    {
        return $this->bayar->nomor($p) . ' ' . ($this->bayar->supplier($p)?->name ?? '-') . ', sisa Rp' . number_format($this->bayar->sisa($p), 0, ',', '.');
    }

    private function keteranganKartu(PengajuanBayarOwner $p): string
    {
        $s = $this->bayar->supplier($p);
        $rekening = $s?->bank_account_no ? "\nRekening: " . trim("{$s->bank_name} {$s->bank_account_no} a.n. {$s->bank_account_name}") : '';

        return 'Diajukan oleh ' . ($p->pengaju?->name ?? 'data lama') . $rekening;
    }

    public function pindai(Carbon $sejak): iterable
    {
        $belum = PengajuanBayarOwner::with(['purchaseOrder.supplier', 'purchaseInvoice.supplier', 'pengaju:id,name'])
            ->whereNotIn('id', Task::where('skill', $this->key())->select('taskable_id'))->orderBy('id')->get();

        foreach ($belum as $p) {
            if ($this->bayar->batal($p) || $this->bayar->sisa($p) <= 0.0001) continue;

            $tempo = $this->bayar->jatuhTempo($p);
            yield [
                'kunci'         => "bayar-owner:{$p->id}",
                'judul'         => $this->judul($p),
                'deskripsi'     => $this->keteranganKartu($p),
                'taskable_type' => PengajuanBayarOwner::class,
                'taskable_id'   => $p->id,
                'dibuat_at'     => $p->created_at,
                'tenggat_at'    => $tempo ? Carbon::parse($tempo)->endOfDay() : null,
                'meta'          => ['jatuh_tempo' => $tempo, 'tempo' => $p->tempo],
            ];
        }
    }

    public function setelahDibuat(Task $task, array $kerja): void
    {
        if ($kerja['meta']['tempo'] ?? false) $this->jadikanHutang($task, null);
    }

    public function jadikanHutang(Task $task, ?User $u): void
    {
        $tempo = $task->meta['jatuh_tempo'] ?? null;
        $task->tenggat_at = $tempo ? Carbon::parse($tempo)->endOfDay() : null;
        $task->pending_alasan = $tempo ? 'Jatuh tempo ' . Carbon::parse($tempo)->format('d/m/Y') : 'Tanpa jatuh tempo';
        app(MejaKerjaService::class)->ubahStatus($task, 'pending', $u, 'Masuk daftar hutang');
    }

    public function keadaan(Task $task): ?array
    {
        $p = $this->pengajuan($task);
        if (!$p || $this->bayar->batal($p)) return ['cancelled', 'Dokumen pembelian dibatalkan.'];

        return $this->bayar->sisa($p) <= 0.0001 ? ['done', 'Lunas.'] : null;
    }

    /** Judul & jatuh tempo mengikuti tagihan: cicilan dari mana pun, atau faktur dari PO baru diposting. */
    public function perbarui(Task $task): ?array
    {
        $p = $this->pengajuan($task);
        if (!$p) return null;

        $judul = $this->judul($p);
        $tempo = $this->bayar->jatuhTempo($p);
        if ($judul === $task->title && $tempo === ($task->meta['jatuh_tempo'] ?? null)) return null;

        return [
            'title'      => $judul,
            'meta'       => array_merge($task->meta ?? [], ['jatuh_tempo' => $tempo]),
            'tenggat_at' => $tempo ? Carbon::parse($tempo)->endOfDay() : null,
            'status'     => null,
            'catatan'    => "Tagihan diperbarui: $judul",
        ];
    }

    public function aksi(Task $task): array
    {
        $p = $this->pengajuan($task);
        if (!$p) return [];

        return [$p->purchase_invoice_id
            ? ['label' => 'Lihat faktur', 'url' => route('purchasing.invoices.show', $p->purchase_invoice_id)]
            : ['label' => 'Lihat PO', 'url' => route('purchasing.orders.show', $p->purchase_order_id)]];
    }

    public function selesaiManual(): bool { return false; }

    /** Pending di sini berarti hutang, dan baru selesai saat lunas. */
    public function kendaliSendiri(): bool { return true; }

    public function jawaban(Task $task): array
    {
        return $task->status === 'open' ? [['kunci' => 'tempo', 'label' => 'Bayar tempo']] : [];
    }

    public function jawab(Task $task, string $kunci, User $u): string
    {
        abort_unless($kunci === 'tempo', 422, 'Jawaban tidak dikenal.');
        $this->jadikanHutang($task, $u);

        return 'Masuk daftar hutang di kolom Pending.';
    }

    public function formKartu(Task $task): array
    {
        $p = $this->pengajuan($task);

        return [
            'jenis' => 'bayar', 'label' => 'Bayar', 'url' => route('tasks.meja.bayar', $task->id),
            'nominal' => $p ? $this->bayar->sisa($p) : 0, 'bukti' => true,
            'keterangan' => 'Pembayaran dan jurnalnya tercatat otomatis. Boleh sebagian untuk cicilan.',
        ];
    }
}
