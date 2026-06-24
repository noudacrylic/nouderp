<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1e3a8a">
    <title>Daftar Akun Karyawan — NOUD</title>
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f1f5f9; min-height:100vh; }
    </style>
</head>
<body class="flex items-start sm:items-center justify-center p-4">

    <div class="w-full max-w-md py-6">
        <div class="text-center mb-6">
            <div class="inline-block bg-yellow-400 text-blue-900 font-extrabold text-xl px-5 py-2 rounded-lg shadow">
                NOUD Karyawan
            </div>
            <p class="text-slate-500 text-sm mt-2">Daftarkan akun untuk akses absensi &amp; slip gaji</p>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">

            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2.5 rounded-lg text-sm mb-4">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2.5 rounded-lg text-sm mb-4">
                    {{ $errors->first() }}
                </div>
            @endif

            @if (! $karyawan)
                {{-- ───── Langkah 1: cek No. HP ───── --}}
                <h1 class="text-lg font-bold text-slate-800 mb-1">Buat akun</h1>
                <p class="text-sm text-slate-500 mb-5">Masukkan nomor HP yang terdaftar di kantor untuk memulai.</p>

                <form method="POST" action="{{ route('me.register.check') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Nomor HP</label>
                        <input type="tel" name="hp" value="{{ old('hp') }}" required inputmode="tel" autofocus
                               class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                               placeholder="08xxxxxxxxxx">
                        <p class="text-[11px] text-slate-400 mt-1">Harus sama dengan nomor HP di data karyawan.</p>
                    </div>
                    <button type="submit" class="w-full bg-blue-700 hover:bg-blue-800 text-white font-bold py-2.5 rounded-lg transition shadow-md">
                        Lanjut
                    </button>
                </form>

            @else
                {{-- ───── Langkah 2: data pribadi + password ───── --}}
                <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 mb-4">
                    <p class="text-xs text-emerald-700">Data karyawan ditemukan:</p>
                    <p class="font-bold text-emerald-900">{{ $karyawan->name }}</p>
                    <p class="text-xs text-emerald-700">Kode Karyawan: <span class="font-mono font-semibold">{{ $karyawan->staf_code }}</span></p>
                </div>

                <p class="text-sm text-slate-500 mb-4">Lengkapi data pribadi &amp; buat password. Login nanti pakai No. HP.</p>

                <form method="POST" action="{{ route('me.register.submit') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <input type="hidden" name="karyawan_id" value="{{ $karyawan->id }}">
                    <input type="hidden" name="hp" value="{{ $karyawan->hp }}">

                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email', $karyawan->email) }}"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Alamat</label>
                        <textarea name="alamat" rows="2" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">{{ old('alamat', $karyawan->alamat) }}</textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">NIK (KTP)</label>
                            <input type="text" name="nik" value="{{ old('nik', $karyawan->nik) }}" inputmode="numeric"
                                   class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">NPWP</label>
                            <input type="text" name="npwp" value="{{ old('npwp', $karyawan->npwp) }}"
                                   class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tanggal Mulai Kerja</label>
                        <input type="date" name="mulai_kerja" value="{{ old('mulai_kerja', optional($karyawan->mulai_kerja)->format('Y-m-d')) }}"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                    </div>

                    <hr class="border-slate-100">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Rekening Bank (untuk gaji)</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Nama Bank</label>
                            <input type="text" name="bank_name" value="{{ old('bank_name', $karyawan->bank_name) }}"
                                   class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm" placeholder="mis. BRI">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">No. Rekening</label>
                            <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $karyawan->bank_account_number) }}" inputmode="numeric"
                                   class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Nama Pemilik Rekening</label>
                        <input type="text" name="bank_account_holder" value="{{ old('bank_account_holder', $karyawan->bank_account_holder) }}"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                    </div>

                    <hr class="border-slate-100">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Foto Profil</label>
                            <input type="file" name="foto" accept="image/*" capture="user"
                                   class="w-full text-xs text-slate-600 file:mr-2 file:py-2 file:px-2 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:text-xs file:font-semibold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Foto KTP</label>
                            <input type="file" name="ktp" accept="image/*" capture="environment"
                                   class="w-full text-xs text-slate-600 file:mr-2 file:py-2 file:px-2 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:text-xs file:font-semibold">
                        </div>
                    </div>

                    <hr class="border-slate-100">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Password (buat sendiri)</label>
                        <input type="password" name="password" required minlength="6" autocomplete="new-password"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Ulangi Password</label>
                        <input type="password" name="password_confirmation" required minlength="6" autocomplete="new-password"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm">
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-lg transition shadow-md">
                        Daftar &amp; Aktifkan Akun
                    </button>
                    <a href="{{ route('me.register') }}" class="block text-center text-xs text-slate-400">← Ganti nomor HP</a>
                </form>
            @endif

            <p class="text-center text-sm text-slate-500 mt-5">
                Sudah punya akun?
                <a href="{{ route('login') }}" class="text-blue-700 font-semibold">Masuk di sini</a>
            </p>
        </div>

        <p class="text-center text-slate-400 text-xs mt-6">
            © {{ date('Y') }} Noud Acrylic — Internal Use Only
        </p>
    </div>

</body>
</html>
