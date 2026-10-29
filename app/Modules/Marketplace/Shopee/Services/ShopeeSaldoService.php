<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Accounting\Account;
use App\Modules\Finance\Models\BankTransfer;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Services\BankTransferService;
use App\Modules\Finance\Services\CashDisbursementService;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Models\ShopeeWalletTransaction as Mutasi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tahap 1b — mutasi Saldo Penjual Shopee lewat API (v2.payment.get_wallet_transaction_list),
 * menggantikan unggah Excel bulanan. Baris disimpan di tabel Mutasi Saldo yang sama
 * (sumber `api`), lalu dua jenis baris dijadikan DRAFT untuk admin:
 *
 *  - Penarikan Dana  → draft Transfer Bank 1104 → (kosong). Shopee tidak menyebut bank
 *    tujuannya, jadi admin memilih BCA / BRI / ShopeePay lalu posting.
 *  - Isi Ulang Saldo Iklan/Koin dari saldo → draft Pengeluaran 1104: 5009 Iklan + 5010 PPN 11%.
 *
 * Sebelum membuat draft, dicari dokumen yang SUDAH mencatatnya (jurnal 1104 posted, atau draft
 * Transfer/Pengeluaran dari 1104) bernominal sama dalam ±HARI_TOLERANSI hari. Ketemu → tidak
 * dibuat draft, cukup ditautkan. Satu dokumen hanya boleh menjawab satu baris.
 *
 * Batas dengan Excel: API tidak menulis baris yang waktunya ≤ baris Excel terakhir, dan impor
 * Excel melewati baris yang waktunya ≥ baris API pertama — supaya satu mutasi tak tercatat dua kali.
 */
class ShopeeSaldoService
{
    /** API menolak rentang waktu yang terlalu panjang ("time period too large"). */
    private const JENDELA_HARI = 14;

    private const PPN = 0.11;

    public function __construct(
        private ShopeeClient $client,
        private MutasiShopeeService $mutasi,
        private BankTransferService $transfer,
        private CashDisbursementService $pengeluaran,
    ) {
    }

    /**
     * Tarik mutasi sejak $dari (default: sehari sebelum baris API terakhir, atau awal hari ini
     * untuk pertama kali), lalu buat draft.
     *
     * @return array{baru:int, draft_transfer:int, draft_iklan:int, sudah_tercatat:int}
     */
    public function tarik(ShopeeShop $shop, ?Carbon $dari = null): array
    {
        $terakhirApi = Mutasi::where('sumber', 'api')->max('waktu');
        $dari ??= $terakhirApi ? Carbon::parse($terakhirApi)->subDay() : now()->startOfDay();

        $batasExcel = Mutasi::where('sumber', 'excel')->max('waktu');
        if ($batasExcel && $dari->lte(Carbon::parse($batasExcel))) {
            $dari = Carbon::parse($batasExcel)->addSecond();
        }

        $baru = 0;
        for ($awal = $dari->copy(); $awal->lt(now()); $awal->addDays(self::JENDELA_HARI)) {
            $akhir = $awal->copy()->addDays(self::JENDELA_HARI)->min(now());
            for ($hal = 1; $hal <= 50; $hal++) {
                $r = $this->client->panggil($shop, 'GET', '/api/v2/payment/get_wallet_transaction_list', [
                    'page_no' => $hal, 'page_size' => 100,
                    'create_time_from' => $awal->timestamp, 'create_time_to' => $akhir->timestamp,
                ]);
                foreach ($r['transaction_list'] ?? [] as $t) {
                    $baru += $this->simpan($shop, $t, $batasExcel);
                }
                if (!($r['more'] ?? false)) {
                    break;
                }
            }
        }

        return ['baru' => $baru] + $this->buatDraft();
    }

