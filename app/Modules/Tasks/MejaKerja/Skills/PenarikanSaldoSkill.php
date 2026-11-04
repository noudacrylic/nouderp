<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Core\Accounting\Account;
use App\Modules\Finance\Models\BankTransfer;
use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;

/**
 * Draft penarikan saldo marketplace yang belum punya rekening tujuan → kartu di Meja Owner.
 * Owner tinggal memilih rekening penerima dan memposting. Kartu selesai juga bila draftnya
 * diposting atau dihapus dari halaman Transfer Antar Bank.
 */
class PenarikanSaldoSkill implements SkillOwner
{
    use JadiSkillManajer;

    public function key(): string { return 'penarikan-saldo'; }

    public function label(): string { return 'Penarikan saldo Shopee'; }

    public function deskripsi(): string
    {
        return 'Draft Transfer dari penarikan saldo Shopee yang belum diposting → kartu di Meja Owner: pilih rekening tujuan lalu posting.';
    }

    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 6; }

    public function slaMenitBawaan(): int { return 1440; }

    public function tersedia(): bool { return true; }

    public function satuan(): string { return 'penarikan'; }

    public function pindai(Carbon $sejak): iterable
    {
        foreach (BankTransfer::where('status', 'draft')->whereNull('to_account_id')->orderBy('date')->orderBy('id')->get() as $bt) {
            yield [
                'kunci'         => "penarikan-saldo:{$bt->id}",
                'judul'         => 'Rp' . number_format((float) $bt->amount, 0, ',', '.') . ' tanggal ' . $bt->date->format('d/m/Y') . " ({$bt->number})",
                'deskripsi'     => $bt->reference,
                'taskable_type' => BankTransfer::class,
                'taskable_id'   => $bt->id,
            ];
        }
    }

    public function keadaan(Task $task): ?array
    {
        $bt = BankTransfer::find($task->taskable_id);
        if (!$bt) return ['cancelled', 'Draft dihapus.'];
        if ($bt->status === 'posted') return ['done', 'Diposting.'];

        return $bt->status === 'draft' ? null : ['cancelled', 'Transfer dibatalkan.'];
    }

    public function aksi(Task $task): array
    {
        return [['label' => 'Lihat transfer', 'url' => route('finance.cash-bank.transfers.show', $task->taskable_id)]];
    }

    public function selesaiManual(): bool { return false; }

    public function formKartu(Task $task): array
    {
        return [
            'jenis' => 'bayar', 'label' => 'Pilih rekening & posting', 'url' => route('tasks.meja.penarikan', $task->id),
            'bukti' => false, 'keterangan' => 'Rekening yang menerima penarikan ini.', 'tombol' => 'Posting',
            'daftar' => self::rekeningTujuan()->get(['id', 'code', 'name']),
        ];
    }

    /** Tujuan penarikan: akun kas/bank mana pun selain saldo sumbernya (1104). */
    public static function rekeningTujuan()
    {
        return Account::where('is_cash_account', 1)->where('is_active', 1)->where('code', '!=', '1104')->orderBy('code');
    }
}
