@extends('layouts.erp')

@section('content')

    <h2>Marketplace Integration</h2>

    <a href="{{ route('integrations.marketplace.create') }}">
        + Add Integration
    </a>

    <br><br>

    <table border="1" cellpadding="5">
        <tr>
            <th>Name</th>
            <th>Admin %</th>
            <th>Action</th>
        </tr>

        @foreach($marketplaces as $mp)
            <tr>
                <td>{{ $mp->customer->name }}</td>
                <td>{{ $mp->admin_fee_percent }}%</td>
                <td>
                    <a href="{{ route('integrations.marketplace.edit', $mp->id) }}">
                        Edit
                    </a>
                </td>
            </tr>
        @endforeach
    </table>

@endsection