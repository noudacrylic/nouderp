@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Bayar Gaji — {{ $sp->number }}</h1>
        <div class="text-xs text-gray-500">{{ $sp->periodeLabel() }} · dibayar {{ $sp->date->format('d M Y') }}</div>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('finance.cash-bank.salary-payments.index') }}" class="text-sm text-gray-600 hover:underline">← Kembali</a>
        @if($sp->isDraft())
            <a href="{{ route('finance.cash-bank.salary-payments.edit', $sp->id) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm">Edit</a>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif

@php
    $stCls = match($sp->status) {
        'draft'  => 'bg-yellow-100 text-yellow-700',
        'posted' => 'bg-green-100 text-green-700',
        'void'   => 'bg-red-100 text-red-700',
        default  => 'bg-gray-100 text-gray-700',
    };
    $potongan = (float) $sp->bpjs_kes_employee + (float) $sp->bpjs_tk_employee + (float) $sp->pph21_amount + (float) $sp->kasbon_potongan;
@endphp

<div class="grid grid-cols-3 gap-4 mb-4">
    <div class="bg-white rounded shadow p-3 text-sm">
        <div class="text-xs text-gray-500">Karyawan</div>
        <div class="font-semibold">{{ $sp->karyawan->name ?? '-' }}</div>
        <div class="text-xs text-gray-400">{{ $sp->karyawan->staf_code ?? '' }}</div>
    </div>
    <div class="bg-white rounded shadow p-3 text-sm">
        <div class="text-xs text-gray-500">Akun Kas/Bank</div>
        <div class="font-semibold">{{ $sp->cashAccount->code ?? '' }} {{ $sp->cashAccount->name ?? '' }}</div>
    </div>
    <div class="bg-white rounded shadow p-3 text-sm">
        <div class="text-xs text-gray-500">Status</div>
        <div><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $sp->status }}</span></div>
    </div>
</div>

<div class="bg-white rounded shadow p-4">
    <table class="w-full text-sm">
        <tbody>
            <tr class="border-b">
                <td class="px-3 py-2 text-gray-600 font-semibold">Bruto Gaji</td>
                <td class="px-3 py-2 text-right font-mono">{{ rupiah($sp->bruto_gaji) }}</td>
            </tr>
            <tr class="border-b">
                <td class="px-3 py-2 text-gray-600">BPJS Kesehatan (potong karyawan)</td>
                <td class="px-3 py-2 text-right font-mono text-red-600">{{ (float) $sp->bpjs_kes_employee > 0 ? '−' . rupiah($sp->bpjs_kes_employee) : '—' }}</td>
            </tr>
            <tr class="border-b">
                <td class="px-3 py-2 text-gray-600">BPJS Ketenagakerjaan (potong karyawan)</td>
                <td class="px-3 py-2 text-right font-mono text-red-600">{{ (float) $sp->bpjs_tk_employee > 0 ? '−' . rupiah($sp->bpjs_tk_employee) : '—' }}</td>
            </tr>
            <tr class="border-b">
                <td class="px-3 py-2 text-gray-600">PPh 21</td>
                <td class="px-3 py-2 text-right font-mono text-red-600">{{ (float) $sp->pph21_amount > 0 ? '−' . rupiah($sp->pph21_amount) : '—' }}</td>
            </tr>
            <tr class="border-b">
                <td class="px-3 py-2 text-gray-600">Potongan Kasbon</td>
                <td class="px-3 py-2 text-right font-mono text-red-600">{{ (float) $sp->kasbon_potongan > 0 ? '−' . rupiah($sp->kasbon_potongan) : '—' }}</td>
            </tr>
            <tr class="bg-emerald-50">
                <td class="px-3 py-3 font-bold">Nett Dibayarkan</td>
                <td class="px-3 py-3 text-right font-mono font-bold text-base">{{ rupiah($sp->nett_dibayar) }}</td>
            </tr>
            <tr class="border-t bg-blue-50">
                <td class="px-3 py-2 text-gray-500 text-xs italic">Iuran perusahaan (informasional)</td>
                <td class="px-3 py-2 text-right font-mono text-xs text-gray-600">
                    BPJS Kes Rp {{ rupiah($sp->bpjs_kes_company) }} + TK Rp {{ rupiah($sp->bpjs_tk_company) }}
                </td>
            </tr>
        </tbody>
    </table>

    @if($sp->notes)
        <div class="mt-4 text-sm">
            <div class="text-xs text-gray-500 uppercase mb-1">Catatan</div>
            <div class="text-gray-700 whitespace-pre-wrap">{{ $sp->notes }}</div>
        </div>
    @endif

    @if($sp->journal)
        <div class="mt-4 text-xs text-gray-500">
            Jurnal: <span class="font-mono">{{ $sp->journal->journal_number }}</span> · diposting {{ $sp->posted_at?->format('d M Y H:i') }}
        </div>
    @endif
</div>

<div class="flex gap-2 mt-4 justify-end">
    @if($sp->isDraft())
        <form method="POST" action="{{ route('finance.cash-bank.salary-payments.post', $sp->id) }}"
              onsubmit="return confirm('Post pembayaran {{ $sp->number }}? Jurnal akan dibuat.')">
            @csrf
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-semibold">Post Jurnal</button>
        </form>
    @elseif($sp->isPosted() && $sp->canBeVoided())
        <form method="POST" action="{{ route('finance.cash-bank.salary-payments.void', $sp->id) }}"
              onsubmit="return confirm('Void pembayaran {{ $sp->number }}? Jurnal di-void & kasbon dikembalikan.')">
            @csrf
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm">Void</button>
        </form>
    @endif
</div>
@endsection
