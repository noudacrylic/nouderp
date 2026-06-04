@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Bayar Gaji</h1>
    <a href="{{ route('finance.cash-bank.salary-payments.create') }}"
       class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Bayar Gaji</a>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

@php
    $bulanNama = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
@endphp

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Nomor / nama karyawan"
               class="border rounded px-2 py-1.5 w-64">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Periode Bulan</label>
        <select name="periode_bulan" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($bulanNama as $b => $label)
                <option value="{{ $b }}" @selected(request('periode_bulan') == $b)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Periode Tahun</label>
        <input type="number" name="periode_tahun" value="{{ request('periode_tahun') }}" min="2020" max="2100" class="border rounded px-2 py-1.5 w-24">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="draft" @selected(request('status')=='draft')>Draft</option>
            <option value="posted" @selected(request('status')=='posted')>Posted</option>
            <option value="void" @selected(request('status')=='void')>Void</option>
        </select>
    </div>
    <div>
        <button type="submit" class="bg-gray-600 text-white px-3 py-1.5 rounded">Filter</button>
        <a href="{{ route('finance.cash-bank.salary-payments.index') }}" class="text-gray-500 ml-1 text-xs">Reset</a>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Tgl Bayar</th>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-left">Periode</th>
                <th class="px-3 py-2 text-left">Kas/Bank</th>
                <th class="px-3 py-2 text-right">Bruto</th>
                <th class="px-3 py-2 text-right">Potongan</th>
                <th class="px-3 py-2 text-right">Nett</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-56">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $sp)
                @php
                    $stCls = match($sp->status) {
                        'draft'  => 'bg-yellow-100 text-yellow-700',
                        'posted' => 'bg-green-100 text-green-700',
                        'void'   => 'bg-red-100 text-red-700',
                        default  => 'bg-gray-100 text-gray-700',
                    };
                    $potongan = (float) $sp->bpjs_kes_employee + (float) $sp->bpjs_tk_employee + (float) $sp->pph21_amount + (float) $sp->kasbon_potongan;
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('finance.cash-bank.salary-payments.show', $sp->id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $sp->number }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $sp->date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $sp->karyawan->name ?? '-' }} <span class="text-xs text-gray-400">{{ $sp->karyawan->staf_code ?? '' }}</span></td>
                    <td class="px-3 py-2">{{ $sp->periodeLabel() }}</td>
                    <td class="px-3 py-2 text-xs">{{ $sp->cashAccount->code ?? '' }} {{ $sp->cashAccount->name ?? '' }}</td>
                    <td class="px-3 py-2 text-right">{{ rupiah($sp->bruto_gaji) }}</td>
                    <td class="px-3 py-2 text-right text-red-600">{{ $potongan > 0 ? '−' . rupiah($potongan) : '—' }}</td>
                    <td class="px-3 py-2 text-right font-semibold">{{ rupiah($sp->nett_dibayar) }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $sp->status }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @if($sp->isDraft())
                                <form method="POST" action="{{ route('finance.cash-bank.salary-payments.post', $sp->id) }}" class="inline"
                                      onsubmit="return confirm('Post pembayaran {{ $sp->number }}?')">
                                    @csrf
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <a href="{{ route('finance.cash-bank.salary-payments.edit', $sp->id) }}"
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                                <form method="POST" action="{{ route('finance.cash-bank.salary-payments.destroy', $sp->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus draft {{ $sp->number }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($sp->isPosted() && $sp->canBeVoided())
                                <form method="POST" action="{{ route('finance.cash-bank.salary-payments.void', $sp->id) }}" class="inline"
                                      onsubmit="return confirm('Void pembayaran {{ $sp->number }}? Jurnal di-void & kasbon dikembalikan.')">
                                    @csrf
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Belum ada pembayaran gaji.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($items instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $items->links() }}</div>
@endif

<script>
    document.querySelectorAll('tr[data-href]').forEach(tr => {
        tr.addEventListener('click', () => window.location.href = tr.dataset.href);
    });
</script>
@endsection
