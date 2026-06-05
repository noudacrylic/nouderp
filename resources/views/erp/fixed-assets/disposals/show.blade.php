@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Disposisi {{ $disposal->disposal_number }}</h1>
        <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-600">{{ $disposal->status }}</span>
    </div>
    <div class="flex gap-1 flex-row-reverse">
        @if($disposal->canBeVoided())
            <form method="POST" action="{{ route('fixed-assets.disposals.void', $disposal->id) }}" onsubmit="return confirm('Void disposisi ini? Aset akan kembali aktif.')">@csrf
                <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Void</button>
            </form>
        @endif
        @if($disposal->status === 'draft')
            <form method="POST" action="{{ route('fixed-assets.disposals.post', $disposal->id) }}" onsubmit="return confirm('Post disposisi?')">@csrf
                <button class="bg-green-600 text-white px-3 py-2 rounded text-sm">Post</button>
            </form>
            <a href="{{ route('fixed-assets.disposals.edit', $disposal->id) }}" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Edit</a>
        @endif
        <a href="{{ route('fixed-assets.disposals.print', $disposal->id) }}" class="bg-gray-500 text-white px-3 py-2 rounded text-sm">Cetak</a>
        <a href="{{ route('fixed-assets.disposals.index') }}" class="text-gray-500 text-sm underline self-center">← Kembali</a>
    </div>
</div>

@if(session('success')) <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded mb-3 text-sm">{{ session('success') }}</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<div class="bg-white rounded shadow p-4 mb-4">
    <table class="w-full text-sm">
        <tbody>
            <tr><td class="py-1 text-gray-500 w-48">Aset</td><td class="py-1"><a href="{{ route('fixed-assets.show', $disposal->fixed_asset_id) }}" class="text-blue-600 underline">{{ $disposal->asset->asset_code ?? '-' }} — {{ $disposal->asset->name ?? '-' }}</a></td></tr>
            <tr><td class="py-1 text-gray-500">Tanggal</td><td class="py-1">{{ optional($disposal->disposal_date)->format('d M Y') }}</td></tr>
            <tr><td class="py-1 text-gray-500">Tipe</td><td class="py-1">{{ ucfirst($disposal->disposal_type) }}</td></tr>
            <tr><td class="py-1 text-gray-500">Nilai Jual / Penerimaan</td><td class="py-1">{{ number_format($disposal->proceeds_amount, 2, ',', '.') }}</td></tr>
            <tr><td class="py-1 text-gray-500">Akun Penerimaan</td><td class="py-1">{{ $disposal->proceedsAccount->code ?? '-' }} {{ $disposal->proceedsAccount->name ?? '' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Nilai Buku saat Disposisi</td><td class="py-1">{{ number_format($disposal->book_value_at_disposal, 2, ',', '.') }}</td></tr>
            <tr><td class="py-1 text-gray-500">Gain/Loss</td><td class="py-1 {{ $disposal->gain_loss_amount >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ number_format($disposal->gain_loss_amount, 2, ',', '.') }}</td></tr>
            @if($disposal->notes) <tr><td class="py-1 text-gray-500 align-top">Catatan</td><td class="py-1 whitespace-pre-line">{{ $disposal->notes }}</td></tr> @endif
        </tbody>
    </table>
</div>

@if($disposal->journal)
<div class="bg-white rounded shadow p-4">
    <h2 class="font-semibold mb-3">Jurnal Disposisi</h2>
    <p class="text-sm text-gray-500 mb-2">{{ $disposal->journal->journal_number }} — Status: {{ $disposal->journal->status }}</p>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Akun</th>
                <th class="px-3 py-2 text-left">Deskripsi</th>
                <th class="px-3 py-2 text-right">Debit</th>
                <th class="px-3 py-2 text-right">Kredit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($disposal->journal->lines as $l)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $l->account->code ?? '-' }} — {{ $l->account->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $l->description }}</td>
                    <td class="px-3 py-2 text-right">{{ $l->debit > 0 ? number_format($l->debit, 2, ',', '.') : '' }}</td>
                    <td class="px-3 py-2 text-right">{{ $l->credit > 0 ? number_format($l->credit, 2, ',', '.') : '' }}</td>
                </tr>
            @endforeach
            <tr class="font-semibold">
                <td class="px-3 py-2" colspan="2">TOTAL</td>
                <td class="px-3 py-2 text-right">{{ number_format($disposal->journal->lines->sum('debit'), 2, ',', '.') }}</td>
                <td class="px-3 py-2 text-right">{{ number_format($disposal->journal->lines->sum('credit'), 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endif
@endsection
