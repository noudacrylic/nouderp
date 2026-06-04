@extends('layouts.erp')

@section('content')

    <div class="bg-white rounded shadow p-6">

        {{-- ACTION BUTTONS / HEADER --}}
        @stack('transaction-header')

        {{-- CONTENT --}}
        @yield('transaction-content')
        @yield('content') {{-- Support standard content section --}}

    </div>

@endsection