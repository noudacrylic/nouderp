<form method="GET" action="{{ route('sales.quotations.index') }}" id="filter-form" class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Cari</label>
        <input 
            type="text"
            name="search"
            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition"
            placeholder="No. Penawaran atau Customer..."
            value="{{ request('search') }}">
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Dari Tanggal</label>
        <input 
            type="date"
            name="from"
            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition"
            value="{{ request('from') }}">
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Sampai Tanggal</label>
        <input 
            type="date"
            name="to"
            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition"
            value="{{ request('to') }}">
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Status</label>
        <select 
            name="status"
            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition appearance-none bg-no-repeat bg-right"
            style="background-image: url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns=%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22 fill=%22none%22 viewBox=%220%200%2020%2020%22%3E%3Cpath stroke=%22%236b7280%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22 stroke-width=%221.5%22 d=%22m6%208%204%204%204-4%22%2F%3E%3C%2Fsvg%3E'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <option value="">Semua Status</option>
            <option value="confirmed" {{ request('status') == 'confirmed' ? 'selected' : '' }}>CONFIRMED</option>
            <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>DRAFT</option>
            <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>CANCELLED</option>
        </select>
    </div>
</form>
