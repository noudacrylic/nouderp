@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-gray-800">Payment Fee Settings</h1>
        <p class="text-xs text-gray-500">Konfigurasi biaya admin otomatis berdasarkan akun kas/bank yang dipilih.</p>
    </div>


    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- LEFT: FORM -->
        <div class="lg:col-span-1">
            <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
                <div class="p-4 border-b bg-gray-50">
                    <h2 class="text-sm font-black text-gray-700 uppercase tracking-widest">Tambah / Edit Setting</h2>
                </div>
                <form action="{{ route('settings.payment-fee.store') }}" method="POST" id="fee_form" class="p-5 space-y-4">
                    @csrf
                    <input type="hidden" name="id" id="setting_id">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Setor Ke (Akun Kas)</label>
                        <select name="cash_account_id" id="cash_account_id" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none transition" required>
                            <option value="">-- PILIH AKUN KAS --</option>
                            @php $existingIds = $settings->pluck('cash_account_id')->toArray(); @endphp
                            @foreach($cashAccounts as $acc)
                                <option value="{{ $acc->id }}" data-full-name="{{ $acc->code }} - {{ $acc->name }}">{{ $acc->code }} - {{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Biaya Flat (Rp)</label>
                            <input type="number" name="fee_flat" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none transition" placeholder="0">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Biaya Persen (%)</label>
                            <input type="number" name="fee_percent" step="0.01" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none transition" placeholder="0.00">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-widest">Akun Beban Fee</label>
                        <select name="expense_account_id" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none transition" required>
                            <option value="">-- PILIH AKUN BEBAN --</option>
                            @foreach($expenseAccounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->code }} - {{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="pt-4 flex gap-2">
                        <button type="submit" id="btn-submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl text-xs uppercase tracking-widest shadow-lg shadow-blue-100 transition active:scale-95">
                            Simpan Setting
                        </button>
                        <button type="button" id="btn-reset" onclick="resetForm()" class="hidden bg-gray-100 hover:bg-gray-200 text-gray-500 font-bold px-4 py-3 rounded-xl text-[10px] uppercase tracking-widest transition active:scale-95">
                            Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- RIGHT: TABLE -->
        <div class="lg:col-span-2">
            <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th class="px-6 py-4 font-black text-gray-400 uppercase tracking-widest text-[10px]">Akun Kas</th>
                            <th class="px-6 py-4 font-black text-gray-400 uppercase tracking-widest text-[10px]">Flat</th>
                            <th class="px-6 py-4 font-black text-gray-400 uppercase tracking-widest text-[10px]">Persen</th>
                            <th class="px-6 py-4 font-black text-gray-400 uppercase tracking-widest text-[10px]">Akun Beban</th>
                            <th class="px-6 py-4 font-black text-gray-400 uppercase tracking-widest text-[10px]"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($settings as $setting)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-800">{{ $setting->cashAccount->name }}</div>
                                    <div class="text-[10px] text-gray-400 font-bold tracking-widest uppercase">{{ $setting->cashAccount->code }}</div>
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-700">
                                    Rp {{ number_format($setting->fee_flat, 0, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 font-medium text-blue-600">
                                    {{ $setting->fee_percent }}%
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-xs text-gray-600 font-medium">{{ $setting->expenseAccount->name }}</div>
                                    <div class="text-[9px] text-gray-400 font-bold uppercase tracking-widest">{{ $setting->expenseAccount->code }}</div>
                                </td>
                                <td class="px-6 py-4 text-right flex gap-1 justify-end">
                                    <button type="button" onclick="editSetting({{ $setting->id }})" class="text-blue-500 hover:text-blue-700 transition p-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-5M16.242 3.758a2.121 2.121 0 013 3L12 15l-4 1 1-4 7.242-7.242z" />
                                        </svg>
                                    </button>
                                    <form action="{{ route('settings.payment-fee.destroy', $setting->id) }}" method="POST" onsubmit="return confirm('Hapus setting ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-600 transition p-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-400 italic">Belum ada konfigurasi biaya admin.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script>
    const feeSettings = @json($settings);

    function editSetting(id) {
        const data = feeSettings.find(s => s.id === id);
        if (!data) return;

        document.getElementById('setting_id').value = data.id;
        document.getElementById('cash_account_id').value = data.cash_account_id;
        document.querySelector('[name="fee_flat"]').value = parseFloat(data.fee_flat);
        document.querySelector('[name="fee_percent"]').value = parseFloat(data.fee_percent);
        document.querySelector('[name="expense_account_id"]').value = data.expense_account_id;

        document.getElementById('btn-submit').innerText = 'Update Setting';
        document.getElementById('btn-reset').classList.remove('hidden');
        
        // Scroll to form
        document.getElementById('fee_form').scrollIntoView({ behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('setting_id').value = '';
        document.getElementById('fee_form').reset();
        document.getElementById('btn-submit').innerText = 'Simpan Setting';
        document.getElementById('btn-reset').classList.add('hidden');
    }
</script>
@endpush
@endsection
