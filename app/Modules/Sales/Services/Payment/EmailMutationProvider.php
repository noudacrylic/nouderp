<?php

namespace App\Modules\Sales\Services\Payment;

use App\Modules\Sales\Contracts\PaymentConfirmationProvider;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Log;

/**
 * Konfirmasi otomatis via email notifikasi bank (mis. BRI "Dana Rp X masuk ke rekening…",
 * myBCA notifikasi kredit). Poller membaca email BELUM DIBACA dari inbox (Gmail via IMAP),
 * menyaring hanya email BANK + transaksi MASUK, mengekstrak nominal, lalu
 * PaymentMatchingService mencocokkan ke order berdasarkan nominal unik.
 *
 * Kredensial & aturan disimpan di PaymentSetting.config (terenkripsi):
 *   imap_host, imap_port(993), imap_encryption(ssl|tls|''), imap_username, imap_password,
 *   imap_folder(INBOX), sender_filter(daftar dipisah koma, opsional),
 *   credit_keywords(default "masuk,kredit,diterima"), amount_regex(opsional), mark_seen(bool).
 *
 * PENGAMAN: hanya memproses email yang (a) dari pengirim yang di-whitelist (bila diisi) DAN
 * (b) mengandung kata kunci kredit → email transaksi KELUAR/BUKAN-BANK tidak pernah dicocokkan.
 * Butuh ekstensi PHP `imap`. Tanpa itu → NO-OP aman (log + []).
 */
class EmailMutationProvider implements PaymentConfirmationProvider
{
    private const DEFAULT_AMOUNT_REGEX   = '/Rp\.?\s*([\d][\d.,]*)/i';
    private const DEFAULT_CREDIT_KEYWORDS = 'masuk,kredit,diterima';

    public function __construct(private PaymentSetting $setting) {}

    public function name(): string
    {
        return 'email';
    }

    public function fetchCredits(): array
    {
        if (! function_exists('imap_open')) {
            Log::warning('EmailMutationProvider: ekstensi PHP imap tidak aktif — konfirmasi email dilewati.');
            return [];
        }

        $host = (string) $this->setting->conf('imap_host');
        $user = (string) $this->setting->conf('imap_username');
        $pass = (string) $this->setting->conf('imap_password');
        if ($host === '' || $user === '' || $pass === '') {
            return [];
        }

        $port     = (int) ($this->setting->conf('imap_port') ?: 993);
        $enc      = strtolower((string) ($this->setting->conf('imap_encryption') ?: 'ssl'));
        $folder   = (string) ($this->setting->conf('imap_folder') ?: 'INBOX');
        $regex    = trim((string) $this->setting->conf('amount_regex')) ?: self::DEFAULT_AMOUNT_REGEX;
        $markSeen = $this->setting->conf('mark_seen', true);

        // Daftar pengirim & kata-kunci kredit (dipisah koma), lowercase.
        $senders  = $this->csvLower((string) $this->setting->conf('sender_filter'));
        $keywords = $this->csvLower((string) ($this->setting->conf('credit_keywords') ?: self::DEFAULT_CREDIT_KEYWORDS));

        $flags = '/imap';
        $flags .= $enc === 'tls' ? '/tls' : ($enc === '' ? '/notls' : '/ssl');
        $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;

        $credits = [];
        $inbox = @imap_open($mailbox, $user, $pass, 0, 1);
        if ($inbox === false) {
            Log::warning('EmailMutationProvider: gagal konek IMAP — ' . imap_last_error());
            return [];
        }

        try {
            $ids = imap_search($inbox, 'UNSEEN', SE_UID) ?: [];
            foreach ($ids as $uid) {
                $overview = imap_fetch_overview($inbox, (string) $uid, FT_UID)[0] ?? null;
                $from = strtolower((string) ($overview->from ?? ''));

                // Gate 1: pengirim (bila daftar diisi) — email non-bank dilewati TANPA ditandai baca.
                if ($senders && ! $this->matchesAny($from, $senders)) {
                    continue;
                }

                $body = $this->messageText($inbox, $uid);

                // Gate 2: harus transaksi MASUK/kredit — cegah transaksi keluar salah cocok.
                if ($keywords && ! $this->matchesAny(strtolower($body), $keywords)) {
                    continue;
                }

                $when = $this->messageDate($overview);

                if (preg_match_all($regex, $body, $m)) {
                    foreach ($m[1] as $raw) {
                        $amount = (float) clean_number($raw);
                        if ($amount > 0) {
                            $credits[] = [
                                'amount'      => $amount,
                                'reference'   => 'email-uid-' . $uid,
                                'occurred_at' => $when,
                            ];
                        }
                    }
                }

                // Tandai email BANK (yang lolos gate) sudah dibaca agar tak diproses ulang.
                if ($markSeen) {
                    imap_setflag_full($inbox, (string) $uid, '\\Seen', ST_UID);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('EmailMutationProvider: gagal baca email — ' . $e->getMessage());
        } finally {
            imap_close($inbox);
        }

        return $credits;
    }

    /** "a, b ,c" → ['a','b','c'] lowercase, buang kosong. */
    private function csvLower(string $csv): array
    {
        return array_values(array_filter(array_map(
            fn ($s) => strtolower(trim($s)),
            explode(',', $csv)
        )));
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    /** Ambil teks email (utamakan plain text; fallback strip HTML) + normalisasi nbsp/entity. */
    private function messageText($inbox, int $uid): string
    {
        $body = (string) imap_fetchbody($inbox, $uid, '1', FT_UID | FT_PEEK);
        if (trim($body) === '') {
            $body = (string) imap_body($inbox, $uid, FT_UID | FT_PEEK);
        }

        $decoded = quoted_printable_decode($body);
        $decoded = strip_tags($decoded);
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Samakan non-breaking space (&nbsp; → \xC2\xA0 / \xA0) jadi spasi biasa agar regex "Rp X" cocok.
        $decoded = str_replace(["\xC2\xA0", "\xA0"], ' ', $decoded);

        return trim($decoded) !== '' ? $decoded : $body;
    }

    private function messageDate(?object $overview): ?\Carbon\CarbonInterface
    {
        try {
            $date = $overview->date ?? null;
            return $date ? \Illuminate\Support\Carbon::parse($date) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
