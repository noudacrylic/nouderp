@extends('layouts.erp')

@section('content')

<div class="max-w-5xl mx-auto" id="adj-form-root">

    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-xl font-bold text-gray-800">Penyesuaian Stok</h2>
            <p class="text-xs text-gray-500 mt-0.5">Buat penyesuaian stok baru sebagai draft</p>
        </div>
        <a href="{{ route('inventory.adjustments.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Kembali</a>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-3 rounded-xl mb-4 text-sm font-medium">⚠ {{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('inventory.adjustments.store') }}">
        @csrf
        <input type="hidden" name="direction" id="hidden-direction" value="set_stock">

        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 space-y-5 mb-5">

            {{-- Row 1: Tujuan + Tanggal + Gudang --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Tujuan Penyesuaian *</label>
                    <select name="purpose" id="purpose-select"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" required>
                        <option value="stock_opname">Stock Opname</option>
                        <option value="perbaikan_rusak">Perbaikan Stock Rusak</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Tanggal *</label>
                    <input type="date" name="date" value="{{ date('Y-m-d') }}"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" required>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Gudang *</label>
                    @php $whSelected = old('warehouse_id', \App\Core\Inventory\Warehouse::defaultId()); @endphp
                    <select name="warehouse_id" id="warehouse_id"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" required>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" {{ $whSelected == $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Row 2: Akun - hanya tampil untuk Stock Opname --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="accounts-row">

                <div id="income-account-wrap">
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">
                        Akun Pendapatan Selisih Stok *
                    </label>
                    <select name="income_account_id"
                            class="account-select w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <option value="">— Pilih akun pendapatan —</option>
                        @foreach ($incomeAccounts as $acc)
                            <option value="{{ $acc->id }}"
                                {{ (old('income_account_id', $defaultIncome?->id)) == $acc->id ? 'selected' : '' }}>
                                {{ $acc->code }} — {{ $acc->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div id="expense-account-wrap">
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">
                        Akun Beban Selisih Stok *
                    </label>
                    <select name="expense_account_id"
                            class="account-select w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <option value="">— Pilih akun beban —</option>
                        @foreach ($expenseAccounts as $acc)
                            <option value="{{ $acc->id }}"
                                {{ (old('expense_account_id', $defaultExpense?->id)) == $acc->id ? 'selected' : '' }}>
                                {{ $acc->code }} — {{ $acc->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Row 3: Penanggung Jawab + Catatan --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Penanggung Jawab</label>
                    <input type="text" name="responsible" value="{{ $authName }}"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-gray-50 text-gray-600" readonly>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Catatan</label>
                    <input type="text" name="notes" placeholder="Catatan tambahan..." value="{{ old('notes') }}"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                </div>
            </div>

        </div>

        {{-- Items Table --}}
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden mb-5">

            <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                <span class="font-bold text-gray-800 text-sm">
                    Item Penyesuaian
                    <span id="perbaikan-warning" class="text-xs font-normal text-amber-600 ml-2" style="display:none">⚠ Perbaikan hanya boleh 1 produk</span>
                </span>
                <button type="button" id="add-item-btn"
                        onclick="addItem()"
                        class="text-blue-600 text-sm font-bold hover:underline">+ Tambah Item</button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="px-4 py-3 text-left font-black text-[10px] text-gray-400 uppercase tracking-widest">Produk</th>
                            <th class="px-4 py-3 text-right font-black text-[10px] text-gray-400 uppercase tracking-widest w-24">Stok Sistem</th>
                            <th class="px-4 py-3 text-right font-black text-[10px] text-gray-400 uppercase tracking-widest w-32">Qty Aktual</th>
                            <th class="px-4 py-3 text-right font-black text-[10px] text-gray-400 uppercase tracking-widest w-24">Selisih</th>
                            <th class="px-4 py-3 text-left font-black text-[10px] text-gray-400 uppercase tracking-widest">Keterangan</th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody id="items-table">
                        <tr class="item-row border-b border-gray-50 last:border-0 hover:bg-gray-50/50 transition">
                            <td class="px-3 py-2">
                                <select name="items[0][product_id]" class="product-select w-full border border-gray-200 rounded-lg px-2 py-2 text-sm" required>
                                    <option value="">— Pilih produk —</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-right text-gray-500">
                                <span class="system-qty font-mono">0</span>
                                <input type="hidden" name="items[0][system]" class="system-value" value="0">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" step="0.0001" name="items[0][actual]" value="0"
                                       class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm text-right font-mono actual-input" required>
                            </td>
                            <td class="px-3 py-2 text-right font-bold font-mono diff-qty text-gray-700">0</td>
                            <td class="px-3 py-2">
                                <input type="text" name="items[0][notes]" placeholder="Rusak / Hilang / ..."
                                       class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm">
                            </td>
                            <td class="px-2 py-2 text-center text-red-400 cursor-pointer hover:text-red-600" onclick="removeRow(this)">✕</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                Simpan sebagai Draft
            </button>
            <a href="{{ route('inventory.adjustments.index') }}"
               class="px-5 py-3 border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-semibold rounded-xl transition">
                Batal
            </a>
        </div>

    </form>

</div>

<script>
    let itemIndex = 1;

    function initTomSelect(el) {
        if (el.tomselect) return;
        new TomSelect(el, {
            create: false,
            sortField: { field: 'text', direction: 'asc' },
            maxOptions: null,
            dropdownParent: 'body'
        });
    }

    $(document).ready(function () {

        // Init TomSelect pada product & account selects
        $('.product-select').each(function () { initTomSelect(this); });
        $('.account-select').each(function () { initTomSelect(this); });

        // Sinkronisasi tampilan berdasarkan tujuan penyesuaian
        function syncPurpose() {
            var isPerbaikan = $('#purpose-select').val() === 'perbaikan_rusak';

            $('#hidden-direction').val(isPerbaikan ? 'pengurangan' : 'set_stock');

            if (isPerbaikan) {
                $('#accounts-row').hide();
                $('#accounts-row select').removeAttr('required');
                $('#perbaikan-warning').show();
                $('#add-item-btn').hide();
            } else {
                $('#accounts-row').show();
                $('#income-account-wrap select').attr('required', 'required');
                $('#expense-account-wrap select').attr('required', 'required');
                $('#perbaikan-warning').hide();
                $('#add-item-btn').show();
            }
        }

        // Jalankan saat halaman pertama kali load
        syncPurpose();

        // Jalankan setiap kali tujuan berubah
        $('#purpose-select').on('change', syncPurpose);

    });

    function addItem() {
        const table = document.getElementById('items-table');
        const i = itemIndex;
        const options = `
            <option value="">— Pilih produk —</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
            @endforeach
        `;
        table.insertAdjacentHTML('beforeend', `
            <tr class="item-row border-b border-gray-50 last:border-0 hover:bg-gray-50/50 transition">
                <td class="px-3 py-2">
                    <select name="items[${i}][product_id]" class="product-select w-full border border-gray-200 rounded-lg px-2 py-2 text-sm" required>
                        ${options}
                    </select>
                </td>
                <td class="px-3 py-2 text-right text-gray-500">
                    <span class="system-qty font-mono">0</span>
                    <input type="hidden" name="items[${i}][system]" class="system-value" value="0">
                </td>
                <td class="px-3 py-2">
                    <input type="number" step="0.0001" name="items[${i}][actual]" value="0"
                           class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm text-right font-mono actual-input" required>
                </td>
                <td class="px-3 py-2 text-right font-bold font-mono diff-qty text-gray-700">0</td>
                <td class="px-3 py-2">
                    <input type="text" name="items[${i}][notes]" placeholder="Rusak / Hilang / ..."
                           class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm">
                </td>
                <td class="px-2 py-2 text-center text-red-400 cursor-pointer hover:text-red-600" onclick="removeRow(this)">✕</td>
            </tr>
        `);
        const newSelect = table.lastElementChild.querySelector('.product-select');
        initTomSelect(newSelect);
        itemIndex++;
    }

    function removeRow(btn) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length <= 1) { alert('Minimal harus ada 1 item.'); return; }
        const row = btn.closest('tr');
        const sel = row.querySelector('.product-select');
        if (sel && sel.tomselect) sel.tomselect.destroy();
        row.remove();
    }

    // Fetch system stock on product change
    $(document).on('change', '.product-select', function () {
        var productId   = $(this).val();
        var warehouseId = $('#warehouse_id').val();
        var row         = $(this).closest('tr');

        if (!productId || !warehouseId) {
            row.find('.system-qty').text('0');
            row.find('.system-value').val('0');
            calculateDiff(row[0]);
            return;
        }

        row.find('.system-qty').text('…');
        fetch('/erp/inventory/product-stock-adjustment?product_id=' + productId + '&warehouse_id=' + warehouseId)
            .then(r => r.json())
            .then(data => {
                row.find('.system-qty').text(data.qty);
                row.find('.system-value').val(data.qty);
                calculateDiff(row[0]);
            })
            .catch(() => row.find('.system-qty').text('?'));
    });

    $(document).on('input', '.actual-input', function () {
        calculateDiff($(this).closest('tr')[0]);
    });

    function calculateDiff(row) {
        const system = parseFloat(row.querySelector('.system-value').value) || 0;
        const actual = parseFloat(row.querySelector('.actual-input').value) || 0;
        const diff   = actual - system;
        const el     = row.querySelector('.diff-qty');
        el.innerText = diff === 0 ? '0' : (diff > 0 ? '+' : '') + diff.toFixed(4).replace(/\.?0+$/, '');
        el.style.color = diff < 0 ? '#ef4444' : diff > 0 ? '#22c55e' : '#374151';
    }
</script>

@endsection
