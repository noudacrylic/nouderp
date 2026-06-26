<?php

namespace App\Http\Controllers\Me;

use App\Modules\SDM\Models\Karyawan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * PWA Karyawan — "Profil": hub menuju Data Pribadi, Cuti & SP, dan Slip Gaji,
 * sekaligus tempat melengkapi data pribadi yang belum lengkap.
 */
class ProfileController extends Controller
{
    /**
     * Field data pribadi yang dipantau kelengkapannya.
     * NPWP TIDAK termasuk: sejak NPWP pribadi = NIK, tidak diwajibkan.
     */
    private const TRACKED = [
        'email'               => 'Email',
        'alamat'              => 'Alamat',
        'nik'                 => 'NIK (KTP)',
        'bank_name'           => 'Nama Bank',
        'bank_account_number' => 'No. Rekening',
        'bank_account_holder' => 'Nama Pemilik Rekening',
        'foto_path'           => 'Foto Profil',
        'ktp_path'            => 'Foto KTP',
    ];

    public function index()
    {
        $karyawan = auth()->user()->karyawan;

        $missing = [];
        foreach (self::TRACKED as $field => $label) {
            if (blank($karyawan->{$field})) {
                $missing[] = $label;
            }
        }

        return view('me.profil', [
            'karyawan' => $karyawan,
            'missing'  => $missing,
        ]);
    }

    public function edit()
    {
        return view('me.profil-edit', [
            'karyawan' => auth()->user()->karyawan,
        ]);
    }

    public function update(Request $request)
    {
        $user     = auth()->user();
        $karyawan = $user->karyawan;

        $data = $request->validate([
            'email'               => 'nullable|email|max:255',
            'alamat'              => 'nullable|string|max:500',
            'nik'                 => 'nullable|string|max:32',
            'npwp'                => 'nullable|string|max:32',
            'bank_name'           => 'nullable|string|max:60',
            'bank_account_number' => 'nullable|string|max:60',
            'bank_account_holder' => 'nullable|string|max:120',
            'foto'                => 'nullable|image|max:5120',
            'ktp'                 => 'nullable|image|max:5120',
        ]);

        // Hanya field non-foto; biarkan kosong = mengosongkan (karyawan boleh perbaiki).
        $karyawan->fill([
            'email'               => $data['email'] ?? null,
            'alamat'              => $data['alamat'] ?? null,
            'nik'                 => $data['nik'] ?? null,
            'npwp'                => $data['npwp'] ?? null,
            'bank_name'           => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'bank_account_holder' => $data['bank_account_holder'] ?? null,
        ]);

        if ($request->hasFile('foto')) {
            $karyawan->foto_path = $request->file('foto')->store('karyawan/foto', 'public');
        }
        if ($request->hasFile('ktp')) {
            $karyawan->ktp_path = $request->file('ktp')->store('karyawan/ktp', 'public');
        }
        $karyawan->save();

        // Selaraskan email ke akun login bila diisi.
        if (! empty($data['email']) && $user->email !== $data['email']) {
            $user->email = $data['email'];
            $user->save();
        }

        return redirect()->route('me.profil')->with('success', 'Data pribadi diperbarui.');
    }
}
