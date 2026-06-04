<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BusinessProfileController extends Controller
{
    public function edit()
    {
        $profile = BusinessProfile::instance()->load('bankAccounts');
        return view('erp.settings.business-profile.edit', compact('profile'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:200',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'signer_name' => 'nullable|string|max:150',
            'signer_title' => 'nullable|string|max:150',
            'payment_instructions' => 'nullable|string|max:1000',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'signature' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
            'remove_signature' => 'nullable|boolean',
            'banks' => 'nullable|array',
            'banks.*.bank_name' => 'nullable|string|max:100',
            'banks.*.account_number' => 'nullable|string|max:50',
            'banks.*.holder' => 'nullable|string|max:150',
        ]);

        $profile = BusinessProfile::instance();

        // Logo: upload baru → hapus lama; remove flag → hapus saja.
        if ($request->hasFile('logo')) {
            if ($profile->logo_path) Storage::disk('public')->delete($profile->logo_path);
            $data['logo_path'] = $request->file('logo')->storeAs(
                'business',
                'logo-' . Str::random(8) . '.' . $request->file('logo')->getClientOriginalExtension(),
                'public'
            );
        } elseif ($request->boolean('remove_logo')) {
            if ($profile->logo_path) Storage::disk('public')->delete($profile->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->hasFile('signature')) {
            if ($profile->signature_path) Storage::disk('public')->delete($profile->signature_path);
            $data['signature_path'] = $request->file('signature')->storeAs(
                'business',
                'sig-' . Str::random(8) . '.' . $request->file('signature')->getClientOriginalExtension(),
                'public'
            );
        } elseif ($request->boolean('remove_signature')) {
            if ($profile->signature_path) Storage::disk('public')->delete($profile->signature_path);
            $data['signature_path'] = null;
        }

        unset(
            $data['logo'], $data['signature'],
            $data['remove_logo'], $data['remove_signature'],
            $data['banks']
        );

        $profile->update($data);

        // Sync rekening bank: hapus semua, insert ulang dari input.
        $profile->bankAccounts()->delete();
        $order = 0;
        foreach ((array) $request->input('banks', []) as $b) {
            $bankName = trim((string) ($b['bank_name'] ?? ''));
            $accNum   = trim((string) ($b['account_number'] ?? ''));
            if ($bankName === '' || $accNum === '') continue;
            $profile->bankAccounts()->create([
                'bank_name'      => $bankName,
                'account_number' => $accNum,
                'holder'         => trim((string) ($b['holder'] ?? '')),
                'sort_order'     => $order++,
            ]);
        }

        return redirect()->route('settings.business-profile.edit')
            ->with('success', 'Profil bisnis tersimpan.');
    }
}
