<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect(user_landing_url());
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'Username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        $attempt = Auth::attempt([
            'username'  => $data['username'],
            'password'  => $data['password'],
            'is_active' => true,
        ], remember: (bool) $request->boolean('remember'));

        if (!$attempt) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Username atau password salah, atau akun tidak aktif.']);
        }

        $request->session()->regenerate();

        // Update last_login_at
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(user_landing_url());
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
