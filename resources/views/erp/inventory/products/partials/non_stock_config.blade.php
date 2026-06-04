<div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full">
    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">SETTING AKUN BIAYA</h3>
    </div>

    <form action="{{ route('inventory.products.update-info', $product->id) }}" method="POST"
        class="p-6 flex-1 flex flex-col">
        @csrf
        <div class="space-y-4 flex-1">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">AKUN EXPENSE (BIAYA)</label>
            <select name="expense_account_id"
                class="account-search w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition bg-white"
                required>
                <option value="">-- Pilih Akun --</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}" {{ $product->expense_account_id == $acc->id ? 'selected' : '' }}>
                        {{ $acc->code }} - {{ $acc->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-100 text-right">
            <button type="submit"
                class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition shadow-md">
                Simpan Akun
            </button>
        </div>
    </form>
</div>