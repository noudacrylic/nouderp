<?php

namespace App\Modules\Assistant\Services;

use App\Core\Accounting\Account;
use App\Models\AnthropicSetting;
use App\Models\User;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Services\CashDisbursementService;
use Illuminate\Support\Facades\Cache;

/**
 * Asisten pencatat keuangan via Telegram (Fase 1).
 *
 * Alur: pesan teks → percakapan dengan Claude (tool use) → AI memanggil tool
 * cari_akun / catat_pengeluaran → posting lewat CashDisbursementService (jalur yang
 * sama dengan UI, jurnal konsisten). Nominal di atas ambang ditahan untuk konfirmasi
 * deterministik (ya/batal) sebelum diposting. /batal mem-void transaksi terakhir.
 *
 * Cakupan Fase 1: HANYA Pengeluaran umum & Prive. Transfer/pembelian/struk menyusul.
 */
class AiAccountantService
{
    public function __construct(
        private ClaudeClient $claude,
        private CashDisbursementService $cdService,
    ) {}

    /** Titik masuk utama. Return teks balasan untuk dikirim ke Telegram (HTML). */
    public function handle(User $user, string $chatId, string $text): string
    {
        $text = trim($text);
        $lower = strtolower($text);

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

        if (! $this->claude->enabled()) {
            return '⚠️ AI belum aktif: ANTHROPIC_API_KEY belum diatur di server.';
        }

        return $this->converse($user, $chatId, $text);
    }

    // ───────────────────────── Percakapan + tool loop ─────────────────────────

    private function converse(User $user, string $chatId, string $text): string
    {
        $messages = Cache::get($this->convKey($chatId), []);
        $messages[] = ['role' => 'user', 'content' => $text];

        $postedSummary = null;

        for ($i = 0; $i < 6; $i++) {
            $resp = $this->claude->messages([
                'model'      => $this->model(),
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
            'cari_akun'         => ['content' => $this->toolCariAkun($input)],
            'catat_pengeluaran' => $this->toolCatatPengeluaran($chatId, $input),
            default             => ['content' => 'Tool tidak dikenal: ' . $name],
        };
    }

    // ───────────────────────── Tools ─────────────────────────

    private function toolCariAkun(array $input): string
    {
        $kw    = trim((string) ($input['keyword'] ?? ''));
        $jenis = (string) ($input['jenis'] ?? 'beban');

        $q = Account::query()->where('is_active', 1);
        if ($jenis === 'kas_bank') {
            $q->whereIn('account_category', ['cash', 'cash_equivalent']);
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
                'draft_id' => $cd->id,
                'number'   => $cd->number,
                'summary'  => $summary,
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

        Cache::put($this->lastKey($chatId), $cd->id, $this->ttl());

        return [
            'content'         => "Berhasil diposting (nomor {$cd->number}). {$summary}",
            '_posted_summary' => "✅ <b>Diposting</b>: {$cd->number}\n{$summary}",
        ];
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
        $cd = CashDisbursement::find($pending['draft_id']);

        if (! $cd || ! $cd->isDraft()) {
            $this->forgetConversation($chatId);
            return 'Transaksi sudah tidak bisa diproses (mungkin sudah berubah). Silakan ulangi.';
        }

        if ($isNo) {
            $cd->lines()->delete();
            $cd->delete();
            $this->forgetConversation($chatId);
            return '❌ Dibatalkan. Tidak ada yang dibukukan.';
        }

        try {
            $this->cdService->post($cd);
        } catch (\Throwable $e) {
            return '❌ Gagal posting: ' . $e->getMessage();
        }

        Cache::put($this->lastKey($chatId), $cd->id, $this->ttl());
        $this->forgetConversation($chatId);

        return "✅ <b>Diposting</b>: {$cd->number}\n{$pending['summary']}\n\n↩️ /batal untuk membatalkan.";
    }

    private function voidLast(string $chatId): string
    {
        $id = Cache::get($this->lastKey($chatId));
        if (! $id) {
            return 'Tidak ada transaksi terakhir untuk dibatalkan (atau sudah kedaluwarsa). '
                . 'Batalkan manual di menu Pengeluaran bila perlu.';
        }

        $cd = CashDisbursement::find($id);
        if (! $cd) {
            return 'Transaksi tidak ditemukan.';
        }
        if ($cd->isVoid()) {
            return "Transaksi {$cd->number} sudah di-void.";
        }
        if (! $cd->canBeVoided()) {
            return "Transaksi {$cd->number} tidak bisa di-void.";
        }

        try {
            $this->cdService->void($cd);
            $this->forgetLast($chatId);
            return "↩️ <b>Dibatalkan (void)</b>: {$cd->number}. Jurnal sudah dibalik.";
        } catch (\Throwable $e) {
            return 'Gagal void: ' . $e->getMessage();
        }
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

KEMAMPUAN SAAT INI (Fase 1) HANYA: mencatat PENGELUARAN UMUM dan PRIVE (pengambilan pribadi pemilik). Untuk transfer antar bank, pembelian supplier, pembacaan struk/foto, laporan, atau lainnya: katakan fitur itu belum aktif dan akan menyusul — jangan dipaksakan.

Untuk mencatat butuh: akun yang dibebani (beban/biaya atau Prive), akun kas/bank sumber dana, nominal, dan keterangan.

ATURAN:
- SELALU pakai tool cari_akun untuk menemukan id akun dari kata user. JANGAN mengarang id akun.
  • biaya/beban (mis. "bensin", "makan", "listrik") → cari_akun jenis=beban.
  • "prive"/"ambil pribadi"/"buat pemilik" → cari_akun jenis=equity (cari "Prive").
  • sumber dana ("kas", "kas kecil", "bca", "bri") → cari_akun jenis=kas_bank.
- Jika SUMBER kas/bank belum disebut user, TANYAKAN dulu — jangan menebak.
- Jika hasil cari_akun lebih dari satu yang relevan dan ambigu, tanyakan user mana yang dimaksud.
- Jika akun Prive tidak ditemukan, beri tahu user agar dibuat dulu di Bagan Akun.
- Parse nominal Indonesia: "20rb"/"20k"/"20.000" = 20000; "1,5jt"/"1.5jt" = 1500000.
- Nominal di atas {$amb} otomatis butuh konfirmasi user (sistem yang menangani setelah kamu memanggil catat_pengeluaran) — kamu tidak perlu minta konfirmasi sendiri.
- Setelah semua id akun & nominal jelas, panggil catat_pengeluaran. Balas singkat, ramah, dan to the point.
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
        ];
    }

    private function helpText(): string
    {
        return "🤖 <b>Noud Bot — Pencatat Keuangan</b>\n\n"
            . "Ketik perintah biasa, mis:\n"
            . "• <i>catat pengeluaran bensin 50rb dari kas</i>\n"
            . "• <i>prive 200rb dari BCA</i>\n\n"
            . "Perintah: /batal (batalkan transaksi terakhir) · /baru (mulai ulang percakapan)\n\n"
            . '<i>Saat ini baru mendukung pengeluaran & prive. Transfer, pembelian, dan baca struk menyusul.</i>';
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
