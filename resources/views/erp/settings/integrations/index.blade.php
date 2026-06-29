@extends('layouts.erp')

@section('content')
<div class="max-w-6xl mx-auto py-4">

    <div class="mb-5">
        <h1 class="text-lg font-semibold text-gray-800">Integrasi</h1>
        <p class="text-sm text-gray-500 mt-0.5">Aplikasi pihak ketiga yang terhubung dengan Noud ERP. Klik <b>Konfigurasi</b> untuk mengatur kredensial tiap layanan.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($integrations as $i)
            <a href="{{ $i['url'] }}"
               class="group bg-white rounded-lg shadow border border-gray-100 p-4 hover:shadow-md hover:border-blue-200 transition flex flex-col">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <div class="text-2xl leading-none w-10 h-10 flex items-center justify-center bg-gray-50 rounded-lg">{{ $i['icon'] }}</div>
                        <div>
                            <div class="font-semibold text-gray-800">{{ $i['name'] }}</div>
                            <div class="text-[11px] text-gray-400">{{ $i['category'] }}</div>
                        </div>
                    </div>
                    @if($i['active'])
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-green-100 text-green-700">Aktif</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-gray-200 text-gray-500">Nonaktif</span>
                    @endif
                </div>

                <p class="text-xs text-gray-500 mt-3 flex-1">{{ $i['description'] }}</p>

                <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-50">
                    <span class="text-[11px] {{ $i['active'] ? 'text-gray-400' : 'text-transparent' }}">
                        Mode: {{ $i['mode'] }}
                    </span>
                    <span class="text-xs font-semibold text-blue-600 group-hover:underline">Konfigurasi →</span>
                </div>
            </a>
        @endforeach
    </div>

    <p class="text-xs text-gray-400 mt-5">Integrasi lain (Kirimin Aja, Jubelio, WooCommerce) akan menyusul.</p>
</div>
@endsection
