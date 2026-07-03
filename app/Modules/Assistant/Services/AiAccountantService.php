<?php

namespace App\Modules\Assistant\Services;

use App\Core\Accounting\Account;
use App\Core\Inventory\Product;
use App\Core\Journal\JournalLine;
use App\Models\AnthropicSetting;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\FreightSetting;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Modules\Finance\Models\BankTransfer;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Models\CashDisbursementLine;
use App\Modules\Finance\Services\BankTransferService;
use App\Modules\Finance\Services\CashDisbursementService;
use App\Modules\Notifications\Services\TelegramNotifier;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CustomerPaymentService;
use App\Modules\Sales\Services\PromotionService;
use App\Modules\Sales\Services\SalesOrderPdfService;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Support\Facades\Cache;

/**
 * Asisten pencatat keuangan via Telegram.
 *
 * Alur: pesan teks → percakapan dengan Claude (tool use) → AI memanggil tool
 * (cari_akun / catat_pengeluaran / catat_transfer_bank) → posting lewat service ERP
 * yang sama dengan UI (jurnal konsisten). Nominal di atas ambang ditahan untuk
 * konfirmasi deterministik (ya/batal) sebelum diposting. /batal mem-void transaksi
 * terakhir (CD atau transfer).
 *
 * Cakupan: Pengeluaran umum & Prive, Transfer antar bank, Bayar ongkir (per faktur),
 * baca struk (vision), laporan ringkas, serta Sales Order (buat draft → kirim PDF →
 * post → DP/uang muka). Pembelian supplier berstok & refund masih manual di ERP.
 */
