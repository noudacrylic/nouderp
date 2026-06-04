@extends('layouts.erp')

@section('content')

    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 fw-bold">✨ Create New Account</h5>
                </div>
                <div class="card-body p-4">

                    <form method="POST" action="/erp/accounting/accounts" id="accountForm">
                        @csrf

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Account Type</label>
                                <select name="type" id="accountType" class="form-select border-dark" required>
                                    <option value="" disabled selected>Select Type</option>
                                    <option value="asset">🟢 Asset</option>
                                    <option value="liability">🔴 Liability</option>
                                    <option value="equity">🧱 Equity</option>
                                    <option value="revenue">💰 Revenue</option>
                                    <option value="expense">📉 Expense</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Category (Optional)</label>
                                <select name="account_category" id="accountCategory" class="form-select">
                                    <option value="">- None -</option>
                                    <option value="cash">💵 Cash</option>
                                    <option value="cash_equivalent">🏦 Cash Equivalent</option>
                                    <option value="receivable">📄 Receivable</option>
                                    <option value="inventory">📦 Inventory</option>
                                </select>
                                <small class="text-muted">Mainly for Assets grouping</small>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Account Code</label>
                            <div class="input-group">
                                <input type="text" name="code" class="form-control border-dark font-monospace" 
                                       placeholder="e.g. 1101" required value="{{ old('code') }}">
                                <button class="btn btn-dark" type="button" onclick="generateCode(true)">
                                    ⚡ Auto Generate
                                </button>
                            </div>
                            <div class="form-text">Format: X (Type) Y (Category) ZZ (Sequence)</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold d-block">Account Settings</label>
                            <div class="p-3 border rounded bg-light">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_cash_account" id="is_cash_account" value="1">
                                    <label class="form-check-label fw-bold" for="is_cash_account">Metode Pembayaran (Kas/Bank/Saldo)</label>
                                </div>
                                <small class="text-muted d-block mt-1">Aktifkan jika akun ini bisa digunakan untuk menerima pembayaran atau membayar biaya (muncul di dropdown payment).</small>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Account Name</label>
                            <input type="text" name="name" class="form-control border-dark" 
                                   placeholder="e.g. Bank BCA Utama" required value="{{ old('name') }}">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Normal Balance</label>
                            <div class="d-flex gap-4 p-2 border rounded bg-light">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="normal_balance" id="nb_debit" value="debit" checked>
                                    <label class="form-check-label" for="nb_debit text-success fw-bold">DEBIT</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="normal_balance" id="nb_credit" value="credit">
                                    <label class="form-check-label" for="nb_credit text-danger fw-bold">CREDIT</label>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="/erp/accounting/accounts" class="btn btn-outline-secondary">
                                ← Back to COA
                            </a>
                            <button type="submit" class="btn btn-primary px-5 shadow-sm">
                                💾 Save Account
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
            const type = typeField.value;

            if (type === 'asset') {
                categoryField.disabled = false;
            } else {
                categoryField.value = '';
                categoryField.disabled = true;
            }
        }

        function generateCode(force = false) {
            const typeField = document.getElementById('accountType');
            const categoryField = document.getElementById('accountCategory');
            const codeInput = document.querySelector('[name="code"]');

            const type = typeField.value;
            const category = categoryField.value;

            if (!type) return;

            // Only auto-fill if the code input is empty OR if user explicitly clicked "Auto Generate"
            if (!force && codeInput.value.length > 0) return;

            fetch(`/erp/accounting/accounts/next-code?type=${type}&category=${category}`)
                .then(res => res.json())
                .then(data => {
                    if (data.code) {
                        codeInput.value = data.code;
                        
                        // Suggest normal balance based on type
                        if (['asset', 'expense'].includes(type)) {
                            document.getElementById('nb_debit').checked = true;
                        } else {
                            document.getElementById('nb_credit').checked = true;
                        }

                        // Add a small highlight animation
                        codeInput.classList.add('bg-warning', 'bg-opacity-25');
                        setTimeout(() => codeInput.classList.remove('bg-warning', 'bg-opacity-25'), 500);
                    }
                })
                .catch(err => console.error('Error fetching next code:', err));
        }

        // Set default focus and toggle initial state
        window.addEventListener('load', () => {
             document.querySelector('[name="name"]').focus();
             toggleCategory();
        });

        document.getElementById('accountType').addEventListener('change', () => {
            toggleCategory();
            generateCode();
        });

        document.getElementById('accountCategory').addEventListener('change', () => {
            generateCode();
        });
    </script>
    <style>
        select:disabled {
            background-color: #e9ecef !important;
            cursor: not-allowed;
            opacity: 0.7;
        }
    </style>
@endsection