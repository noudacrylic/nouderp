@extends('layouts.erp')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight text-indigo-900">Marketplace Configuration</h1>
            <p class="text-gray-500 mt-1">Manage admin fees and GL account mapping for marketplace platforms.</p>
        </div>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-xl font-semibold shadow-lg shadow-indigo-200 transition-all transform hover:-translate-y-0.5 active:scale-95 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
            </svg>
            Add New Marketplace
        </button>
    </div>


    @if($errors->any())
        <div class="bg-rose-50 border-l-4 border-rose-400 p-4 mb-8 rounded-r-xl">
            <ul class="text-sm text-rose-700 list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-xl shadow-gray-100 overflow-hidden border border-gray-100">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-gray-50/50">
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Marketplace Platform</th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Fees</th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Account Mapping</th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Status</th>
                    <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($configs as $config)
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold border-2 border-white shadow-sm">
                                    {{ substr($config->customer->name, 0, 1) }}
                                </div>
                                <div>
                                    <span class="font-bold text-gray-800 block leading-tight">{{ $config->customer->name }}</span>
                                    <span class="text-[10px] text-gray-400 uppercase tracking-widest leading-none">Customer ID: #{{ $config->customer_id }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex flex-col gap-1 items-center">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                                    {{ $config->admin_fee_percent }}%
                                </span>
                                <span class="text-xs text-gray-400 font-mono">
                                    + Rp{{ number_format($config->admin_fee_fixed, 0, ',', '.') }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-center gap-2 group">
                                    <span class="w-16 text-[9px] font-bold text-gray-400 uppercase tracking-tighter">Hold (A)</span>
                                    <span class="text-xs font-medium text-gray-700 bg-gray-50 px-2 py-0.5 rounded-lg border border-gray-100 group-hover:bg-amber-50 group-hover:border-amber-100 transition-colors">
                                        {{ $config->holdAccount->code ?? '-' }} - {{ $config->holdAccount->name ?? 'Not Mapped' }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 group">
                                    <span class="w-16 text-[9px] font-bold text-gray-400 uppercase tracking-tighter">Fee (E)</span>
                                    <span class="text-xs font-medium text-gray-700 bg-gray-50 px-2 py-0.5 rounded-lg border border-gray-100 group-hover:bg-rose-50 group-hover:border-rose-100 transition-colors">
                                        {{ $config->feeAccount->code ?? '-' }} - {{ $config->feeAccount->name ?? 'Not Mapped' }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 group">
                                    <span class="w-16 text-[9px] font-bold text-gray-400 uppercase tracking-tighter">Wallet (A)</span>
                                    <span class="text-xs font-medium text-gray-700 bg-gray-50 px-2 py-0.5 rounded-lg border border-gray-100 group-hover:bg-emerald-50 group-hover:border-emerald-100 transition-colors">
                                        {{ $config->walletAccount->code ?? '-' }} - {{ $config->walletAccount->name ?? 'Not Mapped' }}
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($config->is_active)
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 uppercase tracking-tight">Active</span>
                            @else
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black bg-gray-100 text-gray-700 uppercase tracking-tight">Disabled</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex justify-end gap-1">
                                <button onclick="openEditModal('{{ $config->id }}', '{{ $config->admin_fee_percent }}', '{{ $config->admin_fee_fixed }}', {{ $config->is_active ? 'true' : 'false' }}, '{{ $config->account_receivable_hold_id }}', '{{ $config->account_fee_id }}', '{{ $config->account_wallet_id }}')" 
                                        class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all" title="Edit Configuration">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <form action="{{ route('settings.marketplace.destroy', $config->id) }}" method="POST" onsubmit="return confirm('Hapus konfigurasi marketplace ini?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="p-2 text-rose-500 hover:bg-rose-50 rounded-xl transition-all">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-20 text-center text-gray-400">
                             <p class="text-sm">No marketplace mappings found. Start by adding one above.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="hidden fixed inset-0 z-50 overflow-auto bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] max-w-lg w-full shadow-2xl p-10 animate-in fade-in zoom-in duration-300">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight">Marketplace Setup</h2>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form action="{{ route('settings.marketplace.store') }}" method="POST" class="space-y-6">
            @csrf
            {{-- Platform Selection --}}
            <div>
                <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Select Platform (Customer)</label>
                <select name="customer_id" required class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl px-5 py-4 focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all font-bold text-slate-700 outline-none">
                    <option value="" disabled selected>-- Choose Customer --</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Fee Configuration --}}
            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Admin Fee (%)</label>
                    <div class="relative">
                        <input type="number" name="admin_fee_percent" step="0.01" value="0" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl px-5 py-4 focus:border-indigo-500 transition-all font-mono font-bold text-slate-700 outline-none">
                        <span class="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 font-bold">%</span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Admin Fee (Nominal)</label>
                    <input type="number" name="admin_fee_fixed" value="0" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl px-5 py-4 focus:border-indigo-500 transition-all font-mono font-bold text-slate-700 outline-none">
                </div>
            </div>

            <hr class="border-slate-100">

            {{-- Account Mapping --}}
            <div class="space-y-4">
                <h3 class="text-xs font-black text-indigo-600 uppercase tracking-widest">General Ledger Mapping</h3>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5 ml-1">Account: Receivable Hold (Asset)</label>
                    <select name="account_receivable_hold_id" required class="w-full bg-slate-50 border-2 border-slate-50 rounded-xl px-4 py-3 focus:border-indigo-500 text-sm font-medium outline-none">
                        @foreach($accounts->where('type', 'asset') as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5 ml-1">Account: Marketplace Fee (Expense)</label>
                    <select name="account_fee_id" required class="w-full bg-slate-50 border-2 border-slate-50 rounded-xl px-4 py-3 focus:border-indigo-500 text-sm font-medium outline-none">
                        @foreach($accounts->where('type', 'expense') as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5 ml-1">Account: Wallet Balance (Asset)</label>
                    <select name="account_wallet_id" required class="w-full bg-slate-50 border-2 border-slate-50 rounded-xl px-4 py-3 focus:border-indigo-500 text-sm font-medium outline-none">
                        @foreach($accounts->where('type', 'asset') as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-black py-5 rounded-2xl shadow-xl shadow-indigo-200 transition-all transform hover:scale-[1.02] active:scale-95 tracking-widest uppercase text-sm">
                Initialize Platform
            </button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 z-50 overflow-auto bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] max-w-lg w-full shadow-2xl p-10 animate-in fade-in zoom-in duration-300 border-t-8 border-indigo-600">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight">Edit Mapping</h2>
            <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form id="editForm" method="POST" class="space-y-6">
            @csrf @method('PUT')
            
            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-black text-slate-500 mb-2 uppercase">Fee %</label>
                    <input type="number" name="admin_fee_percent" id="edit_percent" step="0.01" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl px-5 py-4 focus:border-indigo-500 transition-all font-mono font-bold outline-none">
                </div>
                <div>
                    <label class="block text-xs font-black text-slate-500 mb-2 uppercase">Fee Fix</label>
                    <input type="number" name="admin_fee_fixed" id="edit_fixed" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl px-5 py-4 focus:border-indigo-500 transition-all font-mono font-bold outline-none">
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5 uppercase">Receivable Hold Account</label>
                    <select name="account_receivable_hold_id" id="edit_hold_id" class="w-full bg-slate-50 border-2 border-slate-50 rounded-xl px-4 py-3 text-sm font-medium outline-none">
                        @foreach($accounts->where('type', 'asset') as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5 uppercase">Marketplace Fee Account</label>
                    <select name="account_fee_id" id="edit_fee_id" class="w-full bg-slate-50 border-2 border-slate-50 rounded-xl px-4 py-3 text-sm font-medium outline-none">
                        @foreach($accounts->where('type', 'expense') as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5 uppercase">Wallet Balance Account</label>
                    <select name="account_wallet_id" id="edit_wallet_id" class="w-full bg-slate-50 border-2 border-slate-50 rounded-xl px-4 py-3 text-sm font-medium outline-none">
                        @foreach($accounts->where('type', 'asset') as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="pt-2">
                <label class="flex items-center gap-3 cursor-pointer group">
                    <div class="relative">
                        <input type="checkbox" name="is_active" id="edit_active" value="1" class="sr-only">
                        <div class="w-14 h-7 bg-slate-200 rounded-full shadow-inner transition-colors group-hover:bg-slate-300"></div>
                        <div class="dot absolute left-1 top-1 w-5 h-5 bg-white rounded-full transition-transform shadow-md"></div>
                    </div>
                    <span class="text-xs font-black text-slate-700 uppercase tracking-tighter">Enable Platform Integration</span>
                </label>
            </div>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-black py-5 rounded-2xl shadow-xl shadow-indigo-200 transition-all font-mono tracking-widest uppercase">
                COMMIT UPDATES
            </button>
        </form>
    </div>
</div>

<style>
    #edit_active:checked ~ .dot { transform: translateX(1.75rem); }
    #edit_active:checked ~ div { background-color: #10b981; }
</style>

<script>
    function openEditModal(id, percent, fixed, active, hold_id, fee_id, wallet_id) {
        const form = document.getElementById('editForm');
        form.action = `/erp/settings/marketplace/${id}`;
        document.getElementById('edit_percent').value = percent;
        document.getElementById('edit_fixed').value = fixed;
        document.getElementById('edit_active').checked = active;
        
        // Account mappings
        document.getElementById('edit_hold_id').value = hold_id;
        document.getElementById('edit_fee_id').value = fee_id;
        document.getElementById('edit_wallet_id').value = wallet_id;
        
        document.getElementById('editModal').classList.remove('hidden');
    }
</script>
@endsection
