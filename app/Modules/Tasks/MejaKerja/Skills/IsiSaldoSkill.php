<?php

namespace App\Modules\Tasks\MejaKerja\Skills;

use App\Modules\Tasks\MejaKerja\Contracts\JadiSkillManajer;
use App\Modules\Tasks\MejaKerja\Contracts\SkillOwner;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saldo ShopeePay yang dipegang admin di bawah batas → kartu Isi saldo di Meja Owner. Saldonya
 * catatan ERP (akun 1295), bukan saldo asli; selisih dicocokkan lewat rekonsiliasi bulanan.
 * Kartu selesai saat saldo kembali di atas batas, dari transfer maupun pencairan Shopee.
 */
class IsiSaldoSkill implements SkillOwner
{
    use JadiSkillManajer;

    public const AKUN = '1295';
    public const BATAS = 1000000;
    public const TARGET = 3000000;

    public function key(): string { return 'isi-saldo'; }

    public function label(): string { return 'Isi saldo ShopeePay'; }

    public function deskripsi(): string
    {
        return 'Saldo ShopeePay di ERP (akun 1295) di bawah Rp1.000.000 → kartu Isi saldo di Meja Owner dengan usulan isi sampai Rp3.000.000.';
    }

    public function modeBawaan(): string { return self::OTOMATIS; }

    public function peranBawaan(): string { return 'owner'; }

    public function bobotBawaan(): int { return 6; }

    public function slaMenitBawaan(): int { return 1440; }

    public function tersedia(): bool
    {
        return DB::table('accounts')->where('code', self::AKUN)->where('is_active', 1)->exists();
    }

    public function satuan(): string { return 'pengisian'; }

    public function saldo(): float
    {
        return (float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', self::AKUN)->where('j.status', 'posted')
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS saldo')->value('saldo');
    }

    private function judul(float $saldo): string
    {
        return 'ShopeePay sisa Rp' . number_format($saldo, 0, ',', '.') . ' (batas Rp' . number_format(self::BATAS, 0, ',', '.') . ')';
    }

    public function pindai(Carbon $sejak): iterable
    {
        $saldo = $this->saldo();
        if ($saldo >= self::BATAS) return;

        // Per kejadian: saldo boleh turun lagi bulan depan, tapi tetap satu kartu selama masih rendah.
        $ke = Task::where('skill', $this->key())->whereIn('status', ['done', 'cancelled'])->count() + 1;
        yield ['kunci' => "isi-saldo:$ke", 'judul' => $this->judul($saldo)];
    }

    public function keadaan(Task $task): ?array
    {
        $saldo = $this->saldo();

        return $saldo >= self::BATAS ? ['done', 'Saldo kembali Rp' . number_format($saldo, 0, ',', '.') . '.'] : null;
    }

    public function perbarui(Task $task): ?array
    {
        $judul = $this->judul($this->saldo());

        return $judul === $task->title ? null : ['title' => $judul, 'status' => null, 'catatan' => "Saldo berubah: $judul"];
    }

    public function aksi(Task $task): array
    {
        return [];
    }

    public function selesaiManual(): bool { return false; }

    public function formKartu(Task $task): array
    {
        return [
            'jenis' => 'bayar', 'label' => 'Sudah ditransfer', 'url' => route('tasks.meja.isi-saldo', $task->id),
            'nominal' => max(0, self::TARGET - $this->saldo()), 'bukti' => false,
            'keterangan' => 'Dicatat sebagai Transfer Antar Bank ke Shopee Pay.',
        ];
    }
}
