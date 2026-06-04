@extends('layouts.erp')

@section('content')

    <div class="max-w-6xl mx-auto">

        <div class="bg-white shadow rounded-lg border p-6">

            <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                <span class="p-2 bg-blue-50 text-blue-600 rounded-lg">⚙️</span>
                Inventory Settings
            </h2>

            <form method="POST" action="{{ route('settings.inventory.update') }}">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

                    <!-- Asset Account -->
                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-gray-600 uppercase tracking-wider">Inventory Asset
                            Account</label>
                        <select name="inventory_asset_account_id"
                            class="w-full border rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-100 outline-none transition bg-gray-50 border-gray-200">
                            <option value="">Select Account</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" @if($setting && $setting->inventory_asset_account_id == $a->id)
                                selected @endif>
                                    {{ $a->code }} - {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400">Default account for stock valuation.</p>
                    </div>

                    <!-- Gain Account -->
                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-gray-600 uppercase tracking-wider">Stock Gain
                            Account</label>
                        <select name="inventory_gain_account_id"
                            class="w-full border rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-100 outline-none transition bg-gray-50 border-gray-200">
                            <option value="">Select Account</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" @if($setting && $setting->inventory_gain_account_id == $a->id)
                                selected @endif>
                                    {{ $a->code }} - {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400">Account for positive stock adjustments.</p>
                    </div>

                    <!-- Loss Account -->
                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-gray-600 uppercase tracking-wider">Stock Loss
                            Account</label>
                        <select name="inventory_loss_account_id"
                            class="w-full border rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-100 outline-none transition bg-gray-50 border-gray-200">
                            <option value="">Select Account</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" @if($setting && $setting->inventory_loss_account_id == $a->id)
                                selected @endif>
                                    {{ $a->code }} - {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400">Account for negative stock adjustments.</p>
                    </div>

                    <!-- Repair Account -->
                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-gray-600 uppercase tracking-wider">Akun Perbaikan Stock Rusak</label>
                        <select name="repair_account_id"
                            class="w-full border rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-100 outline-none transition bg-gray-50 border-gray-200">
                            <option value="">Select Account</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" @if($setting && $setting->repair_account_id == $a->id) selected @endif>
                                    {{ $a->code }} - {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400">Akun yang didebit untuk penyesuaian perbaikan stock rusak (mis. Persediaan Perbaikan).</p>
                    </div>

                    <!-- OB Account -->
                    <div class="space-y-2">
                        <label class="block text-sm font-bold text-gray-600 uppercase tracking-wider">Opening Balance
                            Account</label>
                        <select name="opening_balance_account_id"
                            class="w-full border rounded-lg px-3 py-2.5 focus:ring-2 focus:ring-blue-100 outline-none transition bg-gray-50 border-gray-200">
                            <option value="">Select Account</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" @if($setting && $setting->opening_balance_account_id == $a->id)
                                selected @endif>
                                    {{ $a->code }} - {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400">Account for initial stock (e.g., Equity/Modal Awal).</p>
                    </div>

                </div>

                <div class="mt-8 pt-6 border-t flex justify-end">
                    <button
                        class="bg-blue-600 text-white px-8 py-3 rounded-lg font-bold shadow-lg hover:bg-blue-700 transition flex items-center gap-2">
                        Save Settings
                    </button>
                </div>

            </form>

        </div>

    </div>

@endsection