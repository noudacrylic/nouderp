@extends('layouts.erp')

@section('content')

    <h2>Add Marketplace Integration</h2>

    <form method="POST" action="{{ route('integrations.marketplace.store') }}">
        @csrf

        <label>Marketplace</label><br>
        <select name="customer_id">
            @foreach($marketplaceCustomers as $mp)
                <option value="{{ $mp->id }}">
                    {{ $mp->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Admin %</label><br>
        <input type="number" step="0.01" name="admin_fee_percent"><br><br>

        <label>Admin Nominal</label><br>
        <input type="number" step="0.01" name="admin_fee_nominal"><br><br>

        <label>Saldo Bank Account</label><br>
        <select name="account_bank_id">
            <option value="">-- Let Empty --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}">{{ $acc->name }}</option>
            @endforeach
        </select><br><br>

        <label>Revenue Account</label><br>
        <select name="account_revenue_id">
            <option value="">-- Let Empty --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}">
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Admin Expense</label><br>
        <select name="account_admin_expense_id">
            <option value="">-- Let Empty --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}">
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Recon + (Pendapatan Lain)</label><br>
        <select name="account_recon_plus_id">
            <option value="">-- Let Empty --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}">
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Recon - (Admin Shopee)</label><br>
        <select name="account_recon_minus_id">
            <option value="">-- Let Empty --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}">
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <button type="submit">Save</button>

    </form>

@endsection