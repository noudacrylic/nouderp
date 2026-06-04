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

        // Saldo Biteship buku (untuk panel rekonsiliasi).
        $biteshipBook = 0;
        if ($setting->biteship_saldo_account_id) {
            $biteshipBook = (float) \App\Core\Journal\JournalLine::where('account_id', $setting->biteship_saldo_account_id)
                ->whereHas('journal', fn ($q) => $q->where('status', '!=', 'void'))
                ->sum(\DB::raw('debit - credit'));
        }

        return view('erp.settings.freight', compact('accounts', 'setting', 'titipanAccount', 'biteshipBook'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'gain_account_id' => 'nullable|exists:accounts,id',
            'loss_account_id' => 'nullable|exists:accounts,id',
            'biteship_saldo_account_id' => 'nullable|exists:accounts,id',
            'biteship_fee_account_id'   => 'nullable|exists:accounts,id',
        ]);

        FreightSetting::singleton()->update($data);

        return redirect()->route('settings.freight.edit')
            ->with('success', 'Pengaturan ongkir disimpan.');
    }

    /** Rekonsiliasi Saldo Biteship: selisih buku vs saldo asli → Beban Layanan Biteship. */
    public function reconcile(Request $request, \App\Modules\Shipping\Services\ShippingAccountingService $svc)
    {
        $data = $request->validate([
            'actual_balance' => 'required|numeric|min:0',
            'recon_date'     => 'nullable|date',
        ]);

        try {
            $date = !empty($data['recon_date']) ? \Carbon\Carbon::parse($data['recon_date']) : null;
            $res = $svc->reconcileSaldo((float) $data['actual_balance'], $date);
        } catch (\Throwable $e) {
            return back()->with('error', 'Rekonsiliasi gagal: ' . $e->getMessage());
        }

        if (!$res['posted']) {
            return back()->with('success', 'Saldo sudah sesuai, tidak ada selisih untuk dijurnal.');
        }

        $msg = $res['diff'] > 0
            ? 'Selisih Rp ' . number_format($res['diff'], 0, ',', '.') . ' dicatat sebagai Beban Layanan Biteship.'
            : 'Koreksi Rp ' . number_format(abs($res['diff']), 0, ',', '.') . ' (saldo asli lebih tinggi) dicatat.';

        return back()->with('success', 'Rekonsiliasi selesai. ' . $msg);
    }
}
