<?php

namespace App\Http\Controllers\Settings;

use App\Core\Accounting\Account;
use App\Http\Controllers\Controller;
use App\Models\MidtransSetting;
use App\Modules\Payment\Services\MidtransService;
use Illuminate\Http\Request;

class MidtransSettingController extends Controller
{
    public function edit()
    {
        $setting = MidtransSetting::singleton();
        $cashAccounts = Account::where('is_active', 1)
            ->whereIn('account_category', ['cash', 'cash_equivalent'])
            ->orderBy('code')->get();
        $expenseAccounts = Account::where('is_active', 1)
            ->where('type', 'expense')
            ->orderBy('code')->get();

        return view('erp.settings.midtrans.edit', compact('setting', 'cashAccounts', 'expenseAccounts'));
    }

    /**
     * Tarik status transaksi pending dari Midtrans sekarang juga.
     *
     * Logikanya sama persis dengan yang dijalankan cron tiap 15 menit — dipanggil dari
     * dua tempat, bukan disalin. Tombol ini ada supaya tidak perlu menunggu jadwal
     * berikutnya saat pelanggan menelepon bilang "saya sudah bayar".
     */
    public function reconcilePending(MidtransService $midtrans)
    {
        $r = $midtrans->reconcilePending();

        $pesan = "Dicek {$r['checked']} transaksi pending: "
            . count($r['updated']) . ' berubah status, '
            . $r['unchanged'] . ' masih pending, '
            . $r['not_found'] . ' tidak dikenal Midtrans.';

        if ($r['failed']) {
            return back()->with('error', $pesan . ' ' . count($r['failed']) . ' gagal dicek — lihat log.');
        }

        return back()->with('success', $pesan);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'server_key' => 'nullable|string|max:255',
            'client_key' => 'nullable|string|max:255',
            'merchant_id' => 'nullable|string|max:255',
            'is_production' => 'nullable|boolean',
            'show_payment_method' => 'nullable|boolean',
            'pos_qris_enabled' => 'nullable|boolean',
            'link_expiry_days' => 'required|integer|min:1|max:90',
            'qris_expiry_minutes' => 'required|integer|min:5|max:1440',
            'va_fee' => 'required|numeric|min:0',
            'qris_fee_percent' => 'required|numeric|min:0|max:100',
            'customer_fee_threshold' => 'required|numeric|min:0',
            'customer_fee_amount' => 'required|numeric|min:0',
            'cash_account_id' => 'required|exists:accounts,id',
            'fee_account_id' => 'required|exists:accounts,id',
        ], [], [
            'va_fee' => 'tarif VA',
            'customer_fee_amount' => 'biaya admin customer',
        ]);

        // Jaga agar beban bersih (MDR - biaya customer) tidak pernah negatif —
        // generic journal tidak mendukung admin_fee negatif.
        if ((float) $data['va_fee'] < (float) $data['customer_fee_amount']) {
            return back()->withInput()->withErrors([
                'va_fee' => 'Tarif VA tidak boleh lebih kecil dari biaya admin yang dibebankan ke customer.',
            ]);
        }

        $data['is_production'] = $request->boolean('is_production');
        $data['show_payment_method'] = $request->boolean('show_payment_method');
        $data['pos_qris_enabled'] = $request->boolean('pos_qris_enabled');

        // Tarif & subsidi per metode. Input <number> → dikirim kanonik (titik = desimal),
        // jadi cast langsung; JANGAN clean_number (bisa salah baca desimal persen).
        $channelFees = [];
        foreach (array_keys(\App\Modules\Payment\Services\MidtransFeeCalculator::channelLabels()) as $ch) {
            $row = (array) $request->input("channel_fees.$ch", []);
            $channelFees[$ch] = [
                'mdr_percent'     => max(0, (float) ($row['mdr_percent'] ?? 0)),
                'mdr_flat'        => max(0, (int) ($row['mdr_flat'] ?? 0)),
                'subsidy_percent' => max(0, (float) ($row['subsidy_percent'] ?? 0)),
                'subsidy_flat'    => max(0, (int) ($row['subsidy_flat'] ?? 0)),
                'flat_threshold'  => max(0, (int) ($row['flat_threshold'] ?? 0)),
            ];
        }
        $data['channel_fees'] = $channelFees;

        MidtransSetting::singleton()->update($data);

        return redirect()->route('settings.integrations.index')
            ->with('success', 'Pengaturan Midtrans tersimpan.');
    }
}
