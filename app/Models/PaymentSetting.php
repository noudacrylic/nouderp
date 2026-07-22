<?php

namespace App\Models;

use App\Core\Accounting\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan pembayaran "Transfer Bank + Kode Unik" (singleton id=1).
 * Pengganti Midtrans untuk toko online: pembeli transfer nominal unik, sistem
 * cocokkan otomatis (email/moota) → eskalasi Telegram → backstop auto-batal.
 */
class PaymentSetting extends Model
{
    protected $fillable = [
        'is_active',
        'bank_name',
        'account_number',
        'account_holder',
        'bank_accounts',
        'cash_account_id',
        'unique_code_min',
        'unique_code_max',
        'escalation_minutes',
        'expiry_hours',
        'confirmation_provider',
        'config',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'bank_accounts'      => 'array',
        'unique_code_min'    => 'integer',
        'unique_code_max'    => 'integer',
        'escalation_minutes' => 'integer',
        'expiry_hours'       => 'integer',
        // Kredensial adapter (IMAP/Moota) terenkripsi at-rest.
        'config'             => 'encrypted:array',
    ];

    public static function singleton(): self
    {
        // Pola aman: JANGAN firstOrCreate(['id'=>1]) — 'id' tidak fillable → tiap panggil
        // bikin baris baru (auto-increment) saat baris pertama ber-id > 1. Lihat R2Setting.
        return self::query()->oldest('id')->first() ?? self::create([]);
    }

    /** Alias konsisten dengan setting lain (current()). */
    public static function current(): self
    {
        return self::singleton();
    }

    /**
     * Daftar rekening tujuan (ternormalisasi & terindeks).
     * Tiap entri: ['key','bank_name','account_number','account_holder','cash_account_id','confirmation'].
     * Fallback ke kolom tunggal lama bila bank_accounts kosong.
     */
    public function accounts(): array
    {
        $rows = is_array($this->bank_accounts) ? $this->bank_accounts : [];

        if (empty($rows) && ! empty($this->account_number)) {
            $rows = [[
                'bank_name'       => $this->bank_name,
                'account_number'  => $this->account_number,
                'account_holder'  => $this->account_holder,
                'cash_account_id' => $this->cash_account_id,
                'confirmation'    => $this->confirmation_provider === 'manual' ? 'manual' : 'email',
            ]];
        }

        $out = [];
        foreach (array_values($rows) as $i => $r) {
            if (empty($r['account_number'])) continue;
            $out[] = [
                'key'             => $i,
                'bank_name'       => $r['bank_name'] ?? '',
                'account_number'  => $r['account_number'] ?? '',
                'account_holder'  => $r['account_holder'] ?? '',
                'cash_account_id' => $r['cash_account_id'] ?? null,
                'confirmation'    => ($r['confirmation'] ?? 'email') === 'manual' ? 'manual' : 'email',
            ];
        }
        return $out;
    }

    /** Akun kas ERP untuk rekening index tertentu (fallback ke default). */
    public function cashIdFor($key): ?int
    {
        $accounts = $this->accounts();
        if ($key !== null && isset($accounts[(int) $key]['cash_account_id'])) {
            return (int) $accounts[(int) $key]['cash_account_id'] ?: $this->defaultCashId();
        }
        return $this->defaultCashId();
    }

    /** Akun kas untuk konfirmasi otomatis (rekening ber-mode email, mis. BRI). */
    public function emailAccountCashId(): ?int
    {
        foreach ($this->accounts() as $a) {
            if ($a['confirmation'] === 'email' && $a['cash_account_id']) {
                return (int) $a['cash_account_id'];
            }
        }
        return $this->defaultCashId();
    }

    /** Akun kas default = rekening pertama, fallback kolom tunggal lama. */
    public function defaultCashId(): ?int
    {
        $accounts = $this->accounts();
        return (int) ($accounts[0]['cash_account_id'] ?? $this->cash_account_id) ?: null;
    }

    /** Siap dipakai bila aktif + minimal satu rekening dengan no. rekening & akun kas. */
    public function isConfigured(): bool
    {
        if (! $this->is_active) return false;
        foreach ($this->accounts() as $a) {
            if (! empty($a['account_number']) && ! empty($a['cash_account_id'])) {
                return true;
            }
        }
        return false;
    }

    /** Ambil satu nilai dari config adapter (mis. imap_host). */
    public function conf(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }
}
