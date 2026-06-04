<div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <div class="md:col-span-1">
        <label class="block text-[10px] uppercase font-bold text-gray-400 mb-1">Search</label>
        <input type="text" id="search"
            class="w-full border border-gray-200 rounded px-3 py-1.5 text-xs focus:ring-2 focus:ring-blue-500 outline-none transition"
            placeholder="Invoice # or Customer..." value="{{ request('search') }}">
    </div>
    <div class="md:col-span-1">
        <label class="block text-[10px] uppercase font-bold text-gray-400 mb-1">From</label>
        <input type="date" id="date_from" class="w-full border border-gray-200 rounded px-3 py-1.5 text-xs outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="md:col-span-1">
        <label class="block text-[10px] uppercase font-bold text-gray-400 mb-1">To</label>
        <input type="date" id="date_to" class="w-full border border-gray-200 rounded px-3 py-1.5 text-xs outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <div class="md:col-span-1">
        <label class="block text-[10px] uppercase font-bold text-gray-400 mb-1">Status</label>
        <select id="status" class="w-full border border-gray-200 rounded px-3 py-1.5 text-xs bg-white outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Semua Status</option>
            <option value="draft">Draft</option>
            <option value="belum_lunas">Belum Lunas</option>
            <option value="selesai">Selesai</option>
            <option value="void">Void</option>
        </select>
    </div>
</div>
