<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — NOUD ERP</title>
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #ffffff;
            min-height: 100vh;
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <div class="inline-block bg-yellow-400 text-blue-900 font-extrabold text-2xl px-5 py-2 rounded-lg shadow">
                NOUD ERP
            </div>
            <p class="text-gray-500 text-sm mt-2 tracking-widest">ENTERPRISE SOLUTIONS</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8">
            <h1 class="text-xl font-bold text-gray-800 mb-1">Selamat datang</h1>
            <p class="text-sm text-gray-500 mb-6">Silakan login untuk melanjutkan</p>

            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-4">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Username / No. HP</label>
                    <input type="text"
                           name="username"
                           value="{{ old('username') }}"
                           autofocus
                           required
                           autocomplete="username"
                           placeholder="Username admin atau No. HP karyawan"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Password</label>
                    <input type="password"
                           name="password"
                           required
                           autocomplete="current-password"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                </div>

                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" name="remember" value="1" class="rounded">
                    Ingat saya
                </label>

                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-lg transition shadow-md">
                    Login
                </button>
            </form>

            <p class="mt-5 text-center text-xs text-gray-400">
                Karyawan baru? <a href="{{ route('me.register') }}" class="text-blue-600 hover:underline font-medium">Register</a>
            </p>
        </div>

        <p class="text-center text-gray-400 text-xs mt-6">
            © {{ date('Y') }} Noud Acrylic — Internal Use Only
        </p>
    </div>

</body>
</html>
