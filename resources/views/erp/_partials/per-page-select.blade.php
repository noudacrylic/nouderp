{{-- Pemilih jumlah baris per halaman (20/50/100/200). Pakai di dalam <form method="GET">
     filter index; class filter-auto membuatnya auto-submit saat diganti. --}}
<div>
    <label class="block text-xs text-gray-500 mb-1">Per Halaman</label>
    <select name="per_page" class="filter-auto border rounded px-2 py-1.5">
        @foreach([20, 50, 100, 200] as $pp)
            <option value="{{ $pp }}" @selected((int) request('per_page', 20) === $pp)>{{ $pp }}</option>
        @endforeach
    </select>
</div>
