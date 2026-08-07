<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Core\Accounting\Account;
use App\Models\FreightSetting;
use Illuminate\Http\Request;

class FreightSettingController extends Controller
{
    public function edit()
    {
        $accounts = Account::where('is_active', 1)->orderBy('code')->get();
        $setting = FreightSetting::singleton();
        $titipanAccount = Account::where('code', '1203')->first();

        // Saldo buku tiap provider (untuk panel rekonsiliasi).
        $svc  = app(\App\Modules\Shipping\Services\ShippingAccountingService::class);
        $book = [];
        foreach (array_keys(FreightSetting::PROVIDERS) as $provider) {
            $book[$provider] = $svc->saldoBook($setting->saldoAccountId($provider));
        }

        // Nama lama tetap dipakai tampilan lawas panel Biteship.
        $biteshipBook = $book['biteship'] ?? 0;

        return view('erp.settings.freight', compact('accounts', 'setting', 'titipanAccount', 'biteshipBook', 'book'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'gain_account_id' => 'nullable|exists:accounts,id',
            'loss_account_id' => 'nullable|exists:accounts,id',
            'biteship_saldo_account_id' => 'nullable|exists:accounts,id',
            'biteship_fee_account_id'   => 'nullable|exists:accounts,id',
            'jubelio_saldo_account_id'  => 'nullable|exists:accounts,id',
            'jubelio_fee_account_id'    => 'nullable|exists:accounts,id',
        ]);

        FreightSetting::singleton()->update($data);

        return redirect()->route('settings.freight.edit')
            ->with('success', 'Pengaturan ongkir disimpan.');
    }

    /** Rekonsiliasi saldo deposit provider: selisih buku vs saldo asli → Beban Layanan. */
    public function reconcile(Request $request, \App\Modules\Shipping\Services\ShippingAccountingService $svc)
    {
        $data = $request->validate([
            'actual_balance' => 'required|numeric|min:0',
            'recon_date'     => 'nullable|date',
            'provider'       => 'nullable|in:' . implode(',', array_keys(FreightSetting::PROVIDERS)),
        ]);

        $provider = $data['provider'] ?? 'biteship';
        $label    = FreightSetting::providerLabel($provider);

        try {
            $date = !empty($data['recon_date']) ? \Carbon\Carbon::parse($data['recon_date']) : null;
            $res = $svc->reconcileSaldo((float) $data['actual_balance'], $date, $provider);
        } catch (\Throwable $e) {
            return back()->with('error', 'Rekonsiliasi gagal: ' . $e->getMessage());
        }

        if (!$res['posted']) {
            return back()->with('success', "Saldo {$label} sudah sesuai, tidak ada selisih untuk dijurnal.");
        }

        $msg = $res['diff'] > 0
            ? 'Selisih Rp ' . number_format($res['diff'], 0, ',', '.') . " dicatat sebagai Beban Layanan {$label}."
            : 'Koreksi Rp ' . number_format(abs($res['diff']), 0, ',', '.') . ' (saldo asli lebih tinggi) dicatat.';

        return back()->with('success', 'Rekonsiliasi selesai. ' . $msg);
    }
}
