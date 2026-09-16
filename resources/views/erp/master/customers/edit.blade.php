@extends('layouts.erp')

@section('content')

<div class="max-w-4xl mx-auto">

<h1 class="text-lg font-semibold mb-4">Edit Pelanggan &mdash; {{ $customer->name }}</h1>

<form method="POST" action="{{ route('customers.update', $customer->id) }}">
    @method('PUT')
    @include('erp.master.customers._form')
</form>

{{--
    Cabang SENGAJA di luar form di atas: tombolnya sendiri berupa form
    (arsip/hapus), dan form bersarang tidak sah di HTML — yang di dalam akan
    diabaikan browser tanpa pesan kesalahan apa pun.
--}}
<div class="mt-10 border-t pt-6">

    <div class="flex items-start justify-between gap-4 mb-3">
        <div>
            <h2 class="text-sm font-bold text-gray-700">Cabang / Alamat Kirim Lain</h2>
            <p class="text-xs text-gray-400 mt-1">
                Untuk pelanggan yang barangnya dikirim ke beberapa tempat tapi tagihannya tetap satu.
                Tagihan, piutang, dan termin tetap atas nama {{ $customer->name }}.
            </p>
        </div>
        <a href="{{ route('customers.branches.create', $customer->id) }}"
            class="shrink-0 text-sm border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded">
            + Tambah Cabang
        </a>
    </div>

    @php $cabangList = $customer->branches; @endphp

    @if ($cabangList->isEmpty())
        <p class="text-sm text-gray-400 border rounded px-3 py-4 text-center">
            Belum ada cabang. Pesanan untuk pelanggan ini memakai alamat pengiriman di atas.
        </p>
    @else
        <table class="w-full text-sm border">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-3 py-2 font-medium">Nama Cabang</th>
                    <th class="text-left px-3 py-2 font-medium">Alamat</th>
                    <th class="text-left px-3 py-2 font-medium">Kontak</th>
                    <th class="text-right px-3 py-2 font-medium">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cabangList as $cabang)
                    <tr class="border-t {{ $cabang->is_active ? '' : 'bg-gray-50 text-gray-400' }}">
                        <td class="px-3 py-2 align-top">
                            {{ $cabang->name }}
                            @unless ($cabang->is_active)
                                <span class="block text-xs">Diarsipkan</span>
                            @endunless
                        </td>
                        <td class="px-3 py-2 align-top">{{ $cabang->fullAddress() ?: '—' }}</td>
                        <td class="px-3 py-2 align-top">
                            {{ $cabang->pic_name ?: '—' }}
                            <span class="block text-xs text-gray-500">
                                Kabar: {{ $cabang->phone ?: 'ikut pusat' }}
                            </span>
                            <span class="block text-xs text-gray-500">
                                Kurir: {{ $cabang->recipient_phone ?: ($cabang->phone ?: 'ikut pusat') }}
                            </span>
                        </td>
                        <td class="px-3 py-2 align-top text-right whitespace-nowrap">
                            <a href="{{ route('customers.branches.edit', [$customer->id, $cabang->id]) }}"
                                class="text-blue-600 hover:underline">Edit</a>

                            @if ($cabang->is_active)
                                <form method="POST" action="{{ route('customers.branches.archive', [$customer->id, $cabang->id]) }}" class="inline">
                                    @csrf
                                    <button class="text-gray-500 hover:underline ml-2">Arsipkan</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('customers.branches.restore', [$customer->id, $cabang->id]) }}" class="inline">
                                    @csrf
                                    <button class="text-gray-500 hover:underline ml-2">Aktifkan</button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('customers.branches.destroy', [$customer->id, $cabang->id]) }}" class="inline"
                                onsubmit="return confirm('Hapus cabang {{ $cabang->name }} permanen?')">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline ml-2">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

</div>

</div>

@endsection
