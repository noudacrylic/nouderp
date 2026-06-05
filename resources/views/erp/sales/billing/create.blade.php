@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-8 flex justify-center">
    <div class="w-full max-w-3xl bg-white border rounded-lg shadow-sm p-6 overflow-visible">
        
        <div class="mb-6 border-b pb-4">
            <h1 class="text-xl font-bold text-gray-800">Create New Billing</h1>
            <p class="text-xs text-gray-500">Kelompokkan beberapa invoice untuk penagihan kolektif (billing).</p>
        </div>

        <form action="{{ route('sales.billing.store') }}" method="POST" id="billing_form">
            @csrf
            
            <div class="space-y-6">
                <!-- SECTION 1: CUSTOMER SELECTION -->
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-100 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-tight">Pelanggan</label>
                        <select id="customer_id" name="customer_id" class="w-full border rounded px-3 py-2 text-sm" placeholder="Ketik nama pelanggan..." required></select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-2 uppercase tracking-tight">Jenis Billing</label>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input type="radio" name="billing_type" value="invoice" checked class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                <span class="text-sm font-semibold text-gray-700 group-hover:text-blue-600 transition">Pelunasan Faktur</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input type="radio" name="billing_type" value="sales_order" class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                <span class="text-sm font-semibold text-gray-700 group-hover:text-blue-600 transition">Uang Muka (SO)</span>
                            </label>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1 italic" id="billing_hint">Pilih faktur yang akan ditagihkan secara kolektif.</p>
                    </div>
                </div>

                <!-- SECTION 2: DOCUMENT LIST -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between border-b pb-1">
                        <label id="section_title" class="block text-xs font-bold text-gray-700 uppercase tracking-tight">Pilih Dokumen Penagihan</label>
                        <span id="selected_count" class="text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded-full font-bold">0 Selected</span>
                    </div>

                    <div id="invoice_list_container" class="space-y-2 max-h-[350px] overflow-y-auto pr-1">
                        <div class="text-center py-10 border-2 border-dashed rounded-lg bg-gray-50 border-gray-100 text-xs text-gray-400 italic">
                            Pilih pelanggan untuk melihat daftar faktur
                        </div>
                    </div>

                    <div class="flex justify-between items-center bg-blue-50 border border-blue-100 rounded p-3">
                        <span class="text-xs font-bold text-blue-800 uppercase tracking-tight">Total Nilai Billing:</span>
                        <span id="display_billing_total" class="text-sm font-black text-blue-900">Rp 0</span>
                    </div>
                </div>

                <!-- SECTION 3: BILLING DETAILS -->
                <div class="pt-4 border-t">
                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-tighter">Catatan / Keterangan</label>
                    <textarea name="notes" class="w-full border rounded px-3 py-2 text-xs focus:ring-1 focus:ring-blue-500 outline-none" rows="3" placeholder="Contoh: Penagihan untuk periode April 2026..."></textarea>
                </div>

                <div class="pt-6 border-t flex justify-end gap-3">
                    <a href="{{ route('sales.billing.index') }}" class="bg-gray-50 border text-gray-500 px-6 py-2.5 rounded text-sm font-bold hover:bg-gray-100 transition">Batal</a>
                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-8 py-2.5 rounded text-sm font-bold shadow-md transition active:scale-95">Simpan Billing</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<style>
    .ts-dropdown { z-index: 9999 !important; }
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    let rawData = { invoices: [], sales_orders: [] };
    let currentType = 'invoice';
    
    // TomSelect with AJAX
    const tomSelect = new TomSelect("#customer_id", {
        valueField: "id",
        labelField: "name",
        searchField: "name",
        dropdownParent: 'body',
        preload: 'focus',
        load: function(query, callback) {
            fetch(`/erp/sales/ajax/search-customers?q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => callback(data))
                .catch(() => callback());
        },
        onChange: function(id) {
            if (!id) return resetAll();
            loadCustomerDocuments(id);
        }
    });

    // Handle Type Radios
    const typeRadios = document.querySelectorAll('input[name="billing_type"]');
    const sectionTitle = document.getElementById('section_title');
    const billingHint = document.getElementById('billing_hint');

    typeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            currentType = this.value;
            if (currentType === 'invoice') {
                sectionTitle.innerText = 'Pilih Faktur untuk Pelunasan';
                billingHint.innerText = 'Pilih faktur yang akan ditagihkan secara kolektif (pelunasan).';
            } else {
                sectionTitle.innerText = 'Pilih Sales Order untuk Uang Muka';
                billingHint.innerText = 'Pilih Sales Order yang belum memiliki invoice untuk penagihan Uang Muka (DP).';
            }
            renderItems();
        });
    });

    function resetAll() {
        rawData = { invoices: [], sales_orders: [] };
        document.getElementById('invoice_list_container').innerHTML = `<div class="text-center py-10 border-2 border-dashed rounded-lg bg-gray-50 border-gray-100 text-xs text-gray-400 italic font-bold">Pilih pelanggan untuk melihat daftar faktur</div>`;
        calculateTotal();
    }

    function loadCustomerDocuments(customerId) {
        const container = document.getElementById('invoice_list_container');
        container.innerHTML = `<div class="text-center py-10 italic text-gray-400 text-xs">Loading data...</div>`;

        fetch(`/erp/sales/ajax/billing-open-documents/${customerId}`)
            .then(res => res.json())
            .then(data => {
                rawData = data;
                renderItems();
            });
    }

    function renderItems() {
        const container = document.getElementById('invoice_list_container');
        const items = (currentType === 'invoice') ? rawData.invoices : rawData.sales_orders;
        
        if (!items || items.length === 0) {
            container.innerHTML = `<div class="text-center py-10 border rounded bg-gray-50 italic text-gray-400 text-xs">Tidak ada ${currentType === 'invoice' ? 'faktur' : 'Sales Order'} terbuka untuk pelanggan ini</div>`;
            calculateTotal();
            return;
        }

        let html = '';
        items.forEach(item => {
            const amount = parseFloat(item.remaining || 0);
            const number = item.invoice_number || item.order_number;
            const date = item.invoice_date || item.order_date;
            
            const badge = currentType === 'invoice' 
                ? '<span class="text-[9px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded font-bold">PELUNASAN</span>'
                : '<span class="text-[9px] bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded font-bold">UANG MUKA</span>';

            html += `
                <label class="block border rounded p-3 text-sm hover:bg-gray-50 cursor-pointer flex justify-between items-center transition group">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="item_ids[]" value="${item.id}" data-type="${currentType}" data-amount="${amount}" class="doc-checkbox w-4 h-4 rounded text-blue-600 border-gray-300">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-gray-800">${number}</span>
                                ${badge}
                            </div>
                            <span class="text-[10px] text-gray-400 uppercase tracking-tight font-medium">${date}</span>
                        </div>
                    </div>
                    <span class="font-black text-gray-900 px-3 py-1 bg-gray-50 rounded border group-hover:bg-white transition shadow-sm">Rp ${amount.toLocaleString()}</span>
                </label>
            `;
        });
        container.innerHTML = html;

        // Bind events
        document.querySelectorAll('.doc-checkbox').forEach(cb => {
            cb.addEventListener('change', calculateTotal);
        });
        
        calculateTotal();
    }

    function calculateTotal() {
        const checked = document.querySelectorAll('.doc-checkbox:checked');
        let total = 0;
        checked.forEach(cb => {
            total += parseFloat(cb.dataset.amount || 0);
        });

        document.getElementById('display_billing_total').innerText = 'Rp ' + total.toLocaleString();
        document.getElementById('selected_count').innerText = `${checked.length} Selected`;
    }

    document.getElementById('billing_form').onsubmit = function() {
        if (document.querySelectorAll('.doc-checkbox:checked').length === 0) {
            alert(`Pilih minimal satu ${currentType === 'invoice' ? 'faktur' : 'Sales Order'}!`);
            return false;
        }
        return true;
    };
});
</script>
@endpush
