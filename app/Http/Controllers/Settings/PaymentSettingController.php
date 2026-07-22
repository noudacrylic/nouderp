<?php

namespace App\Http\Controllers\Settings;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;

/**
 * Pengaturan pembayaran toko online "Transfer Bank + Kode Unik".
 * Settings → Integrasi → Pembayaran. Rekening tujuan, akun kas, rentang kode unik,
 * timer eskalasi/kedaluwarsa, & sumber konfirmasi otomatis (email IMAP / Moota).
 */
class PaymentSettingController extends Controller
{
    public function edit()
    {
        $setting = PaymentSetting::singleton();
        $cashAccounts = Account::where('is_active', 1)
            ->whereIn('account_category', ['cash', 'cash_equivalent'])
            ->orderBy('code')->get();

        return view('erp.settings.payment.edit', compact('setting', 'cashAccounts'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'is_active'             => 'nullable|boolean',
            'bank_accounts'                    => 'nullable|array',
            'bank_accounts.*.bank_name'        => 'nullable|string|max:100',
            'bank_accounts.*.account_number'   => 'nullable|string|max:50',
            'bank_accounts.*.account_holder'   => 'nullable|string|max:100',
            'bank_accounts.*.cash_account_id'  => 'nullable|exists:accounts,id',
            'bank_accounts.*.confirmation'     => 'nullable|in:email,manual',
            'unique_code_min'       => 'required|integer|min:1|max:998',
            'unique_code_max'       => 'required|integer|min:2|max:999|gte:unique_code_min',
            'escalation_minutes'    => 'required|integer|min:1|max:1440',
            'expiry_hours'          => 'required|integer|min:1|max:168',
            'confirmation_provider' => 'required|in:email,moota,manual',

            // Kredensial adapter email (IMAP)
            'imap_host'       => 'nullable|string|max:255',
            'imap_port'       => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|in:ssl,tls,',
            'imap_username'   => 'nullable|string|max:255',
            'imap_password'   => 'nullable|string|max:255',
            'imap_folder'     => 'nullable|string|max:100',
            'sender_filter'   => 'nullable|string|max:255',
            'credit_keywords' => 'nullable|string|max:255',
            'amount_regex'    => 'nullable|string|max:255',
            'mark_seen'       => 'nullable|boolean',

            // Moota (opsional)
            'moota_token'  => 'nullable|string|max:255',
            'moota_secret' => 'nullable|string|max:255',
        ]);

        $setting = PaymentSetting::singleton();
        $config  = (array) ($setting->config ?? []);

        // Password IMAP: kosong = pertahankan yang lama (jangan timpa dgn kosong).
        foreach (['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_folder',
                  'sender_filter', 'credit_keywords', 'amount_regex', 'moota_token', 'moota_secret'] as $key) {
            $config[$key] = $data[$key] ?? null;
        }
        $config['mark_seen'] = $request->boolean('mark_seen');
        if (! empty($data['imap_password'])) {
            $config['imap_password'] = $data['imap_password'];
        }

        // Normalisasi daftar rekening: buang baris tanpa no. rekening.
        $accounts = [];
        foreach ((array) ($data['bank_accounts'] ?? []) as $row) {
            if (empty($row['account_number'])) continue;
            $accounts[] = [
                'bank_name'       => $row['bank_name'] ?? null,
                'account_number'  => $row['account_number'],
                'account_holder'  => $row['account_holder'] ?? null,
                'cash_account_id' => $row['cash_account_id'] ?? null,
                'confirmation'    => ($row['confirmation'] ?? 'email') === 'manual' ? 'manual' : 'email',
            ];
        }

        $setting->update([
            'is_active'             => $request->boolean('is_active'),
            'bank_accounts'         => $accounts,
            // Kolom tunggal lama = rekening pertama (fallback backward-compat).
            'bank_name'             => $accounts[0]['bank_name'] ?? null,
            'account_number'        => $accounts[0]['account_number'] ?? null,
            'account_holder'        => $accounts[0]['account_holder'] ?? null,
            'cash_account_id'       => $accounts[0]['cash_account_id'] ?? null,
            'unique_code_min'       => $data['unique_code_min'],
            'unique_code_max'       => $data['unique_code_max'],
            'escalation_minutes'    => $data['escalation_minutes'],
            'expiry_hours'          => $data['expiry_hours'],
            'confirmation_provider' => $data['confirmation_provider'],
            'config'                => $config,
        ]);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Pengaturan Pembayaran tersimpan.');
    }
}
