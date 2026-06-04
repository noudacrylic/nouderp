@extends('layouts.erp')

@section('content')

    <style>
        input[readonly], select[disabled], input[disabled] {
            background-color: #f8f9fa !important;
            border-color: #dee2e6 !important;
            cursor: not-allowed;
            color: #6c757d;
        }
    </style>

    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 fw-bold">📝 Edit Account: {{ $account->code }}</h5>
                </div>
                <div class="card-body p-4">

                    @if ($errors->any())
                        <div class="alert alert-danger shadow-sm mb-4">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('accounts.update', $account->id) }}">
                        @csrf
                        @method('PUT')

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Account Type</label>
                                <select name="type" id="accountType" class="form-select border-dark" @if($isUsed || $account->is_system) disabled @endif>
                                    <option value="asset" {{ $account->type == 'asset' ? 'selected' : '' }}>🟢 Asset</option>
                                    <option value="liability" {{ $account->type == 'liability' ? 'selected' : '' }}>🔴 Liability</option>
                                    <option value="equity" {{ $account->type == 'equity' ? 'selected' : '' }}>🧱 Equity</option>
                                    <option value="revenue" {{ $account->type == 'revenue' ? 'selected' : '' }}>💰 Revenue</option>
                                    <option value="expense" {{ $account->type == 'expense' ? 'selected' : '' }}>📉 Expense</option>
                                </select>
                                @if($isUsed || $account->is_system)
                                    <input type="hidden" name="type" value="{{ $account->type }}">
                                @endif
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Category</label>
                                <select name="account_category" id="accountCategory" class="form-select" @if($isUsed || $account->is_system) disabled @endif>
                                    <option value="" {{ $account->account_category == null ? 'selected' : '' }}>- None -</option>
                                    <option value="cash" {{ $account->account_category == 'cash' ? 'selected' : '' }}>💵 Cash</option>
                                    <option value="cash_equivalent" {{ $account->account_category == 'cash_equivalent' ? 'selected' : '' }}>🏦 Cash Equivalent</option>
                                    <option value="receivable" {{ $account->account_category == 'receivable' ? 'selected' : '' }}>📄 Receivable</option>
                                    <option value="inventory" {{ $account->account_category == 'inventory' ? 'selected' : '' }}>📦 Inventory</option>
                                </select>
                                @if($isUsed || $account->is_system)
                                    <input type="hidden" name="account_category" value="{{ $account->account_category }}">
                                @endif
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Account Code</label>
                            <input type="text" name="code" class="form-control border-dark font-monospace" 
                                   value="{{ old('code', $account->code) }}" 
                                   @if($isUsed || $account->is_system) readonly @endif>
                            
                            @if($account->is_system)
                                <div class="form-text text-danger">⚠️ System account code cannot be modified.</div>
                            @elseif($isUsed)
                                <div class="form-text text-danger">⚠️ Nomor akun dikunci karena sudah memiliki transaksi.</div>
                            @else
                                <div class="form-text text-success">Bersih: Bisa diubah karena belum ada transaksi.</div>
                            @endif
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold d-block">Account Settings</label>
                            <div class="p-3 border rounded bg-light">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_cash_account" id="is_cash_account" value="1" 
                                           {{ $account->is_cash_account ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold" for="is_cash_account">Metode Pembayaran (Kas/Bank/Saldo)</label>
                                </div>
                                <small class="text-muted d-block mt-1">Aktifkan agar akun ini muncul di pilihan metode pembayaran transaksi.</small>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Account Name</label>
                            <input type="text" name="name" class="form-control border-dark" 
                                   value="{{ old('name', $account->name) }}" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Normal Balance</label>
                            <div class="d-flex gap-4 p-2 border rounded @if($isUsed || $account->is_system) bg-light @endif">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="normal_balance" id="nb_debit" value="debit" 
                                           {{ $account->normal_balance == 'debit' ? 'checked' : '' }}
                                           @if($isUsed || $account->is_system) disabled @endif>
                                    <label class="form-check-label {{ $account->normal_balance == 'debit' ? 'text-success fw-bold' : '' }}" for="nb_debit">DEBIT</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="normal_balance" id="nb_credit" value="credit"
                                           {{ $account->normal_balance == 'credit' ? 'checked' : '' }}
                                           @if($isUsed || $account->is_system) disabled @endif>
                                    <label class="form-check-label {{ $account->normal_balance == 'credit' ? 'text-danger fw-bold' : '' }}" for="nb_credit">CREDIT</label>
                                </div>
                            </div>
                            @if($isUsed || $account->is_system)
                                <input type="hidden" name="normal_balance" value="{{ $account->normal_balance }}">
                            @endif
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="/erp/accounting/accounts" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary px-5 shadow-sm">
                                💾 Update Account
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        function toggleCategory() {
            const typeField = document.getElementById('accountType');
            const categoryField = document.getElementById('accountCategory');
            if (!typeField || !categoryField) return;

            const type = typeField.value;

            // Only allow category for Asset
            if (type === 'asset') {
                categoryField.disabled = false;
            } else {
                categoryField.value = '';
                categoryField.disabled = true;
            }
        }

        window.addEventListener('load', () => {
            toggleCategory();
        });

        const typeSelect = document.getElementById('accountType');
        if (typeSelect) {
            typeSelect.addEventListener('change', toggleCategory);
        }
    </script>
@endsection