class AiAccountantService
{
    public function __construct(
        private ClaudeClient $claude,
        private CashDisbursementService $cdService,
        private BankTransferService $btService,
        private SalesOrderService $soService,
        private CustomerPaymentService $paymentService,
        private SalesOrderPdfService $soPdfService,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * Titik masuk utama. $image = ['data'=>base64,'media_type'=>...] bila user kirim foto struk.
     * Return teks balasan untuk dikirim ke Telegram (HTML).
     */
    public function handle(User $user, string $chatId, string $text, ?array $image = null): string
    {
        $text = trim($text);
        $lower = strtolower($text);

        // Perintah & konfirmasi hanya berlaku untuk pesan teks murni (bukan foto).
        if (! $image) {
            if ($lower === '/baru' || $lower === 'reset' || $lower === '/reset') {
                $this->forgetConversation($chatId);
                $this->forgetPending($chatId);
                return '🔄 Percakapan direset. Silakan mulai lagi.';
            }
            if (str_starts_with($lower, '/batal')) {
                return $this->voidLast($chatId);
            }
            if ($lower === '/help' || $lower === '/bantuan') {
                return $this->helpText();
            }

            // Ada transaksi menunggu konfirmasi (nominal di atas ambang)?
            if ($pending = Cache::get($this->pendingKey($chatId))) {
                return $this->resolvePending($chatId, $text, $pending);
            }
        } else {
            // Foto baru = transaksi baru; buang konfirmasi lama yang menggantung.
            $this->forgetPending($chatId);
        }

        if (! $this->claude->enabled()) {
            return '⚠️ AI belum aktif: API key Claude belum diatur (Settings → Integrasi → Claude AI).';
        }

        return $this->converse($user, $chatId, $text, $image);
    }

    // ───────────────────────── Percakapan + tool loop ─────────────────────────

    private function converse(User $user, string $chatId, string $text, ?array $image = null): string
    {
        $messages = Cache::get($this->convKey($chatId), []);

        if ($image) {
            // Giliran dengan foto struk → konten gambar + instruksi.
            $messages[] = ['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => [
                    'type'       => 'base64',
                    'media_type' => $image['media_type'],
                    'data'       => $image['data'],
                ]],
                ['type' => 'text', 'text' => $text !== '' ? $text : 'Catat transaksi dari struk/nota di gambar ini.'],
            ]];
        } else {
            $messages[] = ['role' => 'user', 'content' => $text];
        }

        // Foto → model vision (Opus) untuk giliran ini; teks → model teks (Sonnet).
        $modelForTurn  = $image ? $this->visionModel() : $this->model();
        $postedSummary = null;

        for ($i = 0; $i < 6; $i++) {
            $resp = $this->claude->messages([
                'model'      => $modelForTurn,
                'max_tokens' => 1024,
                'system'     => [[
                    'type'          => 'text',
                    'text'          => $this->systemPrompt($user),
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'tools'    => $this->tools(),
                'messages' => $messages,
            ]);

            if (isset($resp['_error'])) {
                $status = $resp['_status'] ?? null;
                if (in_array($status, [429, 529], true) || stripos($resp['_error'], 'overload') !== false) {
                    // Jangan simpan giliran yang gagal → user tinggal kirim ulang dari kondisi bersih.
                    return '⏳ Server AI sedang sibuk sebentar. Coba kirim lagi beberapa detik lagi ya.';
                }
                return '❌ AI error: ' . $resp['_error'];
            }

            $content = $resp['content'] ?? [];
            $stop    = $resp['stop_reason'] ?? null;

            // Simpan giliran assistant ke riwayat dengan input tool_use ternormalisasi (objek),
            // tapi $content asli (array) tetap dipakai untuk eksekusi tool di bawah.
            $messages[] = ['role' => 'assistant', 'content' => $this->normalizeToolInputs($content)];

            if ($stop === 'tool_use') {
                $toolResults = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'tool_use') {
                        continue;
                    }
                    $result = $this->execTool($chatId, $block['name'] ?? '', $block['input'] ?? []);

                    // Halt → butuh konfirmasi user; hentikan loop, kirim pesan deterministik.
                    if (isset($result['_halt'])) {
                        $this->saveConversation($chatId, $messages);
                        return $result['message'];
                    }
                    if (! empty($result['_posted_summary'])) {
                        $postedSummary = $result['_posted_summary'];
                    }
                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block['id'] ?? '',
                        'content'     => $result['content'] ?? '',
                    ];
                }
                $messages[] = ['role' => 'user', 'content' => $toolResults];
                continue; // panggil model lagi dengan hasil tool
            }

            // end_turn → balasan teks final
            $reply = $this->collectText($content);

            if ($postedSummary !== null) {
                // Transaksi sukses turn ini → reset percakapan + tempel hint /batal.
                $this->forgetConversation($chatId);
                return ($reply !== '' ? $reply : '✅ Tercatat.')
                    . "\n\n↩️ /batal untuk membatalkan transaksi terakhir.";
            }

            $this->saveConversation($chatId, $messages);
            return $reply !== '' ? $reply : '(tidak ada balasan)';
        }

        $this->saveConversation($chatId, $messages);
        return 'Maaf, percakapan terlalu panjang. Coba /baru lalu ulangi lebih ringkas.';
    }

    private function execTool(string $chatId, string $name, array $input): array
    {
        return match ($name) {
            'cari_akun'            => ['content' => $this->toolCariAkun($input)],
            'catat_pengeluaran'    => $this->toolCatatPengeluaran($chatId, $input),
            'catat_transfer_bank'  => $this->toolCatatTransfer($chatId, $input),
            'cari_faktur_ongkir'   => ['content' => $this->toolCariFakturOngkir($input)],
            'catat_bayar_ongkir'   => $this->toolCatatBayarOngkir($chatId, $input),
            'ringkas_pengeluaran'  => ['content' => $this->toolRingkasPengeluaran($input)],
            'saldo_kas_bank'       => ['content' => $this->toolSaldoKasBank($input)],
            'cari_pelanggan'       => ['content' => $this->toolCariPelanggan($input)],
            'buat_pelanggan'       => ['content' => $this->toolBuatPelanggan($input)],
            'cari_produk'          => ['content' => $this->toolCariProduk($input)],
            'cek_harga_produk'     => ['content' => $this->toolCekHargaProduk($input)],
            'cari_so'              => ['content' => $this->toolCariSo($input)],
            'buat_so_draft'        => $this->toolBuatSoDraft($chatId, $input),
            'edit_so_draft'        => $this->toolEditSoDraft($chatId, $input),
            'post_so'              => $this->toolPostSo($chatId, $input),
            'catat_dp'             => $this->toolCatatDp($chatId, $input),
            default                => ['content' => 'Tool tidak dikenal: ' . $name],
        };
    }

    // ───────────────────────── Tools ─────────────────────────

    private function toolCariAkun(array $input): string
    {
        $kw    = trim((string) ($input['keyword'] ?? ''));
        $jenis = (string) ($input['jenis'] ?? 'beban');

        $q = Account::query()->where('is_active', 1);
        if ($jenis === 'kas_bank') {
            // is_cash_account = rekening kas/bank "asli" (valid utk transfer & sumber dana),
            // bukan akun perantara spt saldo ditahan marketplace.
            $q->where('is_cash_account', 1);
        } elseif ($jenis === 'equity') {
            $q->where('type', 'equity');
        } else { // beban
            $q->where('type', 'expense');
        }
        if ($kw !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$kw}%")->orWhere('code', 'like', "%{$kw}%"));
        }

        $rows = $q->orderBy('code')->limit(12)->get(['id', 'code', 'name']);
        if ($rows->isEmpty()) {
            return "Tidak ada akun jenis '{$jenis}' yang cocok dengan '{$kw}'. "
                . 'Sarankan user memakai kata lain atau membuat akunnya dulu di Bagan Akun (COA).';
        }

        return $rows->map(fn ($a) => "id={$a->id} | {$a->code} {$a->name}")->implode("\n");
    }

    private function toolCatatPengeluaran(string $chatId, array $input): array
    {
        $akunId  = (int) ($input['akun_beban_id'] ?? 0);
        $kasId   = (int) ($input['kas_account_id'] ?? 0);
        $nominal = round((float) ($input['nominal'] ?? 0), 2);
        $ket     = trim((string) ($input['keterangan'] ?? ''));
        $tgl     = trim((string) ($input['tanggal'] ?? '')) ?: now()->format('Y-m-d');

        $akun = Account::find($akunId);
        $kas  = Account::find($kasId);

        if (! $akun) {
            return ['content' => "Gagal: akun beban id={$akunId} tidak ditemukan. Pakai cari_akun dulu."];
        }
        if (! $kas) {
            return ['content' => "Gagal: akun kas/bank id={$kasId} tidak ditemukan. Pakai cari_akun dulu."];
        }
        if (! $kas->isCash()) {
            return ['content' => "Gagal: id={$kasId} ({$kas->name}) bukan akun kas/bank. Minta user pilih sumber dana kas/bank."];
        }
        if ($nominal <= 0) {
            return ['content' => 'Gagal: nominal harus lebih dari 0.'];
        }
        if ($ket === '') {
            $ket = $akun->name;
        }

        $rp      = 'Rp ' . number_format($nominal, 0, ',', '.');
        $summary = "{$ket}\n{$rp} — {$akun->name} (dari {$kas->name}) · {$tgl}";

        try {
            $cd = $this->cdService->createDraft([
                'date'            => $tgl,
                'type'            => 'general',
                'cash_account_id' => $kas->id,
                'notes'           => $ket,
                'lines'           => [[
                    'account_id'  => $akun->id,
                    'amount'      => $nominal,
                    'description' => $ket,
                ]],
            ]);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal membuat draft: ' . $e->getMessage()];
        }

        // Di atas ambang → tahan untuk konfirmasi (deterministik, di luar tangan model).
        if ($nominal > $this->threshold()) {
            Cache::put($this->pendingKey($chatId), [
                'kind'    => 'cd',
                'id'      => $cd->id,
                'number'  => $cd->number,
                'summary' => $summary,
            ], $this->ttl());

            return [
                '_halt'   => true,
                'message' => '⚠️ Perlu konfirmasi (di atas ' . $this->thresholdLabel() . "):\n\n{$summary}\n\n"
                    . 'Balas <b>ya</b> untuk posting atau <b>batal</b> untuk membatalkan.',
            ];
        }

        try {
            $this->cdService->post($cd);
        } catch (\Throwable $e) {
            return ['content' => 'Draft dibuat tapi gagal posting: ' . $e->getMessage()];
        }

        Cache::put($this->lastKey($chatId), ['kind' => 'cd', 'id' => $cd->id], $this->ttl());

        return [
            'content'         => "Berhasil diposting (nomor {$cd->number}). {$summary}",
            '_posted_summary' => "✅ <b>Diposting</b>: {$cd->number}\n{$summary}",
        ];
    }

    private function toolCatatTransfer(string $chatId, array $input): array
    {
        $fromId  = (int) ($input['dari_account_id'] ?? 0);
        $toId    = (int) ($input['ke_account_id'] ?? 0);
        $nominal = round((float) ($input['nominal'] ?? 0), 2);
        $fee     = round((float) ($input['biaya_admin'] ?? 0), 2);
        $ket     = trim((string) ($input['keterangan'] ?? ''));
        $tgl     = trim((string) ($input['tanggal'] ?? '')) ?: now()->format('Y-m-d');

        $from = Account::find($fromId);
        $to   = Account::find($toId);

        if (! $from) {
            return ['content' => "Gagal: rekening sumber id={$fromId} tidak ditemukan. Pakai cari_akun jenis=kas_bank."];
        }
        if (! $to) {
            return ['content' => "Gagal: rekening tujuan id={$toId} tidak ditemukan. Pakai cari_akun jenis=kas_bank."];
        }
        if (! (int) $from->is_cash_account) {
            return ['content' => "Gagal: {$from->name} bukan rekening kas/bank yang bisa ditransfer. Cari rekening lain via cari_akun jenis=kas_bank."];
        }
        if (! (int) $to->is_cash_account) {
            return ['content' => "Gagal: {$to->name} bukan rekening kas/bank yang bisa ditransfer. Cari rekening lain via cari_akun jenis=kas_bank."];
        }
        if ($from->id === $to->id) {
            return ['content' => 'Gagal: rekening sumber & tujuan tidak boleh sama.'];
        }
        if ($nominal <= 0) {
            return ['content' => 'Gagal: nominal harus lebih dari 0.'];
        }

        $data = [
            'date'            => $tgl,
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => $nominal,
            'notes'           => $ket ?: null,
        ];

        if ($fee > 0) {
            $feeAcc = Account::where('code', '5103')->where('is_active', 1)->first(); // Beban Administrasi Bank
            if (! $feeAcc) {
                return ['content' => 'Ada biaya admin tapi akun 5103 (Beban Administrasi Bank) tidak ada. '
                    . 'Sarankan user membuat akunnya atau mencatat tanpa biaya admin.'];
            }
            $data['admin_fee']            = $fee;
            $data['admin_fee_account_id'] = $feeAcc->id;
            $data['fee_borne_by']         = 'source';
        }

        $rp       = 'Rp ' . number_format($nominal, 0, ',', '.');
        $feeLabel = $fee > 0 ? ' (+ admin Rp ' . number_format($fee, 0, ',', '.') . ')' : '';
        $summary  = ($ket !== '' ? "{$ket}\n" : '')
            . "Transfer {$rp}{$feeLabel} — {$from->name} → {$to->name} · {$tgl}";

        try {
            $bt = $this->btService->createDraft($data);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal membuat draft transfer: ' . $e->getMessage()];
        }

        // Patokan ambang = nominal transfer (tanpa biaya admin).
        if ($nominal > $this->threshold()) {
            Cache::put($this->pendingKey($chatId), [
                'kind'    => 'transfer',
                'id'      => $bt->id,
                'number'  => $bt->number,
                'summary' => $summary,
            ], $this->ttl());

            return [
                '_halt'   => true,
                'message' => '⚠️ Perlu konfirmasi (di atas ' . $this->thresholdLabel() . "):\n\n{$summary}\n\n"
                    . 'Balas <b>ya</b> untuk posting atau <b>batal</b> untuk membatalkan.',
            ];
        }

        try {
            $this->btService->post($bt);
        } catch (\Throwable $e) {
            return ['content' => 'Draft transfer dibuat tapi gagal posting: ' . $e->getMessage()];
        }

        Cache::put($this->lastKey($chatId), ['kind' => 'transfer', 'id' => $bt->id], $this->ttl());

        return [
            'content'         => "Transfer berhasil diposting (nomor {$bt->number}). {$summary}",
            '_posted_summary' => "✅ <b>Diposting</b>: {$bt->number}\n{$summary}",
        ];
    }

    // ───────────────────────── Bayar Ongkir (titipan per faktur) ─────────────────────────

    private function toolCariFakturOngkir(array $input): string
    {
        $kw = trim((string) ($input['keyword'] ?? ''));

        // Faktur yang ongkirnya sudah/sedang dibayar (CD freight draft/posted) → dikecualikan.
        $paidIds = CashDisbursementLine::whereNotNull('sales_invoice_id')
            ->whereHas('disbursement', fn ($q) => $q->where('type', 'freight')->whereIn('status', ['draft', 'posted']))
            ->pluck('sales_invoice_id')->all();

        $rows = SalesInvoice::with(['customer', 'delivery'])
            ->whereIn('status', ['posted', 'paid'])
            ->where('shipping_cost', '>', 0)
            ->whereNotIn('id', $paidIds)
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('invoice_number', 'like', "%{$kw}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$kw}%"))
                ->orWhereHas('delivery', fn ($d) => $d->where('delivery_number', 'like', "%{$kw}%")
                    ->orWhere('tracking_number', 'like', "%{$kw}%"))))
            ->orderByDesc('invoice_date')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return 'Tidak ada faktur dengan titipan ongkir yang belum dibayar' . ($kw !== '' ? " cocok '{$kw}'" : '') . '.';
        }

        return $rows->map(function ($i) {
            $tgl  = optional($i->invoice_date)->format('d/m/Y') ?: '-';
            $resi = $i->delivery->tracking_number ?? $i->delivery->delivery_number ?? null;
            return "invoice_id={$i->id} | {$i->invoice_number} | " . ($i->customer->name ?? '-')
                . ' | titipan Rp ' . number_format((float) $i->shipping_cost, 0, ',', '.')
                . " | {$tgl}" . ($resi ? " | resi {$resi}" : '');
        })->implode("\n");
    }

    private function toolCatatBayarOngkir(string $chatId, array $input): array
    {
        $invoiceId = (int) ($input['invoice_id'] ?? 0);
        $bayar     = round((float) ($input['bayar_aktual'] ?? 0), 2);
        $kasId     = (int) ($input['kas_account_id'] ?? 0);
        $tgl       = trim((string) ($input['tanggal'] ?? '')) ?: now()->format('Y-m-d');

        $inv = SalesInvoice::with('customer')->find($invoiceId);
        if (! $inv) {
            return ['content' => "Gagal: faktur id={$invoiceId} tidak ditemukan. Pakai cari_faktur_ongkir dulu."];
        }
        $titipan = (float) $inv->shipping_cost;
        if ($titipan <= 0) {
            return ['content' => "Gagal: faktur {$inv->invoice_number} tidak punya titipan ongkir."];
        }

        $sudah = CashDisbursementLine::where('sales_invoice_id', $inv->id)
            ->whereHas('disbursement', fn ($q) => $q->where('type', 'freight')->whereIn('status', ['draft', 'posted']))
            ->exists();
        if ($sudah) {
            return ['content' => "Gagal: ongkir faktur {$inv->invoice_number} sudah pernah dibayar/draft — tidak boleh dobel."];
        }

        $kas = Account::find($kasId);
        if (! $kas) {
            return ['content' => "Gagal: rekening id={$kasId} tidak ditemukan. Pakai cari_akun jenis=kas_bank."];
        }
        if (! (int) $kas->is_cash_account) {
            return ['content' => "Gagal: {$kas->name} bukan rekening kas/bank."];
        }
        if ($bayar <= 0) {
            return ['content' => 'Gagal: nominal bayar aktual harus lebih dari 0.'];
        }

        $titipanId = Account::where('code', '1203')->value('id');
        if (! $titipanId) {
            return ['content' => 'Gagal: akun 1203 (Titipan Ongkir) belum ada di COA.'];
        }

        // Pra-cek akun selisih bila ada selisih (post() akan throw kalau belum diset).
        $selisih = round($titipan - $bayar, 2);
        if ($selisih != 0.0) {
            $fs = FreightSetting::singleton();
            if ($selisih > 0 && ! $fs->gain_account_id) {
                return ['content' => "Ada selisih LEBIH titipan ongkir, tapi akun 'Selisih Lebih (Gain)' belum diset di Settings → Pengaturan Ongkir."];
            }
            if ($selisih < 0 && ! $fs->loss_account_id) {
                return ['content' => "Ada selisih KURANG titipan ongkir, tapi akun 'Selisih Kurang (Loss)' belum diset di Settings → Pengaturan Ongkir."];
            }
        }

        $cust    = $inv->customer->name ?? '-';
        $rpBayar = 'Rp ' . number_format($bayar, 0, ',', '.');
        $rpTit   = 'Rp ' . number_format($titipan, 0, ',', '.');
        $selLbl  = $selisih > 0
            ? 'lebih Rp ' . number_format($selisih, 0, ',', '.')
            : ($selisih < 0 ? 'kurang Rp ' . number_format(abs($selisih), 0, ',', '.') : 'pas');
        $summary = "Bayar ongkir {$inv->invoice_number} ({$cust})\nTitipan {$rpTit} · bayar {$rpBayar} (selisih {$selLbl}) · dari {$kas->name} · {$tgl}";

        try {
            $cd = $this->cdService->createDraft([
                'date'            => $tgl,
                'type'            => 'freight',
                'cash_account_id' => $kas->id,
                'lines'           => [[
                    'account_id'       => $titipanId,
                    'sales_invoice_id' => $inv->id,
                    'amount'           => $bayar,
                ]],
            ]);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal membuat draft ongkir: ' . $e->getMessage()];
        }

        // Ambang = bayar aktual.
        if ($bayar > $this->threshold()) {
            Cache::put($this->pendingKey($chatId), [
                'kind' => 'cd', 'id' => $cd->id, 'number' => $cd->number, 'summary' => $summary,
            ], $this->ttl());

            return [
                '_halt'   => true,
                'message' => '⚠️ Perlu konfirmasi (di atas ' . $this->thresholdLabel() . "):\n\n{$summary}\n\n"
                    . 'Balas <b>ya</b> untuk posting atau <b>batal</b>.',
            ];
        }

        try {
            $this->cdService->post($cd);
        } catch (\Throwable $e) {
            return ['content' => 'Draft ongkir dibuat tapi gagal posting: ' . $e->getMessage()];
        }

        Cache::put($this->lastKey($chatId), ['kind' => 'cd', 'id' => $cd->id], $this->ttl());

        return [
            'content'         => "Bayar ongkir diposting (nomor {$cd->number}). {$summary}",
            '_posted_summary' => "✅ <b>Diposting</b>: {$cd->number}\n{$summary}",
        ];
    }

    // ───────────────────────── Tools baca-saja (laporan) ─────────────────────────

    private function toolRingkasPengeluaran(array $input): string
    {
        [$from, $to, $label] = $this->periodeRange((string) ($input['periode'] ?? 'bulan_ini'));

        $q = CashDisbursement::where('status', 'posted')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $total = (float) $q->sum('total');
        $count = (clone $q)->count();

        return "Pengeluaran {$label}: Rp " . number_format($total, 0, ',', '.') . " dari {$count} transaksi.";
    }

    private function toolSaldoKasBank(array $input): string
    {
        $kw = trim((string) ($input['keyword'] ?? ''));

        $q = Account::where('is_cash_account', 1)->where('is_active', 1);
        if ($kw !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$kw}%")->orWhere('code', 'like', "%{$kw}%"));
        }
        $accs = $q->orderBy('code')->get();

        if ($accs->isEmpty()) {
            return "Tidak ada rekening kas/bank yang cocok dengan '{$kw}'.";
        }

        $lines = [];
        foreach ($accs as $a) {
            $bal = (float) JournalLine::where('account_id', $a->id)
                ->whereHas('journal', fn ($j) => $j->where('status', 'posted'))
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as b')
                ->value('b');
            $lines[] = "{$a->name}: Rp " . number_format($bal, 0, ',', '.');
        }

        return implode("\n", $lines);
    }

    /** @return array{0:\Illuminate\Support\Carbon,1:\Illuminate\Support\Carbon,2:string} */
    private function periodeRange(string $p): array
    {
        $t = now();
        return match ($p) {
            'hari_ini'   => [$t->copy()->startOfDay(),   $t->copy()->endOfDay(),   'hari ini'],
            'kemarin'    => [$t->copy()->subDay()->startOfDay(), $t->copy()->subDay()->endOfDay(), 'kemarin'],
            'minggu_ini' => [$t->copy()->startOfWeek(),  $t->copy()->endOfWeek(),  'minggu ini'],
            'bulan_lalu' => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth(), 'bulan lalu'],
            default      => [$t->copy()->startOfMonth(), $t->copy()->endOfMonth(), 'bulan ini'],
        };
    }

    // ───────────────────────── Sales Order (buat → PDF → post) & DP ─────────────────────────

    private function toolCariPelanggan(array $input): string
    {
        $kw = trim((string) ($input['keyword'] ?? ''));

        $rows = Customer::query()
            ->where('is_active', 1)
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$kw}%")->orWhere('code', 'like', "%{$kw}%")))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'code']);

        if ($rows->isEmpty()) {
            return "Tidak ada pelanggan cocok dengan '{$kw}'. Tawarkan buat_pelanggan bila ini pelanggan baru.";
        }

        return $rows->map(fn ($c) => "id={$c->id} | {$c->name}" . ($c->code ? " ({$c->code})" : ''))->implode("\n");
    }

    private function toolBuatPelanggan(array $input): string
    {
        $nama = trim((string) ($input['nama'] ?? ''));
        if ($nama === '') {
            return 'Gagal: nama pelanggan wajib diisi.';
        }

        // Hindari duplikat nama persis.
        if ($dup = Customer::where('name', $nama)->where('is_active', 1)->first()) {
            return "Pelanggan '{$nama}' sudah ada (id={$dup->id}). Pakai yang ini saja.";
        }

        $cust = Customer::create([
            'code'      => 'CUST-' . time(),
            'name'      => $nama,
            'phone'     => trim((string) ($input['telepon'] ?? '')) ?: null,
            'address'   => trim((string) ($input['alamat'] ?? '')) ?: null,
            'is_active' => 1,
        ]);

        return "Pelanggan baru dibuat: id={$cust->id} | {$cust->name}. Lanjut buat SO dengan customer_id={$cust->id}.";
    }

    private function toolCariProduk(array $input): string
    {
        $kw = trim((string) ($input['keyword'] ?? ''));

        $rows = Product::query()
            ->where('is_active', 1)
            ->where('is_sellable', true)
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$kw}%")->orWhere('sku', 'like', "%{$kw}%")))
            ->orderBy('name')->limit(10)->get();

        if ($rows->isEmpty()) {
            return "Tidak ada produk 'Dijual' yang cocok dengan '{$kw}'.";
        }

        return $rows->map(function ($p) {
            $harga  = 'Rp ' . number_format((float) ($p->display_price ?? 0), 0, ',', '.');
            $custom = in_array($p->sale_type, ['custom', 'preorder'], true)
                ? ' | CUSTOM (minta nama/spesifikasi produk ke user utk field description item)'
                : '';
            return "id={$p->id} | " . ($p->sku ? "{$p->sku} " : '') . "{$p->name} | harga {$harga}"
                . ($p->base_unit ? " | satuan {$p->base_unit}" : '') . $custom;
        })->implode("\n");
    }

    private function toolCekHargaProduk(array $input): string
    {
        $kw  = trim((string) ($input['keyword'] ?? ''));
        $qty = max(1.0, (float) ($input['qty'] ?? 1));
        if ($kw === '') {
            return 'Sebutkan SKU atau nama produk yang mau dicek harganya.';
        }

        // Prioritas: cocok SKU persis → kalau tidak, cari mirip (SKU/nama).
        $product = Product::where('is_active', 1)->where('sku', $kw)->first();
        if (! $product) {
            $rows = Product::where('is_active', 1)
                ->where(fn ($w) => $w->where('sku', 'like', "%{$kw}%")->orWhere('name', 'like', "%{$kw}%"))
                ->orderBy('name')->limit(8)->get();

            if ($rows->isEmpty()) {
                return "Produk '{$kw}' tidak ditemukan.";
            }
            if ($rows->count() > 1) {
                return "Ada beberapa produk cocok '{$kw}', sebutkan yang mana (pakai SKU):\n"
                    . $rows->map(fn ($p) => '• ' . ($p->sku ? "{$p->sku} — " : '') . $p->name)->implode("\n");
            }
            $product = $rows->first();
        }

        $base = (float) ($product->display_price ?? 0);

        // Diskon dari promo item aktif (auto-apply). qty menyesuaikan (nominal = per unit).
        $m = null;
        try {
            $promoMap = app(PromotionService::class)->resolveItemDiscounts([
                ['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $base],
            ]);
            $m = $promoMap[$product->id] ?? null;
        } catch (\Throwable $e) {
            // Kalau modul promo error, tetap tampilkan harga tanpa diskon.
        }

        $disc     = $m ? (float) ($m['discount_amount'] ?? 0) : 0.0;   // total untuk qty
        $subtotal = round($base * $qty, 2);
        $total    = max(0, round($subtotal - $disc, 2));
        $rp       = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $label    = ($product->sku ? "{$product->sku} — " : '') . $product->name;
        $promoNm  = $m['promotion_name'] ?? null;

        if ($qty > 1) {
            $out  = "{$label} (× " . rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') . ")\n";
            $out .= 'Harga satuan: ' . $rp($base) . "\n";
            $out .= 'Subtotal: ' . $rp($subtotal) . "\n";
        } else {
            $out  = "{$label}\n";
            $out .= 'Harga utama: ' . $rp($base) . "\n";
        }
        $out .= $disc > 0
            ? 'Diskon' . ($promoNm ? " ({$promoNm})" : '') . ': -' . $rp($disc) . "\n"
            : "Diskon: tidak ada promo aktif\n";
        $out .= 'Harga total: ' . $rp($total);

        return $out;
    }

    private function toolCariSo(array $input): string
    {
        $kw = trim((string) ($input['keyword'] ?? ''));

        $rows = SalesOrder::with('customer')
            ->where('status', 'confirmed')
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$kw}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$kw}%"))))
            ->orderByDesc('order_date')->limit(10)->get()
            ->filter(fn ($so) => round((float) $so->grand_total - (float) $so->paid_amount, 2) > 0)
            ->values();

        if ($rows->isEmpty()) {
            return 'Tidak ada SO ter-post (confirmed) dengan sisa tagihan' . ($kw !== '' ? " cocok '{$kw}'" : '') . '.';
        }

        return $rows->map(function ($so) {
            $sisa = round((float) $so->grand_total - (float) $so->paid_amount, 2);
            return "so_id={$so->id} | {$so->order_number} | " . ($so->customer->name ?? '-')
                . ' | sisa Rp ' . number_format($sisa, 0, ',', '.');
        })->implode("\n");
    }

    /** Bentuk dto item dari input tool → array untuk SalesOrderService. */
    private function mapSoItems(array $input): array
    {
        $items = [];
        foreach ((array) ($input['items'] ?? []) as $it) {
            if (empty($it['product_id'])) continue;
            $items[] = [
                'product_id'     => (int) $it['product_id'],
                'qty'            => (float) ($it['qty'] ?? 0),
                'unit_price'     => $it['unit_price'] ?? 0,
                'discount_type'  => ($it['discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal',
                'discount_value' => $it['discount_value'] ?? 0,
                'description'    => trim((string) ($it['description'] ?? '')) ?: null,
            ];
        }
        return $items;
    }

    /** Susun dto pengiriman (metode + kurir manual + ongkir + diskon) dari input tool. */
    private function mapSoShipping(array $input): array
    {
        $method = ($input['metode_pengiriman'] ?? 'kurir') === 'ambil_toko' ? 'ambil_toko' : 'kurir';
        $dto = ['delivery_method' => $method];
        if ($method === 'kurir') {
            $dto['courier_name']            = trim((string) ($input['kurir'] ?? '')) ?: null;
            $dto['shipping_gross']          = $input['ongkir'] ?? 0;
            $dto['shipping_discount_type']  = ($input['diskon_ongkir_tipe'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
            $dto['shipping_discount_value'] = $input['diskon_ongkir_nilai'] ?? 0;
        }
        return $dto;
    }

    private function toolBuatSoDraft(string $chatId, array $input): array
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        if (! $customerId || ! Customer::find($customerId)) {
            return ['content' => 'Gagal: customer_id tidak valid. Pakai cari_pelanggan / buat_pelanggan dulu.'];
        }
        $items = $this->mapSoItems($input);
        if (empty($items)) {
            return ['content' => 'Gagal: minimal satu item (product_id + qty) diperlukan. Pakai cari_produk dulu.'];
        }

        $dto = array_merge([
            'customer_id'           => $customerId,
            'notes'                 => trim((string) ($input['catatan'] ?? '')) ?: null,
            'global_discount_type'  => ($input['diskon_total_tipe'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal',
            'global_discount_value' => $input['diskon_total_nilai'] ?? 0,
            'items'                 => $items,
        ], $this->mapSoShipping($input));

        try {
            $so = $this->soService->createDraftFromData($dto);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal membuat SO: ' . $e->getMessage()];
        }

        Cache::put($this->soKey($chatId), $so->id, $this->soTtl());

        $pdfNote = $this->sendSoPdf($chatId, $so, "📄 Draft {$so->order_number} — silakan diteruskan ke pembeli. Balas <b>post</b> bila sudah oke.");

        return ['content' => "SO draft dibuat (id={$so->id}, nomor {$so->order_number}).\n"
            . $this->soSummary($so) . "\n{$pdfNote}\n"
            . 'Sampaikan ke user bahwa PDF sudah dikirim & bisa diteruskan ke pembeli; jangan di-post sebelum user bilang "post".'];
    }

    private function toolEditSoDraft(string $chatId, array $input): array
    {
        $soId = (int) ($input['so_id'] ?? 0) ?: (int) Cache::get($this->soKey($chatId), 0);
        if (! $soId) {
            return ['content' => 'Gagal: tidak ada SO aktif untuk diedit. Sebutkan so_id atau buat SO dulu.'];
        }

        $dto = [];
        if (array_key_exists('items', $input)) {
            $dto['items'] = $this->mapSoItems($input);
        }
        if (array_key_exists('catatan', $input)) {
            $dto['notes'] = trim((string) $input['catatan']) ?: null;
        }
        if (array_key_exists('customer_id', $input) && (int) $input['customer_id'] > 0) {
            $dto['customer_id'] = (int) $input['customer_id'];
        }
        if (array_key_exists('diskon_total_nilai', $input) || array_key_exists('diskon_total_tipe', $input)) {
            $dto['global_discount_type']  = ($input['diskon_total_tipe'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
            $dto['global_discount_value'] = $input['diskon_total_nilai'] ?? 0;
        }
        if (array_key_exists('metode_pengiriman', $input) || array_key_exists('kurir', $input) || array_key_exists('ongkir', $input)) {
            $dto = array_merge($dto, $this->mapSoShipping($input));
        }

        try {
            $so = $this->soService->updateDraftFromData($soId, $dto);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal mengedit SO: ' . $e->getMessage()];
        }

        Cache::put($this->soKey($chatId), $so->id, $this->soTtl());

        $pdfNote = $this->sendSoPdf($chatId, $so, "📄 Revisi {$so->order_number} — versi terbaru untuk diteruskan ke pembeli.");

        return ['content' => "SO {$so->order_number} diperbarui.\n" . $this->soSummary($so) . "\n{$pdfNote}"];
    }

    private function toolPostSo(string $chatId, array $input): array
    {
        $soId = (int) ($input['so_id'] ?? 0) ?: (int) Cache::get($this->soKey($chatId), 0);
        if (! $soId) {
            return ['content' => 'Gagal: tidak ada SO aktif untuk di-post. Sebutkan so_id.'];
        }

        $so = SalesOrder::find($soId);
        if (! $so) {
            return ['content' => "Gagal: SO id={$soId} tidak ditemukan."];
        }
        if ($so->status !== 'draft') {
            return ['content' => "SO {$so->order_number} statusnya bukan draft (sekarang: {$so->status}), tidak bisa di-post lagi."];
        }

        try {
            $this->soService->confirm($so->id);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal post SO: ' . $e->getMessage()];
        }

        $so->refresh();
        $extra = $so->delivery_method === 'ambil_toko' && $so->pickup_code
            ? "\nKode ambil di toko: <b>{$so->pickup_code}</b>."
            : '';

        return [
            'content'         => "SO {$so->order_number} berhasil di-post (dikonfirmasi, stok direservasi).{$extra}",
            '_posted_summary' => "✅ <b>SO diposting</b>: {$so->order_number}\n" . $this->soSummary($so) . $extra,
        ];
    }

    private function toolCatatDp(string $chatId, array $input): array
    {
        $soId    = (int) ($input['so_id'] ?? 0) ?: (int) Cache::get($this->soKey($chatId), 0);
        $nominal = round((float) clean_number($input['nominal'] ?? 0), 2);
        $kasId   = (int) ($input['kas_account_id'] ?? 0);
        $tgl     = trim((string) ($input['tanggal'] ?? '')) ?: now()->format('Y-m-d');
        $notes   = trim((string) ($input['catatan'] ?? '')) ?: null;

        $so = SalesOrder::find($soId);
        if (! $so) {
            return ['content' => 'Gagal: SO tidak ditemukan. Sebutkan so_id yang benar.'];
        }
        if ($so->status !== 'confirmed') {
            return ['content' => "Gagal: SO {$so->order_number} harus di-post (confirmed) dulu sebelum DP dicatat. Statusnya sekarang: {$so->status}."];
        }
        $sisa = round((float) $so->grand_total - (float) $so->paid_amount, 2);
        if ($sisa <= 0) {
            return ['content' => "SO {$so->order_number} sudah lunas/tidak ada sisa tagihan. Tidak perlu DP."];
        }
        if ($nominal <= 0) {
            return ['content' => 'Gagal: nominal DP harus lebih dari 0.'];
        }
        if ($nominal > $sisa + 0.01) {
            return ['content' => 'Gagal: nominal DP (Rp ' . number_format($nominal, 0, ',', '.') . ') melebihi sisa tagihan SO (Rp ' . number_format($sisa, 0, ',', '.') . ').'];
        }

        $kas = Account::find($kasId);
        if (! $kas) {
            return ['content' => "Gagal: rekening id={$kasId} tidak ditemukan. Pakai cari_akun jenis=kas_bank."];
        }
        if (! (int) $kas->is_cash_account) {
            return ['content' => "Gagal: {$kas->name} bukan rekening kas/bank."];
        }

        try {
            $payment = $this->paymentService->create([
                'customer_id'     => $so->customer_id,
                'date'            => $tgl,
                'cash_account_id' => $kas->id,
                'amount'          => $nominal,
                'payment_type'    => 'advance',
                'sales_order_id'  => $so->id,
                'notes'           => $notes,
            ]);
        } catch (\Throwable $e) {
            return ['content' => 'Gagal membuat draft DP: ' . $e->getMessage()];
        }

        $rp      = 'Rp ' . number_format($nominal, 0, ',', '.');
        $cust    = $so->customer->name ?? '-';
        $summary = "DP {$so->order_number} ({$cust})\n{$rp} → {$kas->name} · {$tgl}";

        if ($nominal > $this->threshold()) {
            Cache::put($this->pendingKey($chatId), [
                'kind'    => 'dp',
                'id'      => $payment->id,
                'so_id'   => $so->id,
                'number'  => $payment->payment_number,
                'summary' => $summary,
            ], $this->ttl());

            return [
                '_halt'   => true,
                'message' => '⚠️ Perlu konfirmasi (di atas ' . $this->thresholdLabel() . "):\n\n{$summary}\n\n"
                    . 'Balas <b>ya</b> untuk posting DP atau <b>batal</b> untuk membatalkan.',
            ];
        }

        try {
            $this->paymentService->post($payment->id, null, [], [$so->id], false);
        } catch (\Throwable $e) {
            return ['content' => 'Draft DP dibuat tapi gagal posting: ' . $e->getMessage()];
        }

        return [
            'content'         => "DP diposting (nomor {$payment->payment_number}). {$summary}",
            '_posted_summary' => "✅ <b>DP diposting</b>: {$payment->payment_number}\n{$summary}",
        ];
    }

    /** Render & kirim PDF SO ke chat. Return catatan status (untuk konteks model). */
    private function sendSoPdf(string $chatId, SalesOrder $so, string $caption): string
    {
        try {
            $pdf = $this->soPdfService->render($so);
            $ok  = $this->telegram->sendDocument($chatId, $pdf, $this->soPdfService->filename($so), $caption);
            return $ok ? '(PDF SO sudah dikirim ke Telegram.)'
                       : '(Catatan: PDF gagal dikirim ke Telegram — mungkin bot belum diset. Sampaikan ini ke user.)';
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gagal render PDF SO utk Telegram: ' . $e->getMessage());
            return '(Catatan: PDF tidak bisa dibuat saat ini. SO tetap tersimpan; user bisa cetak dari ERP.)';
        }
    }

    /** Ringkasan SO untuk balasan Telegram (HTML). */
    private function soSummary(SalesOrder $so): string
    {
        $so->loadMissing('items', 'customer');
        $lines = [];
        foreach ($so->items as $it) {
            $nama = $it->description ?: ($it->product->name ?? 'Item');
            $lines[] = '• ' . $nama . ' × ' . rtrim(rtrim(number_format((float) $it->qty, 2, ',', '.'), '0'), ',')
                . ' = Rp ' . number_format((float) $it->line_total, 0, ',', '.');
        }
        $kirim = $so->delivery_method === 'ambil_toko'
            ? 'Ambil di toko'
            : ('Kurir' . ($so->shipping_service_name ? ' ' . $so->shipping_service_name : '')
                . ' · ongkir Rp ' . number_format((float) $so->shipping_cost, 0, ',', '.'));

        return 'Pelanggan: ' . ($so->customer->name ?? '-') . "\n"
            . implode("\n", $lines) . "\n"
            . "Pengiriman: {$kirim}\n"
            . 'Total: <b>Rp ' . number_format((float) $so->grand_total, 0, ',', '.') . '</b>';
    }

    // ───────────────────────── Konfirmasi & void ─────────────────────────

    private function resolvePending(string $chatId, string $text, array $pending): string
    {
        $t   = strtolower(trim($text));
        $yes = ['ya', 'iya', 'y', 'ok', 'oke', 'lanjut', 'betul', 'benar', 'sip', 'gas', 'setuju', 'yoi'];
        $no  = ['batal', 'tidak', 'gak', 'ga', 'engga', 'enggak', 'no', 'jangan', 'stop', 'cancel'];

        $isYes = in_array($t, $yes, true);
        $isNo  = in_array($t, $no, true);

        if (! $isYes && ! $isNo) {
            return "❓ Masih ada transaksi menunggu konfirmasi:\n\n{$pending['summary']}\n\n"
                . 'Balas <b>ya</b> untuk posting atau <b>batal</b> untuk membatalkan.';
        }

        $this->forgetPending($chatId);
        $kind = $pending['kind'] ?? 'cd';

        // DP (uang muka) punya alur post tersendiri (butuh SO id) → tangani terpisah.
        if ($kind === 'dp') {
            return $this->resolvePendingDp($chatId, $isYes, $pending);
        }

        $doc = $this->findDoc($kind, (int) ($pending['id'] ?? 0));

        if (! $doc || ! $doc->isDraft()) {
            $this->forgetConversation($chatId);
            return 'Transaksi sudah tidak bisa diproses (mungkin sudah berubah). Silakan ulangi.';
        }

        if ($isNo) {
            $this->deleteDraft($kind, $doc);
            $this->forgetConversation($chatId);
            return '❌ Dibatalkan. Tidak ada yang dibukukan.';
        }

        try {
            $this->postDoc($kind, $doc);
        } catch (\Throwable $e) {
            return '❌ Gagal posting: ' . $e->getMessage();
        }

        Cache::put($this->lastKey($chatId), ['kind' => $kind, 'id' => $doc->id], $this->ttl());
        $this->forgetConversation($chatId);

        return "✅ <b>Diposting</b>: {$doc->number}\n{$pending['summary']}\n\n↩️ /batal untuk membatalkan.";
    }

    /** Konfirmasi DP: post lewat CustomerPaymentService (butuh SO id). Tidak wire /batal (void DP di ERP). */
    private function resolvePendingDp(string $chatId, bool $isYes, array $pending): string
    {
        $payment = CustomerPayment::find((int) ($pending['id'] ?? 0));

        if (! $payment || $payment->status !== 'draft') {
            $this->forgetConversation($chatId);
            return 'DP sudah tidak bisa diproses (mungkin sudah berubah). Silakan ulangi.';
        }

        if (! $isYes) {
            $payment->delete();
            $this->forgetConversation($chatId);
            return '❌ Dibatalkan. DP tidak dibukukan.';
        }

        try {
            $this->paymentService->post($payment->id, null, [], [(int) ($pending['so_id'] ?? 0)], false);
        } catch (\Throwable $e) {
            return '❌ Gagal posting DP: ' . $e->getMessage();
        }

        $this->forgetConversation($chatId);

        return "✅ <b>DP diposting</b>: {$payment->payment_number}\n{$pending['summary']}\n\n"
            . '<i>Untuk membatalkan DP, void di menu Pembayaran ERP.</i>';
    }

    private function voidLast(string $chatId): string
    {
        $last = Cache::get($this->lastKey($chatId));
        if (! $last || empty($last['id'])) {
            return 'Tidak ada transaksi terakhir untuk dibatalkan (atau sudah kedaluwarsa). '
                . 'Batalkan manual di menu terkait bila perlu.';
        }

        $kind = $last['kind'] ?? 'cd';
        $doc  = $this->findDoc($kind, (int) $last['id']);
        if (! $doc) {
            return 'Transaksi tidak ditemukan.';
        }
        if ($doc->isVoid()) {
            return "Transaksi {$doc->number} sudah di-void.";
        }
        if (! $doc->canBeVoided()) {
            return "Transaksi {$doc->number} tidak bisa di-void.";
        }

        try {
            $this->voidDoc($kind, $doc);
            $this->forgetLast($chatId);
            return "↩️ <b>Dibatalkan (void)</b>: {$doc->number}. Jurnal sudah dibalik.";
        } catch (\Throwable $e) {
            return 'Gagal void: ' . $e->getMessage();
        }
    }

    /** Cari dokumen draft/posted berdasarkan jenis (cd = Pengeluaran, transfer = Bank Transfer). */
    private function findDoc(string $kind, int $id)
    {
        return $kind === 'transfer' ? BankTransfer::find($id) : CashDisbursement::find($id);
    }

    private function postDoc(string $kind, $doc): void
    {
        $kind === 'transfer' ? $this->btService->post($doc) : $this->cdService->post($doc);
    }

    private function voidDoc(string $kind, $doc): void
    {
        $kind === 'transfer' ? $this->btService->void($doc) : $this->cdService->void($doc);
    }

    private function deleteDraft(string $kind, $doc): void
    {
        if ($kind === 'cd') {
            $doc->lines()->delete();
        }
        $doc->delete();
    }

    // ───────────────────────── Prompt & tool schema ─────────────────────────

    private function systemPrompt(User $user): string
    {
        $today = now()->format('Y-m-d');
        $nama  = $user->name ?: 'Pengguna';
        $amb   = $this->thresholdLabel();

        return <<<TXT
Kamu "Noud Bot", asisten pencatat keuangan untuk Noud Acrylic di dalam ERP. Kamu ngobrol santai dalam Bahasa Indonesia dan membantu {$nama} mencatat transaksi lewat Telegram.

Tanggal hari ini: {$today}.

KEMAMPUAN SAAT INI:
1. PENGELUARAN UMUM & PRIVE — uang keluar untuk biaya/beban atau pengambilan pribadi pemilik (pakai catat_pengeluaran).
2. TRANSFER ANTAR REKENING kas/bank SENDIRI — mis. pindah dana BCA → Mandiri (pakai catat_transfer_bank).
3. BAYAR ONGKIR (titipan per faktur) — pelunasan titipan ongkir sebuah faktur penjualan ke kurir (cari_faktur_ongkir → catat_bayar_ongkir). Hanya untuk SATU faktur; banyak faktur sekaligus → arahkan ke menu Bayar Ongkir di ERP.
4. BACA FOTO STRUK/NOTA — baca total, tanggal, dan keterangannya, lalu catat sebagai PENGELUARAN (alur sama: cari akun beban yang sesuai, tanya sumber kas bila belum jelas, lalu catat_pengeluaran).
5. JAWAB pertanyaan ringkas — total pengeluaran periode (ringkas_pengeluaran), saldo kas/bank (saldo_kas_bank), & CEK HARGA PRODUK (cek_harga_produk: harga utama + diskon promo + harga total dari SKU/nama). Cukup panggil tool lalu sampaikan hasilnya; tidak ada yang diposting.
6. BUAT PESANAN PENJUALAN (SO) → PDF → POST → DP:
   a. Cari/buat pelanggan (cari_pelanggan → kalau tak ada, buat_pelanggan) dan cari produk (cari_produk) untuk dapat id + harga.
   b. buat_so_draft → membuat SO DRAFT lalu OTOMATIS mengirim PDF-nya ke chat ini. Sampaikan bahwa PDF sudah dikirim & bisa diteruskan ke pembeli. Kalau harga tidak disebut, pakai harga jual produk.
      • PRODUK CUSTOM: banyak barang dijual lewat produk custom (mis. SKU "Custom 6"/"CS 6") yang namanya diganti per pesanan. Kalau user pilih produk custom (ditandai CUSTOM di hasil cari_produk) atau menyebut nama/spesifikasi produk spesifik, ISI field `description` item dengan nama itu — mis. "Akrilik Sign Dilarang Merokok 3mm 16x30". Nama ini yang muncul di SO & PDF (menggantikan nama master). Untuk mengganti nama item belakangan, pakai edit_so_draft (kirim ulang items dengan description baru). Produk biasa: description dikosongkan.
   c. Revisi: bila user minta ubah (qty/item/ongkir/diskon), pakai edit_so_draft (PDF dikirim ulang). Bila mengirim daftar item, kirim SELURUH item versi final.
   d. POST: JANGAN mem-post sendiri. Hanya panggil post_so kalau user secara eksplisit bilang "post"/"konfirmasi"/"oke post". Sebelum itu biarkan tetap draft supaya user bisa teruskan PDF & minta revisi.
   e. DP/uang muka: setelah SO di-post & pembeli bayar, user meneruskan bukti transfer (teks atau FOTO). Baca nominalnya, tentukan rekening kas/bank tujuan via cari_akun jenis=kas_bank, lalu catat_dp. Kalau SO-nya bukan yang terakhir dibuat / lupa, pakai cari_so untuk temukan so_id-nya.

Pengiriman SO: hanya "kurir" (kurir MANUAL — user isi nama kurir spt "JNT Cargo" + ongkir + diskon ongkir; TIDAK ada cek ongkir otomatis) atau "ambil_toko". Metode "instant" BELUM didukung — kalau user minta instant, jelaskan belum ada & tawarkan kurir manual atau ambil di toko.

Untuk refund customer & pembelian barang dagangan/stok dari supplier (yang menambah stok): katakan itu dicatat manual di ERP, belum didukung lewat chat — jangan dipaksakan.

Bedakan dengan jelas:
- Uang keluar ke PIHAK LAIN / jadi biaya (bensin, gaji, listrik, sewa, prive) → catat_pengeluaran.
- Uang pindah ANTAR REKENING SENDIRI (tidak jadi biaya) → catat_transfer_bank.
- BAYAR ONGKIR yang terkait FAKTUR/pelanggan (pelepasan titipan) → cari_faktur_ongkir + catat_bayar_ongkir. User boleh menyebut NAMA PEMESAN/PELANGGAN (tidak harus nomor faktur). Jika hasil cari_faktur_ongkir lebih dari satu, TAMPILKAN daftarnya (pelanggan, nomor faktur, titipan, tanggal/resi) dan minta user memilih yang mana. Ongkir lain yang murni biaya umum tanpa faktur → catat_pengeluaran ke akun beban. Kalau ragu, tanya user dulu.
- Foto struk pengeluaran → baca lalu catat_pengeluaran. Bila struk jelas pembelian barang untuk stok dari supplier, sampaikan dicatat manual di ERP.

ATURAN:
- SELALU pakai tool cari_akun untuk menemukan id akun dari kata user. JANGAN mengarang id akun.
  • biaya/beban (mis. "bensin", "makan", "listrik") → cari_akun jenis=beban.
  • "prive"/"ambil pribadi"/"buat pemilik" → cari_akun jenis=equity (cari "Prive").
  • rekening kas/bank ("kas", "kas kecil", "bca", "bri", "mandiri") → cari_akun jenis=kas_bank.
- Pengeluaran: jika SUMBER kas/bank belum disebut user, TANYAKAN dulu — jangan menebak.
- Transfer: butuh rekening SUMBER dan TUJUAN; kalau salah satu belum jelas, tanyakan. Biaya admin opsional (sebutkan jika user menyebut, mis. "admin 6500").
- Jika hasil cari_akun lebih dari satu yang relevan dan ambigu, tanyakan user mana yang dimaksud.
- Jika akun Prive tidak ditemukan, beri tahu user agar dibuat dulu di Bagan Akun.
- Parse nominal Indonesia: "20rb"/"20k"/"20.000" = 20000; "1,5jt"/"1.5jt" = 1500000.
- Nominal di atas {$amb} otomatis butuh konfirmasi user (sistem yang menangani setelah kamu memanggil tool catat/DP) — kamu tidak perlu minta konfirmasi sendiri. (Membuat SO draft & mem-post SO TIDAK pakai ambang; post hanya atas perintah user.)
- SO: JANGAN mengarang customer_id atau product_id — selalu lewat cari_pelanggan/buat_pelanggan & cari_produk. Konfirmasikan ringkas isi pesanan sebelum buat_so_draft bila ada yang ambigu.
- DP hanya untuk SO yang sudah confirmed (di-post). Kalau user mau DP tapi SO masih draft, ingatkan untuk post dulu.
- Setelah semua id akun & nominal jelas, panggil tool yang sesuai. Balas singkat, ramah, dan to the point.
TXT;
    }

    private function tools(): array
    {
        return [
            [
                'name'        => 'cari_akun',
                'description' => 'Cari akun di Bagan Akun (COA) berdasarkan kata kunci untuk menemukan id-nya '
                    . 'sebelum mencatat. Selalu pakai id dari hasil pencarian; jangan mengarang id.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => [
                            'type'        => 'string',
                            'description' => 'Kata kunci nama akun, mis. "bensin", "prive", "kas", "bca".',
                        ],
                        'jenis' => [
                            'type'        => 'string',
                            'enum'        => ['beban', 'kas_bank', 'equity'],
                            'description' => 'beban=akun biaya/beban; kas_bank=sumber dana kas/bank; equity=akun ekuitas spt Prive.',
                        ],
                    ],
                    'required' => ['keyword', 'jenis'],
                ],
            ],
            [
                'name'        => 'catat_pengeluaran',
                'description' => 'Catat satu pengeluaran umum/prive ke ERP (Debit akun beban/ekuitas, Kredit akun '
                    . 'kas/bank). Panggil HANYA setelah semua id akun jelas dari cari_akun dan nominal pasti. '
                    . 'Jika sumber kas/bank belum disebut user, tanya dulu — jangan menebak.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'akun_beban_id'  => ['type' => 'integer', 'description' => 'id akun yang didebit (beban/biaya atau Prive).'],
                        'kas_account_id' => ['type' => 'integer', 'description' => 'id akun kas/bank sumber dana (yang dikredit).'],
                        'nominal'        => ['type' => 'number', 'description' => 'Nominal rupiah, angka bulat (mis. 20000).'],
                        'keterangan'     => ['type' => 'string', 'description' => 'Keterangan singkat transaksi.'],
                        'tanggal'        => ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD. Kosongkan untuk hari ini.'],
                    ],
                    'required' => ['akun_beban_id', 'kas_account_id', 'nominal', 'keterangan'],
                ],
            ],
            [
                'name'        => 'catat_transfer_bank',
                'description' => 'Catat pemindahan dana antar rekening kas/bank SENDIRI (Dr rekening tujuan / '
                    . 'Cr rekening sumber). Panggil setelah id rekening sumber & tujuan jelas via cari_akun '
                    . '(jenis=kas_bank). BUKAN untuk membayar ke pihak lain — itu pakai catat_pengeluaran.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'dari_account_id' => ['type' => 'integer', 'description' => 'id rekening kas/bank sumber (berkurang).'],
                        'ke_account_id'   => ['type' => 'integer', 'description' => 'id rekening kas/bank tujuan (bertambah).'],
                        'nominal'         => ['type' => 'number', 'description' => 'Nominal transfer, angka bulat.'],
                        'biaya_admin'     => ['type' => 'number', 'description' => 'Biaya admin bank bila ada (opsional, default 0; ditanggung rekening sumber).'],
                        'keterangan'      => ['type' => 'string', 'description' => 'Keterangan singkat (opsional).'],
                        'tanggal'         => ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD. Kosongkan untuk hari ini.'],
                    ],
                    'required' => ['dari_account_id', 'ke_account_id', 'nominal'],
                ],
            ],
            [
                'name'        => 'cari_faktur_ongkir',
                'description' => 'Cari faktur penjualan yang punya titipan ongkir & BELUM dibayar ongkirnya, '
                    . 'berdasarkan NAMA PELANGGAN, nomor faktur, atau nomor resi/SJ. '
                    . 'Pakai sebelum catat_bayar_ongkir untuk dapat invoice_id dan nilai titipannya.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Nama pelanggan, nomor faktur, atau nomor resi (opsional; kosong = daftar terbaru).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'catat_bayar_ongkir',
                'description' => 'Catat pembayaran ongkir (pelepasan titipan) untuk SATU faktur: titipan 1203 dilepas '
                    . 'sebesar shipping_cost faktur, kas dikredit sebesar bayar aktual, selisih jadi untung/rugi otomatis. '
                    . 'Panggil setelah invoice_id jelas via cari_faktur_ongkir & sumber kas via cari_akun jenis=kas_bank. '
                    . 'Untuk banyak faktur sekaligus, arahkan user ke menu Bayar Ongkir di ERP.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'invoice_id'     => ['type' => 'integer', 'description' => 'id faktur dari cari_faktur_ongkir.'],
                        'bayar_aktual'   => ['type' => 'number', 'description' => 'Jumlah yang benar-benar dibayar ke kurir (angka bulat).'],
                        'kas_account_id' => ['type' => 'integer', 'description' => 'id rekening kas/bank sumber dana.'],
                        'tanggal'        => ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD, kosong = hari ini.'],
                    ],
                    'required' => ['invoice_id', 'bayar_aktual', 'kas_account_id'],
                ],
            ],
            [
                'name'        => 'ringkas_pengeluaran',
                'description' => 'Hitung total pengeluaran (kas keluar yang sudah diposting) pada periode tertentu. '
                    . 'Untuk menjawab pertanyaan seperti "pengeluaran hari ini/bulan ini berapa?".',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'periode' => [
                            'type'        => 'string',
                            'enum'        => ['hari_ini', 'kemarin', 'minggu_ini', 'bulan_ini', 'bulan_lalu'],
                            'description' => 'Periode yang diminta. Default bulan_ini.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'saldo_kas_bank',
                'description' => 'Lihat saldo rekening kas/bank saat ini. Untuk pertanyaan seperti "saldo kas berapa?" '
                    . 'atau "saldo BCA?". keyword opsional untuk memfilter rekening.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Filter nama rekening (opsional), mis. "bca". Kosong = semua.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'cari_pelanggan',
                'description' => 'Cari pelanggan/customer berdasarkan nama atau kode untuk mendapat customer_id sebelum membuat SO. '
                    . 'Bila tidak ada yang cocok, tawarkan buat_pelanggan.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Nama atau kode pelanggan.'],
                    ],
                    'required' => ['keyword'],
                ],
            ],
            [
                'name'        => 'buat_pelanggan',
                'description' => 'Buat pelanggan baru bila belum ada di database. Cukup nama; telepon & alamat opsional '
                    . '(bisa dilengkapi nanti di ERP). Pakai hanya setelah cari_pelanggan tidak menemukan yang cocok.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'nama'    => ['type' => 'string', 'description' => 'Nama pelanggan.'],
                        'telepon' => ['type' => 'string', 'description' => 'Nomor telepon/HP (opsional).'],
                        'alamat'  => ['type' => 'string', 'description' => 'Alamat (opsional).'],
                    ],
                    'required' => ['nama'],
                ],
            ],
            [
                'name'        => 'cari_produk',
                'description' => 'Cari produk yang boleh dijual (nama/SKU) untuk mendapat product_id, harga jual, & satuan '
                    . 'sebelum menambahkannya ke item SO.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Nama atau SKU produk.'],
                    ],
                    'required' => ['keyword'],
                ],
            ],
            [
                'name'        => 'buat_so_draft',
                'description' => 'Buat Sales Order (SO / Pesanan Penjualan) berstatus DRAFT lalu KIRIM PDF-nya ke chat '
                    . 'untuk diteruskan ke pembeli. JANGAN memposting — hanya draft. Panggil setelah customer_id (via '
                    . 'cari_pelanggan/buat_pelanggan) dan semua product_id (via cari_produk) jelas. unit_price default '
                    . 'ke harga jual produk bila user tidak menyebut harga. Gudang otomatis (gudang utama).',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'id pelanggan dari cari_pelanggan/buat_pelanggan.'],
                        'items' => [
                            'type'  => 'array',
                            'description' => 'Baris item SO.',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'product_id'     => ['type' => 'integer', 'description' => 'id produk dari cari_produk.'],
                                    'qty'            => ['type' => 'number', 'description' => 'Jumlah/kuantitas.'],
                                    'unit_price'     => ['type' => 'number', 'description' => 'Harga satuan. Kosong/0 = pakai harga jual produk.'],
                                    'discount_type'  => ['type' => 'string', 'enum' => ['nominal', 'percent'], 'description' => 'Tipe diskon per item (opsional).'],
                                    'discount_value' => ['type' => 'number', 'description' => 'Nilai diskon per item; nominal = per unit (opsional).'],
                                    'description'    => ['type' => 'string', 'description' => 'Nama produk custom / nama tampilan item untuk pesanan ini — MENGGANTIKAN nama master di SO & PDF. '
                                        . 'Isi bila user pilih produk custom (mis. SKU "Custom 6") lalu sebut nama sebenarnya, mis. "Akrilik Sign Dilarang Merokok 3mm 16x30". Kosongkan untuk produk biasa.'],
                                ],
                                'required' => ['product_id', 'qty'],
                            ],
                        ],
                        'metode_pengiriman'   => ['type' => 'string', 'enum' => ['kurir', 'ambil_toko'], 'description' => 'Metode kirim. Default kurir. "instant" belum didukung.'],
                        'kurir'               => ['type' => 'string', 'description' => 'Nama kurir manual bila metode=kurir, mis. "JNT Cargo", "JNE". Default "JNT Cargo".'],
                        'ongkir'              => ['type' => 'number', 'description' => 'Ongkir kotor (sebelum diskon) bila metode=kurir.'],
                        'diskon_ongkir_tipe'  => ['type' => 'string', 'enum' => ['nominal', 'percent'], 'description' => 'Tipe diskon ongkir (opsional, default nominal).'],
                        'diskon_ongkir_nilai' => ['type' => 'number', 'description' => 'Nilai diskon ongkir (opsional).'],
                        'diskon_total_tipe'   => ['type' => 'string', 'enum' => ['nominal', 'percent'], 'description' => 'Tipe diskon total belanja (opsional).'],
                        'diskon_total_nilai'  => ['type' => 'number', 'description' => 'Nilai diskon total belanja (opsional).'],
                        'catatan'             => ['type' => 'string', 'description' => 'Catatan SO (opsional).'],
                    ],
                    'required' => ['customer_id', 'items'],
                ],
            ],
            [
                'name'        => 'edit_so_draft',
                'description' => 'Revisi SO DRAFT yang barusan dibuat (mis. ganti qty, tambah/kurang item, ubah ongkir/diskon/catatan) '
                    . 'lalu kirim ulang PDF-nya. so_id opsional (default SO aktif terakhir di chat ini). Bila mengirim "items", '
                    . 'kirim SELURUH daftar item versi final (item lama diganti total).',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'so_id'       => ['type' => 'integer', 'description' => 'id SO yang diedit (opsional, default SO aktif terakhir).'],
                        'customer_id' => ['type' => 'integer', 'description' => 'Ganti pelanggan (opsional).'],
                        'items' => [
                            'type'  => 'array',
                            'description' => 'Daftar item FINAL (mengganti semua item lama). Kirim hanya bila item berubah.',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'product_id'     => ['type' => 'integer'],
                                    'qty'            => ['type' => 'number'],
                                    'unit_price'     => ['type' => 'number'],
                                    'discount_type'  => ['type' => 'string', 'enum' => ['nominal', 'percent']],
                                    'discount_value' => ['type' => 'number'],
                                    'description'    => ['type' => 'string', 'description' => 'Nama produk custom / nama tampilan item (mengganti nama master). Isi/ubah untuk produk custom.'],
                                ],
                                'required' => ['product_id', 'qty'],
                            ],
                        ],
                        'metode_pengiriman'   => ['type' => 'string', 'enum' => ['kurir', 'ambil_toko']],
                        'kurir'               => ['type' => 'string'],
                        'ongkir'              => ['type' => 'number'],
                        'diskon_ongkir_tipe'  => ['type' => 'string', 'enum' => ['nominal', 'percent']],
                        'diskon_ongkir_nilai' => ['type' => 'number'],
                        'diskon_total_tipe'   => ['type' => 'string', 'enum' => ['nominal', 'percent']],
                        'diskon_total_nilai'  => ['type' => 'number'],
                        'catatan'             => ['type' => 'string'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'post_so',
                'description' => 'POST / konfirmasi SO draft (reservasi stok). PANGGIL HANYA bila user secara eksplisit '
                    . 'menyuruh "post"/"konfirmasi"/"gas post". JANGAN otomatis mem-post setelah membuat draft — '
                    . 'user perlu meneruskan PDF ke pembeli & bisa minta revisi dulu. so_id opsional (default SO aktif).',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'so_id' => ['type' => 'integer', 'description' => 'id SO yang di-post (opsional, default SO aktif terakhir).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'cek_harga_produk',
                'description' => 'Cek harga jual sebuah produk berdasarkan SKU atau nama: harga utama, diskon promo aktif (bila ada), '
                    . 'dan harga total. Untuk pertanyaan seperti "harga produk TBKD-13-T3-M1 berapa?". qty opsional (default 1) '
                    . 'bila user tanya harga untuk sejumlah unit. Bila SKU/nama cocok ke banyak produk, hasilnya minta user memilih.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'SKU (diutamakan) atau nama produk.'],
                        'qty'     => ['type' => 'number', 'description' => 'Jumlah unit (opsional, default 1).'],
                    ],
                    'required' => ['keyword'],
                ],
            ],
            [
                'name'        => 'cari_so',
                'description' => 'Cari Sales Order yang SUDAH di-post (confirmed) & masih punya sisa tagihan, berdasarkan nama '
                    . 'pelanggan atau nomor SO. Pakai untuk menemukan so_id sebelum catat_dp — terutama bila DP masuk '
                    . 'belakangan / di sesi berbeda dari saat SO dibuat.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Nama pelanggan atau nomor SO (opsional; kosong = daftar terbaru).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'catat_dp',
                'description' => 'Catat DP / uang muka pembeli untuk sebuah SO yang SUDAH di-post (confirmed). Kredit akun Uang Muka '
                    . 'Penjualan, debit kas/bank. Sumber DP dari bukti transfer yang diteruskan user (baca nominal dari foto '
                    . 'bila ada). Panggil setelah so_id jelas & kas via cari_akun jenis=kas_bank. Nominal di atas ambang '
                    . 'ditahan untuk konfirmasi otomatis oleh sistem.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'so_id'          => ['type' => 'integer', 'description' => 'id SO tujuan DP (opsional, default SO aktif terakhir).'],
                        'nominal'        => ['type' => 'number', 'description' => 'Nominal DP yang diterima (angka bulat).'],
                        'kas_account_id' => ['type' => 'integer', 'description' => 'id rekening kas/bank penerima DP (via cari_akun jenis=kas_bank).'],
                        'tanggal'        => ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD, kosong = hari ini.'],
                        'catatan'        => ['type' => 'string', 'description' => 'Catatan (opsional).'],
                    ],
                    'required' => ['nominal', 'kas_account_id'],
                ],
            ],
        ];
    }

    private function helpText(): string
    {
        return "🤖 <b>Noud Bot — Pencatat Keuangan</b>\n\n"
            . "Ketik perintah biasa, mis:\n"
            . "• <i>catat pengeluaran bensin 50rb dari kas</i>\n"
            . "• <i>prive 200rb dari BCA</i>\n"
            . "• <i>transfer 5jt dari BCA ke Mandiri</i>\n"
            . "• <i>bayar ongkir pesanan Tama 42rb dari BRI</i> (boleh sebut nama pemesan)\n"
            . "• <i>pengeluaran bulan ini berapa?</i> / <i>saldo BCA?</i>\n"
            . "• <i>harga produk TBKD-13-T3-M1 berapa?</i> (harga utama, diskon, total)\n"
            . "🧾 <b>Buat pesanan (SO):</b>\n"
            . "• <i>buat SO Budi: 10 akrilik A4, kurir JNT Cargo ongkir 20rb</i> → dapat PDF untuk diteruskan ke pembeli\n"
            . "• produk custom: <i>Custom 6, namanya Akrilik Sign Dilarang Merokok 3mm 16x30, 2pcs 50rb</i>\n"
            . "• revisi: <i>ganti jadi 12 pcs</i> / <i>ganti nama item jadi ...</i> · lalu <i>post</i> kalau sudah oke\n"
            . "• DP: forward bukti transfer + <i>DP 500rb ke BCA</i>\n"
            . "📷 atau kirim <b>foto struk</b> untuk dicatat otomatis.\n\n"
            . "Perintah: /batal (batalkan transaksi terakhir) · /baru (mulai ulang percakapan)\n\n"
            . '<i>Mendukung pengeluaran, prive, transfer antar bank, bayar ongkir (per faktur), baca struk, cek pengeluaran/saldo, '
            . 'serta buat SO → PDF → post → DP. Pembelian supplier berstok dicatat manual di ERP.</i>';
    }

    // ───────────────────────── Util ─────────────────────────

    /**
     * Pastikan setiap blok tool_use punya `input` berupa OBJEK saat dikirim ulang ke API.
     * Respons API ter-decode jadi array asosiatif; `{}` (tool tanpa argumen) jadi `[]` (array
     * kosong) yang akan di-encode sebagai array JSON → API menolak ("input should be an object").
     */
    private function normalizeToolInputs(array $content): array
    {
        foreach ($content as $i => $blk) {
            if (($blk['type'] ?? '') === 'tool_use' && (! isset($blk['input']) || $blk['input'] === [])) {
                $content[$i]['input'] = (object) [];
            }
        }
        return $content;
    }

    private function collectText(array $content): string
    {
        $parts = [];
        foreach ($content as $b) {
            if (($b['type'] ?? '') === 'text' && ! empty($b['text'])) {
                $parts[] = $b['text'];
            }
        }
        return trim(implode("\n", $parts));
    }

    private function model(): string
    {
        $setting = AnthropicSetting::current();
        return (string) (($setting?->model_text) ?: config('services.anthropic.model_text', 'claude-sonnet-4-6'));
    }

    private function visionModel(): string
    {
        $setting = AnthropicSetting::current();
        return (string) (($setting?->model_vision) ?: config('services.anthropic.model_vision', 'claude-opus-4-8'));
    }

    private function threshold(): float
    {
        $setting = AnthropicSetting::current();
        if ($setting && $setting->confirm_threshold !== null) {
            return (float) $setting->confirm_threshold;
        }
        return (float) config('services.anthropic.confirm_threshold', 100000);
    }

    private function thresholdLabel(): string
    {
        return 'Rp ' . number_format($this->threshold(), 0, ',', '.');
    }

    private function ttl(): int
    {
        return (int) config('services.anthropic.conversation_ttl', 1200);
    }

    /** SO aktif diingat lebih lama (7 hari) supaya DP yang di-forward belakangan tetap bisa default ke SO terakhir. */
    private function soTtl(): int
    {
        return 7 * 24 * 3600;
    }

    private function convKey(string $chatId): string    { return "aiacc:conv:{$chatId}"; }
    private function pendingKey(string $chatId): string  { return "aiacc:pending:{$chatId}"; }
    private function lastKey(string $chatId): string     { return "aiacc:last:{$chatId}"; }
    private function soKey(string $chatId): string       { return "aiacc:so:{$chatId}"; }

    private function saveConversation(string $chatId, array $messages): void
    {
        Cache::put($this->convKey($chatId), $messages, $this->ttl());
    }

    private function forgetConversation(string $chatId): void { Cache::forget($this->convKey($chatId)); }
    private function forgetPending(string $chatId): void      { Cache::forget($this->pendingKey($chatId)); }
    private function forgetLast(string $chatId): void         { Cache::forget($this->lastKey($chatId)); }
}
