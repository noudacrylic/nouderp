<?php

namespace App\Console\Commands;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Buka kembali piutang faktur marketplace yang terlanjur "lunas" oleh uang muka.
 *
 * LATAR. Faktur marketplace gaya baru terbit saat PENGIRIMAN. Antara 19 September 2026 dan
 * perubahan ini, posting faktur langsung memakai uang muka (Dr 2105 / Cr Piutang), sehingga
 * fakturnya berstatus LUNAS padahal marketplace belum menyerahkan sesen pun — uang pembeli
 * masih ditahan. Aturannya kini: piutang dibiarkan terbuka sampai pesanan SELESAI, dan
 * pelunasannya dibuat bersamaan dengan pencairan saldo ditahan.
 *
 * YANG DIPERBAIKI. Hanya faktur yang benar-benar salah tempat:
 *   - `fee_at_settlement` = faktur gaya baru (yang lama, 7.372 faktur, tak tersentuh);
 *   - `advance_applied` > 0 = piutangnya sudah terlanjur ditutup;
 *   - `marketplace_processed` = 0 = pesanannya BELUM selesai, jadi memang belum boleh lunas.
 * Faktur yang pesanannya sudah selesai dibiarkan: pelunasannya sah, cuma dibukukan lebih awal.
 *
 * CARA. Jurnal penyesuaian BARU (Dr Piutang / Cr Uang Muka) bertanggal hari ini, bukan
 * mengubah jurnal faktur lama yang bisa saja ada di periode tertutup. Murni antar-akun
 * neraca — tidak mengubah laba rugi bulan mana pun. Begitu pesanannya selesai,
 * MarketplaceEngineService akan melunasi seperti biasa.
 *
 * Idempoten lewat reference_type `marketplace_ar_reopen` per faktur.
 */
class MarketplaceBukaPiutangFaktur extends Command
{
    protected $signature = 'marketplace:buka-piutang-faktur
        {--dry-run : Hanya tampilkan yang akan diperbaiki, tanpa menulis apa pun}
        {--invoice= : Batasi ke satu nomor faktur}';

    protected $description = 'Buka kembali piutang faktur marketplace yang terlanjur lunas sebelum pesanannya selesai';

    public const REF_TYPE = 'marketplace_ar_reopen';

    public function handle(JournalPostingService $poster): int
    {
        $dry = (bool) $this->option('dry-run');

        $arId      = (int) DB::table('accounts')->where('code', '1120')->value('id');
        $advanceId = (int) DB::table('accounts')->where('code', '2105')->value('id');
        if (!$arId || !$advanceId) {
            $this->error('Akun Piutang (1120) / Uang Muka Customer (2105) tidak ditemukan.');
            return self::FAILURE;
        }

        $q = SalesInvoice::query()
            ->where('status', 'posted')
            ->where('fee_at_settlement', true)
            ->where('advance_applied', '>', 0)
            ->where(fn ($w) => $w->whereNull('marketplace_processed')->orWhere('marketplace_processed', false));

        if ($no = $this->option('invoice')) {
            $q->where('invoice_number', $no);
        }

        $invoices = $q->orderBy('id')->get();
        if ($invoices->isEmpty()) {
            $this->info('Tidak ada faktur yang perlu diperbaiki.');
            return self::SUCCESS;
        }

        $diperbaiki = 0;
        $dilewati   = 0;
        $total      = 0.0;

        foreach ($invoices as $inv) {
            $amount = round((float) $inv->advance_applied, 2);
            if ($amount <= 0) {
                $dilewati++;
                continue;
            }

            $sudah = Journal::where('reference_type', self::REF_TYPE)
                ->where('reference_id', $inv->id)
                ->where('status', '!=', 'void')
                ->exists();
            if ($sudah) {
                $this->line("  - {$inv->invoice_number}: sudah pernah dibuka, dilewati.");
                $dilewati++;
                continue;
            }

            $this->line(sprintf('  %s %s  %s', $dry ? '[pratinjau]' : '[perbaiki]  ', $inv->invoice_number, rupiah($amount)));
            $total += $amount;

            if ($dry) {
                $diperbaiki++;
                continue;
            }

            DB::transaction(function () use ($poster, $inv, $amount, $arId, $advanceId) {
                $poster->post(new JournalEntryDTO(
                    date: now()->toDateString(),
                    reference_type: self::REF_TYPE,
                    reference_id: $inv->id,
                    description: 'Buka kembali piutang marketplace - ' . $inv->invoice_number,
                    lines: [
                        new JournalLineDTO($arId, $amount, 0, 'Piutang dibuka kembali (dana belum cair)'),
                        new JournalLineDTO($advanceId, 0, $amount, 'Uang muka dikembalikan ke posisi semula'),
                    ],
                    reference_number: $inv->invoice_number,
                ));

                $inv->forceFill(['advance_applied' => 0])->save();
            });

            $diperbaiki++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d faktur (%s), %d dilewati.',
            $dry ? 'Akan diperbaiki:' : 'Diperbaiki:',
            $diperbaiki,
            rupiah($total),
            $dilewati
        ));

        return self::SUCCESS;
    }
}
