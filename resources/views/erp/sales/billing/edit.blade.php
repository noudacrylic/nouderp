@extends('layouts.erp')

@section('content')
<div class="flex justify-center w-full px-6 py-8">
    <div class="w-full max-w-3xl">
        <!-- HEADER -->
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-3">
                <a href="{{ route('sales.billing.index') }}" class="text-gray-400 hover:text-gray-600 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
                <h1 class="text-2xl font-black text-gray-800 tracking-tight">Edit Billing: {{ $billing->billing_number }}</h1>
            </div>
            <div class="text-right">
                <div id="display_billing_total" class="text-2xl font-black text-blue-600">Rp {{ number_format($billing->total_amount, 0, ',', '.') }}</div>
                <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Total Penagihan</div>
            </div>
        </div>


        <form action="{{ route('sales.billing.update', $billing->id) }}" method="POST" id="billing_form" class="bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden">
            @csrf
            @method('PUT')
            
            <div class="p-8 space-y-8">
                <!-- SECTION 1: CUSTOMER SELECTION (READ-ONLY IN EDIT) -->
                <div class="bg-gray-50 p-5 rounded-xl border border-gray-200">
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pelanggan</label>
                            <div class="text-sm font-bold text-gray-800">{{ $billing->customer->name }}</div>
                            <input type="hidden" name="customer_id" value="{{ $billing->customer_id }}">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Jenis Billing</label>
                            @php
                                $isInvoice = $billing->items->whereNotNull('invoice_id')->count() > 0;
                                $type = $isInvoice ? 'invoice' : 'sales_order';
                            @endphp
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2">
                                    <input type="radio" name="billing_type" value="invoice" {{ $type == 'invoice' ? 'checked' : '' }} class="text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-semibold text-gray-700">Pelunasan Faktur</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="radio" name="billing_type" value="sales_order" {{ $type == 'sales_order' ? 'checked' : '' }} class="text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-semibold text-gray-700">Uang Muka (SO)</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: DOCUMENT LIST -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                        <label id="section_title" class="text-xs font-black text-gray-700 uppercase tracking-widest">Pilih Dokumen Penagihan</label>
                        <span id="selected_count" class="text-[10px] bg-blue-600 text-white px-2 py-0.5 rounded-full font-bold">0 Selected</span>
                    </div>

                    <div id="invoice_list_container" class="space-y-3 max-h-[400px] overflow-y-auto pr-2 custom-scrollbar">
                        <div class="text-center py-10 italic text-gray-400 text-xs font-medium">Loading documents...</div>
                    </div>
                </div>

                <!-- NOTES -->
                <div class="pt-4 border-t border-gray-100">
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Catatan Tambahan</label>
                    <textarea name="notes" rows="2" class="w-full border-gray-200 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 transition" placeholder="Contoh: Titipan tagihan via ekspedisi...">{{ $billing->notes }}</textarea>
                </div>
            </div>

            <!-- FOOTER ACTIONS -->
            <div class="bg-gray-50 px-8 py-5 flex justify-between items-center border-t border-gray-100">
                <p class="text-[10px] text-gray-400 font-medium italic">Pastikan daftar dokumen sudah sesuai sebelum memperbarui.</p>
                <div class="flex gap-3">
                    <a href="{{ route('sales.billing.index') }}" class="px-6 py-2 text-sm font-bold text-gray-500 hover:text-gray-700 transition">Batal</a>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2 rounded-lg text-sm font-black shadow-lg shadow-blue-200 transition transform hover:-translate-y-0.5 active:translate-y-0">
                        Update Penagihan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    let rawData = { invoices: [], sales_orders: [] };
    let currentType = "{{ $type }}";
    const customerId = "{{ $billing->customer_id }}";
    const selectedIds = @json($billing->items->pluck($isInvoice ? 'invoice_id' : 'sales_order_id'));

    loadCustomerDocuments(customerId);

    // Handle Type Radios
    const typeRadios = document.querySelectorAll('input[name="billing_type"]');
    const sectionTitle = document.getElementById('section_title');

    typeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            currentType = this.value;
            sectionTitle.innerText = currentType === 'invoice' ? 'Pilih Faktur untuk Pelunasan' : 'Pilih Sales Order untuk Uang Muka';
            renderItems();
        });
    });

    function loadCustomerDocuments(id) {
        fetch(`/erp/sales/ajax/billing-open-documents/${id}`)
            .then(res => res.json())
            .then(data => {
                rawData = data;
                renderItems();
            });
    }

    function renderItems() {
        const container = document.getElementById('invoice_list_container');
        let items = (currentType === 'invoice') ? rawData.invoices : rawData.sales_orders;
        
        // In EDIT mode, we MUST include current billing items if they are not in the 'open' list
        // (because they are 'occupied' by this billing)
        const currentBillingItems = @json($billing->items);
        
        // This part is simplified: we just render what we have and mark current ones as checked
        // Ideally we should merge currentBillingItems into 'items' if they are missing

        let html = '';
        const allItems = [...items];
        
        // Add current items if missing (snapshot data)
        currentBillingItems.forEach(bi => {
            const biId = bi.invoice_id || bi.sales_order_id;
            const isMatch = (bi.invoice_id && currentType === 'invoice') || (bi.sales_order_id && currentType === 'sales_order');
            
            if (isMatch && !allItems.find(i => i.id == biId)) {
                allItems.push({
                    id: biId,
                    invoice_number: bi.document_number,
                    order_number: bi.document_number,
                    invoice_date: bi.document_date,
                    order_date: bi.document_date,
                    remaining: bi.remaining_snapshot
                });
            }
        });

        if (allItems.length === 0) {
            container.innerHTML = `<div class="text-center py-10 border rounded bg-gray-50 italic text-gray-400 text-xs">Tidak ada dokumen tersedia.</div>`;
            calculateTotal();
            return;
        }

        allItems.forEach(item => {
            const amount = parseFloat(item.remaining || 0);
            const number = item.invoice_number || item.order_number;
            const isChecked = selectedIds.includes(item.id);

            html += `
                <label class="block border rounded-xl p-4 text-sm hover:bg-blue-50 cursor-pointer flex justify-between items-center transition group ${isChecked ? 'bg-blue-50 border-blue-200' : 'border-gray-100'}">
                    <div class="flex items-center gap-4">
                        <input type="checkbox" name="item_ids[]" value="${item.id}" ${isChecked ? 'checked' : ''} data-amount="${amount}" class="doc-checkbox w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <div>
                            <div class="font-black text-gray-800">${number}</div>
                            <div class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">${item.invoice_date || item.order_date}</div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-black text-gray-900">Rp ${amount.toLocaleString()}</div>
                    </div>
                </label>
            `;
        });
        container.innerHTML = html;

        document.querySelectorAll('.doc-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                this.closest('label').classList.toggle('bg-blue-50', this.checked);
                this.closest('label').classList.toggle('border-blue-200', this.checked);
                calculateTotal();
            });
        });
        calculateTotal();
    }

    function calculateTotal() {
        const checked = document.querySelectorAll('.doc-checkbox:checked');
        let total = 0;
        checked.forEach(cb => total += parseFloat(cb.dataset.amount || 0));

        document.getElementById('display_billing_total').innerText = 'Rp ' + total.toLocaleString();
        document.getElementById('selected_count').innerText = `${checked.length} Selected`;
    }
});
</script>
@endsection
