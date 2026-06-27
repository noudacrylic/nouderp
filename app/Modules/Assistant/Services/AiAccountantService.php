<?php

namespace App\Modules\Assistant\Services;

use App\Core\Accounting\Account;
use App\Core\Journal\JournalLine;
use App\Models\AnthropicSetting;
use App\Models\FreightSetting;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Modules\Finance\Models\BankTransfer;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Models\CashDisbursementLine;
use App\Modules\Finance\Services\BankTransferService;
use App\Modules\Finance\Services\CashDisbursementService;
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
 * Cakupan: Fase 1 = Pengeluaran umum & Prive. Fase 2 = Transfer antar bank.
 * Pembelian supplier, refund, & baca struk menyusul.
 */
class AiAccountantService
{
    public function __construct(
        private ClaudeClient $claude,
        private CashDisbursementService $cdService,
        private BankTransferService $btService,
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

            // Simpan giliran assistant (mentah, termasuk blok tool_use) ke riwayat.
            $messages[] = ['role' => 'assistant', 'content' => $content];

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

        $rows = SalesInvoice::with('customer')
            ->whereIn('status', ['posted', 'paid'])
            ->where('shipping_cost', '>', 0)
            ->whereNotIn('id', $paidIds)
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('invoice_number', 'like', "%{$kw}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$kw}%"))))
            ->orderByDesc('invoice_date')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return 'Tidak ada faktur dengan titipan ongkir yang belum dibayar' . ($kw !== '' ? " cocok '{$kw}'" : '') . '.';
        }

        return $rows->map(fn ($i) => "invoice_id={$i->id} | {$i->invoice_number} | "
            . ($i->customer->name ?? '-') . ' | titipan Rp ' . number_format((float) $i->shipping_cost, 0, ',', '.'))
            ->implode("\n");
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
        $doc  = $this->findDoc($kind, (int) ($pending['id'] ?? 0));

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
5. JAWAB pertanyaan ringkas — total pengeluaran periode (ringkas_pengeluaran) & saldo kas/bank (saldo_kas_bank). Cukup panggil tool lalu sampaikan hasilnya; tidak ada yang diposting.

Untuk refund customer & pembelian barang dagangan/stok dari supplier (yang menambah stok): katakan itu dicatat manual di ERP, belum didukung lewat chat — jangan dipaksakan.

Bedakan dengan jelas:
- Uang keluar ke PIHAK LAIN / jadi biaya (bensin, gaji, listrik, sewa, prive) → catat_pengeluaran.
- Uang pindah ANTAR REKENING SENDIRI (tidak jadi biaya) → catat_transfer_bank.
- BAYAR ONGKIR yang terkait FAKTUR/pelanggan (pelepasan titipan) → cari_faktur_ongkir + catat_bayar_ongkir. Ongkir lain yang murni biaya umum tanpa faktur → catat_pengeluaran ke akun beban. Kalau ragu, tanya user dulu.
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
- Nominal di atas {$amb} otomatis butuh konfirmasi user (sistem yang menangani setelah kamu memanggil tool catat) — kamu tidak perlu minta konfirmasi sendiri.
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
                'description' => 'Cari faktur penjualan yang punya titipan ongkir & BELUM dibayar ongkirnya. '
                    . 'Pakai sebelum catat_bayar_ongkir untuk dapat invoice_id dan nilai titipannya.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Nomor faktur atau nama pelanggan (opsional; kosong = daftar terbaru).'],
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
        ];
    }

    private function helpText(): string
    {
        return "🤖 <b>Noud Bot — Pencatat Keuangan</b>\n\n"
            . "Ketik perintah biasa, mis:\n"
            . "• <i>catat pengeluaran bensin 50rb dari kas</i>\n"
            . "• <i>prive 200rb dari BCA</i>\n"
            . "• <i>transfer 5jt dari BCA ke Mandiri</i>\n"
            . "• <i>bayar ongkir faktur SI/2026/06/00020 448rb dari BRI</i>\n"
            . "• <i>pengeluaran bulan ini berapa?</i> / <i>saldo BCA?</i>\n"
            . "📷 atau kirim <b>foto struk</b> untuk dicatat otomatis.\n\n"
            . "Perintah: /batal (batalkan transaksi terakhir) · /baru (mulai ulang percakapan)\n\n"
            . '<i>Mendukung pengeluaran, prive, transfer antar bank, bayar ongkir (per faktur), baca struk, & cek pengeluaran/saldo. '
            . 'Pembelian supplier berstok dicatat manual di ERP.</i>';
    }

    // ───────────────────────── Util ─────────────────────────

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

    private function convKey(string $chatId): string    { return "aiacc:conv:{$chatId}"; }
    private function pendingKey(string $chatId): string  { return "aiacc:pending:{$chatId}"; }
    private function lastKey(string $chatId): string     { return "aiacc:last:{$chatId}"; }

    private function saveConversation(string $chatId, array $messages): void
    {
        Cache::put($this->convKey($chatId), $messages, $this->ttl());
    }

    private function forgetConversation(string $chatId): void { Cache::forget($this->convKey($chatId)); }
    private function forgetPending(string $chatId): void      { Cache::forget($this->pendingKey($chatId)); }
    private function forgetLast(string $chatId): void         { Cache::forget($this->lastKey($chatId)); }
}
