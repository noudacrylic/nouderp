{{-- Kolom "Sumber komponen" saat menambah/mengubah baris: pilih manual atau akun buku besar.
     Butuh: $formId, $source, $accountId, $percentage, $notes, $expenseAccounts --}}
<div class="flex items-center gap-2">
    <select name="source" form="{{ $formId }}"
            class="komponen-source border border-slate-200 rounded-lg px-2 py-1.5 text-sm shrink-0">
        <option value="manual" @selected($source === 'manual')>Manual</option>
        <option value="akun"   @selected($source === 'akun')>Dari akun</option>
    </select>

    <select name="account_id" form="{{ $formId }}"
            class="komponen-akun border border-slate-200 rounded-lg px-2 py-1.5 text-sm flex-1 min-w-0 {{ $source === 'akun' ? '' : 'hidden' }}">
        @foreach($expenseAccounts as $acc)
            <option value="{{ $acc->id }}" @selected((int) $accountId === (int) $acc->id)>{{ $acc->code }} — {{ $acc->name }}</option>
        @endforeach
    </select>

    <input type="number" name="percentage" form="{{ $formId }}" value="{{ $percentage ?: 100 }}"
           min="0.01" max="100" step="0.01" title="Persen bagian akun yang dianggap biaya produksi"
           class="komponen-akun border border-slate-200 rounded-lg px-2 py-1.5 text-sm w-16 text-right {{ $source === 'akun' ? '' : 'hidden' }}">

    <input type="text" name="notes" form="{{ $formId }}" value="{{ $source === 'manual' ? $notes : '' }}"
           placeholder="Keterangan (opsional)" maxlength="255"
           class="komponen-manual border border-slate-200 rounded-lg px-2 py-1.5 text-sm flex-1 min-w-0 {{ $source === 'akun' ? 'hidden' : '' }}">
</div>