    /** Simpan satu transaksi API; 1 bila baris baru. */
    private function simpan(ShopeeShop $shop, array $t, ?string $batasExcel): int
    {
        $tipe   = (string) ($t['transaction_type'] ?? '');
        $jumlah = (float) ($t['amount'] ?? 0);

        // Penanda "penarikan selesai" bernilai 0 — uangnya sudah tercatat di WITHDRAWAL_CREATED.
        if ($tipe === 'WITHDRAWAL_COMPLETED' && $jumlah == 0.0) {
            return 0;
        }

        $waktu = Carbon::createFromTimestamp((int) $t['create_time'], config('app.timezone'));
        if ($batasExcel && $waktu->lte(Carbon::parse($batasExcel))) {
            return 0;
        }

        $sn   = trim((string) ($t['order_sn'] ?? '')) ?: null;
        $desk = (string) ($t['description'] ?? '');

        $baris = Mutasi::firstOrCreate(['kunci' => 'api:' . $t['transaction_id']], [
            'shop_id'        => $shop->shop_id,
            'sumber'         => 'api',
            'transaction_id' => $t['transaction_id'],
            'waktu'          => $waktu,
            'tipe'           => $tipe,
            'deskripsi'      => $desk,
            'order_sn'       => $sn,
            'jumlah'         => $jumlah,
            'saldo_akhir'    => isset($t['current_balance']) ? (float) $t['current_balance'] : null,
            'status'         => $t['status'] ?? null,
            'kategori'       => Mutasi::kategoriApi($tipe, $desk, $sn),
        ]);

        return $baris->wasRecentlyCreated ? 1 : 0;
    }

    /**
     * Draft untuk penarikan & isi ulang iklan yang belum punya dokumen.
     *
     * @return array{draft_transfer:int, draft_iklan:int, sudah_tercatat:int}
     */
    public function buatDraft(): array
    {
        $hasil = ['draft_transfer' => 0, 'draft_iklan' => 0, 'sudah_tercatat' => 0];
        $akun1104 = $this->mutasi->akunSaldo();
        if (!$akun1104) {
            return $hasil;
        }

        $baris = Mutasi::where('sumber', 'api')
            ->whereNull('dokumen_type')
            ->where('jumlah', '<', 0)
            ->where(fn ($q) => $q->where('tipe', 'WITHDRAWAL_CREATED')
                ->orWhere(fn ($w) => $w->where('kategori', 'iklan')))
            ->orderBy('waktu')
            ->get();

        foreach ($baris as $m) {
            DB::transaction(function () use ($m, $akun1104, &$hasil) {
                if ($ada = $this->sudahTercatat($m, $akun1104)) {
                    $m->forceFill($ada)->save();
                    $hasil['sudah_tercatat']++;

                    return;
                }

                if ($m->tipe === 'WITHDRAWAL_CREATED') {
                    $bt = $this->draftPenarikan($m, $akun1104);
                    $m->forceFill(['dokumen_type' => 'bank_transfer', 'dokumen_id' => $bt->id,
                        'dokumen_catatan' => 'Draft ' . $bt->number . ' dibuat — pilih bank tujuan lalu posting'])->save();
                    $hasil['draft_transfer']++;
                } else {
                    $cd = $this->draftIklan($m, $akun1104);
                    $m->forceFill(['dokumen_type' => 'cash_disbursement', 'dokumen_id' => $cd->id,
                        'dokumen_catatan' => 'Draft ' . $cd->number . ' dibuat — tinggal posting'])->save();
                    $hasil['draft_iklan']++;
                }
            });
        }

        return $hasil;
    }

    private function draftPenarikan(Mutasi $m, int $akun1104): BankTransfer
    {
        return $this->transfer->createDraft([
            'date'            => $m->waktu->toDateString(),
            'from_account_id' => $akun1104,
            'to_account_id'   => null,
            'amount'          => abs($m->jumlah),
            'reference'       => 'Penarikan Dana Shopee ' . $m->waktu->format('d M H:i'),
            'notes'           => 'Otomatis dari mutasi saldo Shopee. Pilih bank tujuan (BCA / BRI / ShopeePay) sesuai rekening penerima, lalu posting.',
        ], tujuanBolehKosong: true);
    }

