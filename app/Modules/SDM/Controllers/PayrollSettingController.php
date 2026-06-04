<?php

namespace App\Modules\SDM\Controllers;

use App\Core\Accounting\Account;
use App\Modules\SDM\Models\PayrollSetting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PayrollSettingController extends Controller
{
    public function edit()
    {
        $setting = PayrollSetting::singleton();
        $this->applyDefaultAccounts($setting);
        $accounts = Account::orderBy('code')->get(['id', 'code', 'name', 'type', 'account_category']);
        return view('erp.sdm.kebijakan.pengaturan', compact('setting', 'accounts'));
    }

    /**
     * Auto-fill akun default dari Chart of Accounts berdasarkan kode (hanya isi yang masih null).
     */
    protected function applyDefaultAccounts(PayrollSetting $setting): void
    {
        $defaultsByCode = [
            'expense_salary_account_id'           => '5300', // Beban Gaji Karyawan (THR/cuti unused juga ke sini)
            'bpjs_company_expense_account_id'     => '5303', // Beban BPJS Perusahaan
            'kasbon_receivable_account_id'        => '1150', // Piutang Kasbon Karyawan
            'bpjs_kesehatan_liability_account_id' => '2110', // Hutang BPJS Kesehatan
            'bpjs_tk_liability_account_id'        => '2111', // Hutang BPJS Ketenagakerjaan
            'pph21_liability_account_id'          => '2112', // Hutang PPh 21
        ];
        $dirty = false;
        foreach ($defaultsByCode as $field => $code) {
            if (! empty($setting->{$field})) continue;
            $acc = Account::where('code', $code)->where('is_active', true)->first();
            if ($acc) {
                $setting->{$field} = $acc->id;
                $dirty = true;
            }
        }
        if ($dirty) {
            $setting->save();
        }
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'expense_salary_account_id'            => 'nullable|exists:accounts,id',
            'bpjs_company_expense_account_id'      => 'nullable|exists:accounts,id',
            'bpjs_kesehatan_liability_account_id'  => 'nullable|exists:accounts,id',
            'bpjs_kesehatan_employee_rate'         => 'nullable|numeric|min:0|max:100',
            'bpjs_kesehatan_company_rate'          => 'nullable|numeric|min:0|max:100',
            'bpjs_kesehatan_max_base'              => 'nullable|numeric|min:0',
            'bpjs_tk_liability_account_id'         => 'nullable|exists:accounts,id',
            'bpjs_jht_employee_rate'               => 'nullable|numeric|min:0|max:100',
            'bpjs_jht_company_rate'                => 'nullable|numeric|min:0|max:100',
            'bpjs_jp_employee_rate'                => 'nullable|numeric|min:0|max:100',
            'bpjs_jp_company_rate'                 => 'nullable|numeric|min:0|max:100',
            'bpjs_jkk_company_rate'                => 'nullable|numeric|min:0|max:100',
            'bpjs_jkm_company_rate'                => 'nullable|numeric|min:0|max:100',
            'bpjs_jp_max_base'                     => 'nullable|numeric|min:0',
            'pph21_liability_account_id'           => 'nullable|exists:accounts,id',
            'pph21_enabled'                        => 'nullable|boolean',
            'kasbon_receivable_account_id'         => 'nullable|exists:accounts,id',
        ]);
        $data['pph21_enabled'] = $request->boolean('pph21_enabled');
        $setting = PayrollSetting::singleton();
        $setting->update($data);
        return back()->with('success', 'Pengaturan akun & rate payroll disimpan.');
    }
}
