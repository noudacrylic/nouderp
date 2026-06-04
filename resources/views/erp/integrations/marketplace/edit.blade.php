@extends('layouts.erp')

@section('content')

    <h2>Edit Marketplace: {{ $marketplace->customer->name ?? '-' }}</h2>

    <form method="POST" action="{{ route('integrations.marketplace.update', $marketplace->id) }}">
        @csrf

        <label>Admin Fee (%)</label><br>
        <input type="number" step="0.01" name="admin_fee_percent" value="{{ $marketplace->admin_fee_percent }}"><br><br>

        <label>Admin Fee Nominal</label><br>
        <input type="number" step="0.01" name="admin_fee_nominal" value="{{ $marketplace->admin_fee_nominal }}"><br><br>

        <label>Saldo Bank</label><br>
        <select name="account_bank_id">
            <option value="">-- Kosong --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ $marketplace->account_bank_id == $acc->id ? 'selected' : '' }}>
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Revenue Account</label><br>
        <select name="account_revenue_id">
            <option value="">-- Kosong --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ $marketplace->account_revenue_id == $acc->id ? 'selected' : '' }}>
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Admin Expense</label><br>
        <select name="account_admin_expense_id">
            <option value="">-- Kosong --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ $marketplace->account_admin_expense_id == $acc->id ? 'selected' : '' }}>
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Recon + (Pendapatan Lain)</label><br>
        <select name="account_recon_plus_id">
            <option value="">-- Kosong --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ $marketplace->account_recon_plus_id == $acc->id ? 'selected' : '' }}>
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <label>Recon - (Admin Shopee)</label><br>
        <select name="account_recon_minus_id">
            <option value="">-- Kosong --</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" {{ $marketplace->account_recon_minus_id == $acc->id ? 'selected' : '' }}>
                    {{ $acc->name }}
                </option>
            @endforeach
        </select><br><br>

        <button type="submit">Save</button>

    </form>

@endsection