    private function draftIklan(Mutasi $m, int $akun1104): CashDisbursement
    {
        $total = abs($m->jumlah);
        $dasar = round($total / (1 + self::PPN));

        return $this->pengeluaran->createDraft([
            'date'            => $m->waktu->toDateString(),
            'type'            => 'general',
            'cash_account_id' => $akun1104,
            'reference'       => 'Isi Ulang Saldo Iklan Shopee ' . $m->waktu->format('d M H:i'),
            'notes'           => 'Otomatis dari mutasi saldo Shopee (dibayar dari Saldo Penjual). Periksa lalu posting.',
            'lines'           => [
                ['account_id' => Account::where('code', '5009')->value('id'), 'amount' => $dasar, 'description' => 'Biaya iklan Shopee'],
                ['account_id' => Account::where('code', '5010')->value('id'), 'amount' => $total - $dasar, 'description' => 'PPN iklan Shopee'],
            ],
        ]);
    }

    /**
     * Dokumen yang sudah mencatat baris ini (nominal sama, ±HARI_TOLERANSI hari, dari 1104),
     * belum dipakai baris lain. Null = belum tercatat.
     *
     * @return array{dokumen_type:string, dokumen_id:int, dokumen_catatan:string}|null
     */
    private function sudahTercatat(Mutasi $m, int $akun1104): ?array
    {
        $jumlah = abs($m->jumlah);
        $dari   = $m->waktu->copy()->subDays(MutasiShopeeService::HARI_TOLERANSI)->toDateString();
        $sampai = $m->waktu->copy()->addDays(MutasiShopeeService::HARI_TOLERANSI)->toDateString();
        $dipakai = fn (string $jenis) => Mutasi::where('dokumen_type', $jenis)->whereNotNull('dokumen_id')->pluck('dokumen_id');

        $jurnal = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $akun1104)->where('j.status', 'posted')
            ->whereBetween('j.date', [$dari, $sampai])
            ->whereRaw('ABS(l.credit - ?) < 0.5', [$jumlah])
            ->whereNotIn('j.id', $dipakai('journal'))
            // Jurnal dari draft yang sudah ditautkan ke baris lain (lalu diposting) juga sudah terpakai.
            ->where(fn ($q) => $q->whereNull('j.reference_type')
                ->orWhere(fn ($w) => $w->where('j.reference_type', 'bank_transfer')->whereNotIn('j.reference_id', $dipakai('bank_transfer')))
                ->orWhere(fn ($w) => $w->where('j.reference_type', 'cash_disbursement')->whereNotIn('j.reference_id', $dipakai('cash_disbursement')))
                ->orWhereNotIn('j.reference_type', ['bank_transfer', 'cash_disbursement']))
            ->orderByRaw('ABS(DATEDIFF(j.date, ?))', [$m->waktu->toDateString()])
            ->first(['j.id', 'j.reference_number']);
        if ($jurnal) {
            return ['dokumen_type' => 'journal', 'dokumen_id' => $jurnal->id,
                'dokumen_catatan' => 'Sudah tercatat di ' . ($jurnal->reference_number ?: 'jurnal #' . $jurnal->id)];
        }

        $model = $m->tipe === 'WITHDRAWAL_CREATED'
            ? BankTransfer::where('from_account_id', $akun1104)->where('status', 'draft')->whereRaw('ABS(amount - ?) < 0.5', [$jumlah])
                ->whereNotIn('id', $dipakai('bank_transfer'))
            : CashDisbursement::where('cash_account_id', $akun1104)->where('status', 'draft')->whereRaw('ABS(total - ?) < 0.5', [$jumlah])
                ->whereNotIn('id', $dipakai('cash_disbursement'));
        $draft = $model->whereBetween('date', [$dari, $sampai])->first();
        if ($draft) {
            return ['dokumen_type' => $draft instanceof BankTransfer ? 'bank_transfer' : 'cash_disbursement', 'dokumen_id' => $draft->id,
                'dokumen_catatan' => 'Sudah ada draft ' . $draft->number];
        }

        return null;
    }
}
