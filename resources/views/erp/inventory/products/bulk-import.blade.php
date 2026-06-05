@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Upload Produk via Excel</h1>
    <a href="{{ route('inventory.products.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← Daftar Produk</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    {{-- ============ Form upload ============ --}}
    <div class="lg:col-span-2">
        <form method="POST" action="{{ route('inventory.products.bulk-import-store') }}" enctype="multipart/form-data">
            @csrf
            <div class="bg-white rounded shadow p-4 space-y-4">
                @if(session('error') || $errors->any())
                    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm">
                        {{ session('error') }}
                        @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
                    </div>
                @endif

                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-3 py-3 rounded text-sm">
                    <div class="font-semibold mb-1">📋 Format Excel (6 kolom):</div>
                    <code class="block bg-white rounded px-2 py-1 text-xs font-mono">sku | name | sale_type | base_unit | base_price | cost_price</code>
                    <div class="text-xs mt-2">
                        Hanya <b>name</b> yang wajib. SKU kosong → auto-generate.
                        Stok awal & akun jurnal di-setup terpisah lewat halaman <b>Setup Produk</b> per produk.
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">File Excel <span class="text-red-500">*</span></label>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" class="border rounded px-2 py-1.5 w-full" required>
                </div>

                <div class="flex justify-end border-t pt-3">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">
                        Upload & Buat Produk
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- ============ Sidebar: Download template & petunjuk ============ --}}
    <div>
        <div class="bg-white rounded shadow p-4 space-y-3">
            <div class="font-semibold text-sm">📥 Download</div>
            <a href="{{ route('inventory.products.bulk-import-template') }}"
               class="block bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm text-center font-semibold">
                ⬇ Template Excel (.xlsx)
            </a>

            <div class="border-t pt-3 text-xs text-gray-600 space-y-1.5">
                <div class="font-semibold">Kolom Excel:</div>
                <ul class="list-disc pl-4 space-y-1">
                    <li><b>sku</b> (opsional) — kosongkan untuk auto-generate</li>
                    <li><b>name</b> (wajib) — nama produk</li>
                    <li><b>sale_type</b> — ready / preorder / bundle / service / non_stock (default: ready)</li>
                    <li><b>base_unit</b> — satuan dasar (default: pcs)</li>
                    <li><b>base_price</b> — harga jual (Rp)</li>
                    <li><b>cost_price</b> — harga pokok / referensi pembelian (Rp)</li>
                </ul>
            </div>

            <div class="border-t pt-3 text-xs text-amber-700 bg-amber-50 rounded p-2">
                <b>Catatan:</b> SKU yang sudah ada akan di-skip (tidak overwrite). Untuk update produk existing, gunakan halaman edit per produk.
            </div>
        </div>
    </div>
</div>

{{-- ============ Tampilkan detail skip/error kalau ada ============ --}}
@if(session('import_skipped') || session('import_errors'))
    <div class="bg-white rounded shadow p-4 mt-4 text-sm">
        @if(session('import_skipped'))
            <div class="font-semibold text-amber-700 mb-2">⚠ Baris di-skip ({{ count(session('import_skipped')) }}):</div>
            <ul class="list-disc pl-5 text-xs text-gray-700 mb-3">
                @foreach(session('import_skipped') as $s)
                    <li>{{ $s }}</li>
                @endforeach
            </ul>
        @endif
        @if(session('import_errors'))
            <div class="font-semibold text-red-700 mb-2">❌ Error ({{ count(session('import_errors')) }}):</div>
            <ul class="list-disc pl-5 text-xs text-gray-700">
                @foreach(session('import_errors') as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
@endsection
