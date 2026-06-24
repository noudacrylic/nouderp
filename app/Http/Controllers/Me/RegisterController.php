<?php

namespace App\Http\Controllers\Me;

use App\Models\User;
use App\Modules\SDM\Models\Karyawan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Self-register karyawan untuk PWA (`/me/register`). Role akun = 'karyawan'
 * (terpisah dari 'user'). Login pakai No. HP.
 *
 * Alur:
 *  1. Karyawan masukkan No. HP → dicocokkan dgn sdm_karyawan.hp (secret).
 *  2. Kalau cocok → tampil Kode Karyawan + form data pribadi + buat password sendiri.
 *  3. Submit → data pribadi tersimpan, akun karyawan dibuat LANGSUNG AKTIF,
 *     username = No. HP ternormalisasi. Bisa langsung login.
 */
class RegisterController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect(user_landing_url());
        }
        return view('me.register', ['karyawan' => null]);
    }

    /** Langkah 1: cek No. HP → tampilkan form data pribadi bila cocok. */
    public function check(Request $request)
    {
        $request->validate(['hp' => 'required|string|max:30'], [
            'hp.required' => 'Nomor HP wajib diisi.',
        ]);

        $karyawan = $this->matchByPhone($request->hp);

        if (! $karyawan) {
            return back()->withInput()
                ->withErrors(['hp' => 'Nomor HP tidak cocok dengan data karyawan. Hubungi admin bila benar.']);
        }
        if ($this->hasKaryawanAccount($karyawan)) {
            return back()->withInput()
                ->withErrors(['hp' => 'Karyawan ini sudah punya akun. Silakan login pakai No. HP, atau hubungi admin untuk reset.']);
        }

        return view('me.register', ['karyawan' => $karyawan]);
    }

    /** Langkah 2: simpan data pribadi + buat akun karyawan (langsung aktif). */
    public function register(Request $request)
    {
        $data = $request->validate([
            'karyawan_id'         => 'required|integer|exists:sdm_karyawan,id',
            'hp'                  => 'required|string|max:30',
            'email'               => 'nullable|email|max:255',
            'alamat'              => 'nullable|string|max:500',
            'nik'                 => 'nullable|string|max:32',
            'npwp'                => 'nullable|string|max:32',
            'bank_name'           => 'nullable|string|max:60',
            'bank_account_number' => 'nullable|string|max:60',
            'bank_account_holder' => 'nullable|string|max:120',
            'mulai_kerja'         => 'nullable|date',
            'foto'                => 'nullable|image|max:5120',
            'ktp'                 => 'nullable|image|max:5120',
            'password'            => 'required|string|min:6|max:60|confirmed',
        ], [
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        $karyawan = Karyawan::find($data['karyawan_id']);

        // Re-verifikasi HP cocok (jangan percaya hidden id saja).
        if (! $karyawan || normalize_phone($karyawan->hp) === ''
            || normalize_phone($karyawan->hp) !== normalize_phone($data['hp'])) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['hp' => 'Verifikasi nomor HP gagal. Ulangi dari awal.']);
        }
        if ($this->hasKaryawanAccount($karyawan)) {
            return back()->withErrors(['hp' => 'Karyawan ini sudah punya akun.']);
        }

        $username = normalize_phone($karyawan->hp);
        if (User::where('username', $username)->exists()) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['hp' => 'Nomor HP ini sudah terpakai sebagai akun lain. Hubungi admin.']);
        }

        DB::transaction(function () use ($request, $data, $karyawan, $username) {
            // Simpan data pribadi ke master karyawan (hanya field terisi).
            $karyawan->fill(array_filter([
                'email'               => $data['email'] ?? null,
                'alamat'              => $data['alamat'] ?? null,
                'nik'                 => $data['nik'] ?? null,
                'npwp'                => $data['npwp'] ?? null,
                'bank_name'           => $data['bank_name'] ?? null,
                'bank_account_number' => $data['bank_account_number'] ?? null,
                'bank_account_holder' => $data['bank_account_holder'] ?? null,
                'mulai_kerja'         => $data['mulai_kerja'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));

            if ($request->hasFile('foto')) {
                $karyawan->foto_path = $request->file('foto')->store('karyawan/foto', 'public');
            }
            if ($request->hasFile('ktp')) {
                $karyawan->ktp_path = $request->file('ktp')->store('karyawan/ktp', 'public');
            }
            $karyawan->save();

            User::create([
                'username'    => $username,
                'name'        => $karyawan->name,
                'email'       => $data['email'] ?? null,
                'password'    => Hash::make($data['password']),
                'role'        => 'karyawan',
                'is_active'   => true,
                'karyawan_id' => $karyawan->id,
            ]);
        });

        return redirect()->route('login')
            ->with('status', 'Pendaftaran berhasil! Silakan login dengan No. HP ' . $username . ' dan password Anda.');
    }

    /** Cari karyawan aktif yang HP-nya cocok (ternormalisasi). */
    private function matchByPhone(string $hp): ?Karyawan
    {
        $norm = normalize_phone($hp);
        if ($norm === '') return null;

        return Karyawan::where('is_active', true)->get()
            ->first(fn ($k) => normalize_phone($k->hp) === $norm);
    }

    private function hasKaryawanAccount(Karyawan $karyawan): bool
    {
        // Unique (karyawan_id, role): 1 karyawan boleh punya akun kerja (user) DAN
        // akun absensi (karyawan). Di sini cek khusus akun absensi.
        return User::where('karyawan_id', $karyawan->id)->where('role', 'karyawan')->exists();
    }
}
