<div class="bg-white shadow rounded p-6">

    <h2 class="text-lg font-semibold mb-4">
        {{ $title }}
    </h2>

    <div class="mb-6 text-sm text-gray-600">
        {{ $reference_label }} :
        <b>{{ $reference_number }}</b>
    </div>

    <form method="POST" action="{{ $action }}">
        @csrf

        <div class="grid grid-cols-2 gap-6 mb-6">

            <div>
                <label class="block text-sm mb-1">Tanggal</label>

                <input type="date" name="date" value="{{ date('Y-m-d') }}" class="border rounded px-3 py-2 w-full">
            </div>

            <div>
                <label class="block text-sm mb-1">Bank / Kas</label>

                <select name="account_id" class="border rounded px-3 py-2 w-full">

                    @foreach($accounts as $account)

                        <option value="{{ $account->id }}">
                            {{ $account->code }} - {{ $account->name }}
                        </option>

                    @endforeach

                </select>
            </div>

        </div>


        <div class="mb-6">

            <label class="block text-sm mb-1">Jumlah</label>

            <input type="number" name="amount" @isset($max_amount) max="{{ $max_amount }}" @endisset
                class="border rounded px-3 py-2 w-full">

        </div>


        <div class="mb-6">

            <label class="block text-sm mb-1">Catatan</label>

            <textarea name="notes" class="border rounded px-3 py-2 w-full" rows="3"></textarea>

        </div>


        <div class="flex justify-end">

            <button class="bg-blue-600 text-white px-5 py-2 rounded">
                Simpan Pembayaran
            </button>

        </div>

    </form>

</div>