@extends('layouts.erp')

@section('content')

    <style>
        .sticky-filter {
            position: sticky;
            top: 0;
            background: #f3f4f6;
            z-index: 100;
            padding-bottom: 15px;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .month-header {
            font-size: 16px;
            font-weight: 700;
            background: #212529;
            padding: 10px 15px;
            border-radius: 5px;
            color: #fff;
            margin-top: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-ledger td {
            vertical-align: middle;
            font-size: 13px;
            color: #000;
        }

        .table-ledger thead th {
            font-size: 12px;
            text-transform: uppercase;
            color: #495057;
            background: #f8f9fa;
        }

        .table-ledger tbody tr:hover {
            background-color: #fff3cd !important;
        }

        .ref-link {
            font-weight: 600;
            color: #0d6efd;
            text-decoration: none;
        }

        .ref-link:hover {
            text-decoration: underline;
        }
    </style>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('accounts.index') }}">COA</a></li>
                    <li class="breadcrumb-item active">{{ $account->code }}</li>
                </ol>
            </nav>
            <h2 class="mb-0 fw-bold">Ledger: {{ $account->name }}</h2>
        </div>
        <div class="text-end">
            <div class="text-muted small">Normal Balance</div>
            <span class="badge bg-dark">{{ strtoupper($account->normal_balance) }}</span>
        </div>
    </div>

    <!-- STICKY FILTER & SEARCH -->
    <div class="sticky-filter">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">

            {{-- FILTER TAHUN & BULAN --}}
            <form method="GET" class="d-flex align-items-end gap-2">
                <div>
                    <label class="form-label mb-1 fw-bold text-muted small">Tahun</label>
                    <select name="year" onchange="this.form.submit()"
                            class="form-select form-select-sm border-dark shadow-sm" style="height: 38px; min-width: 110px;">
                        <option value="">— Semua —</option>
                        @foreach ($availableYears as $y)
                            <option value="{{ $y }}" {{ $selectedYear === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1 fw-bold text-muted small">Bulan</label>
                    <select name="month" onchange="this.form.submit()"
                            class="form-select form-select-sm border-dark shadow-sm" style="height: 38px; min-width: 140px;">
                        <option value="">— Semua Bulan —</option>
                        @for ($m = 1; $m <= 12; $m++)
                            @php $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT); @endphp
                            <option value="{{ $mm }}" {{ $selectedMonth === $mm ? 'selected' : '' }}>
                                {{ \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('F') }}
                            </option>
                        @endfor
                    </select>
                </div>
                @if (request()->hasAny(['year', 'month']))
                    <div>
                        <a href="{{ url()->current() }}" class="btn btn-outline-secondary d-flex align-items-center" style="height: 38px;">
                            Reset
                        </a>
                    </div>
                @endif
            </form>

            {{-- FILTER SECTION (rentang tanggal) --}}
            <form method="GET" class="d-flex align-items-end gap-2 flex-grow-1">
                <div>
                    <label class="form-label mb-1 fw-bold text-muted small">Start Date</label>
                    <input type="date" name="start_date" class="form-control form-control-sm border-dark shadow-sm"
                           value="{{ request('start_date') }}" style="height: 38px;">
                </div>
                <div>
                    <label class="form-label mb-1 fw-bold text-muted small">End Date</label>
                    <input type="date" name="end_date" class="form-control form-control-sm border-dark shadow-sm"
                           value="{{ request('end_date') }}" style="height: 38px;">
                </div>
                <div>
                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" style="height: 38px;">
                        🔍 Filter Period
                    </button>
                </div>
                @if (request('start_date') || request('end_date'))
                    <div>
                        <a href="{{ url()->current() }}" class="btn btn-outline-secondary d-flex align-items-center" style="height: 38px;">
                            Reset
                        </a>
                    </div>
                @endif
            </form>

            {{-- SEARCH SECTION --}}
            <div style="width: 350px;">
                <label class="form-label mb-1 fw-bold text-muted small">Quick Search</label>
                <input type="text" id="searchRef" class="form-control border-primary shadow-sm"
                    placeholder="🔍 Cari referensi / deskripsi..." style="height: 38px; font-size: 14px;">
            </div>

        </div>
    </div>

    @forelse($grouped as $month => $items)
        <div class="month-group-wrapper">
            <div class="month-header shadow-sm mb-3">
                <span>📅 {{ \Carbon\Carbon::parse($month . '-01')->translatedFormat('F Y') }}</span>
                <span class="badge bg-light text-dark small" style="font-size: 11px;">
                    {{ $items->count() }} Transaksi
                </span>
            </div>

            <div class="card shadow-sm border-0 mb-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-ledger table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width: 110px">Tanggal</th>
                                <th style="width: 140px">Journal #</th>
                                <th style="width: 160px">Ref</th>
                                <th style="min-width: 250px">Keterangan</th>
                                <th class="text-end" style="width: 150px">Debit</th>
                                <th class="text-end" style="width: 150px">Credit</th>
                                <th class="text-end" style="width: 180px">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $line)
                                <tr class="ledger-row {{ $line->debit > 2000000 || $line->credit > 2000000 ? 'table-warning' : '' }}"
                                    data-ref="{{ strtolower($line->reference_number ?? '') }}"
                                    data-desc="{{ strtolower($line->description ?? '') }}">
                                    <td class="ps-3 text-muted">
                                        {{ $line->created_at->format('d/m/Y') }}
                                    </td>
                                    <td class="fw-bold text-dark">
                                        {{ $line->journal->journal_number }}
                                    </td>
                                    <td class="ref-cell">
                                        @if ($line->reference_number)
                                            @php
                                                $refUrl = match($line->reference_type ?? '') {
                                                    'sales_invoice'         => \Route::has('sales.invoices.show')
                                                                                ? route('sales.invoices.show', $line->reference_id) : null,
                                                    'sales_delivery',
                                                    'delivery'              => \Route::has('sales.deliveries.show')
                                                                                ? route('sales.deliveries.show', $line->reference_id) : null,
                                                    'sales_order'           => \Route::has('sales.orders.show')
                                                                                ? route('sales.orders.show', $line->reference_id) : null,
                                                    'sales_advance'         => \Route::has('sales.advances.show')
                                                                                ? route('sales.advances.show', $line->reference_id) : null,
                                                    'sales_return'          => \Route::has('sales.returns.show')
                                                                                ? route('sales.returns.show', $line->reference_id) : null,
                                                    'customer_payment'      => \Route::has('sales.payment.index')
                                                                                ? route('sales.payment.index') : null,
                                                    'inventory_adjustment'  => \Route::has('inventory.adjustments.index')
                                                                                ? route('inventory.adjustments.index') : null,
                                                    default => null,
                                                };
                                            @endphp
                                            @if ($refUrl)
                                                <a href="{{ $refUrl }}" class="ref-link" title="{{ ucwords(str_replace('_', ' ', $line->reference_type ?? '')) }}">
                                                    {{ $line->reference_number }}
                                                </a>
                                            @else
                                                <span class="fw-semibold text-dark" title="{{ ucwords(str_replace('_', ' ', $line->reference_type ?? '')) }}">
                                                    {{ $line->reference_number }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-medium">{{ $line->description }}</div>
                                        @if($line->journal && $line->journal->description !== $line->description)
                                            <div class="text-muted" style="font-size: 11px;">{{ $line->journal->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($line->debit > 0)
                                            {{ number_format($line->debit, 2) }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($line->credit > 0)
                                            {{ number_format($line->credit, 2) }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-bold {{ $line->running_balance < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ number_format($line->running_balance, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-info text-center py-5 shadow-sm">
            <h4>No transactions found</h4>
            <p class="mb-0">Please adjust your date filter or check back later.</p>
        </div>
    @endforelse

@endsection

@section('scripts')
    <script>
        let searchTimer;

        document.getElementById('searchRef').addEventListener('keyup', function() {
            
            clearTimeout(searchTimer);
            const input = this;

            searchTimer = setTimeout(() => {
                const keyword = input.value.toLowerCase();
                const rows = document.querySelectorAll('.ledger-row');

                rows.forEach(row => {
                    const ref = row.getAttribute('data-ref') || '';
                    const desc = row.getAttribute('data-desc') || '';

                    if (ref.includes(keyword) || desc.includes(keyword)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Update visibility of month groups
                document.querySelectorAll('.month-group-wrapper').forEach(monthGroup => {
                    let visibleRows = monthGroup.querySelectorAll('.ledger-row:not([style*="display: none"])');
                    monthGroup.style.display = visibleRows.length ? '' : 'none';
                });
            }, 200); // Debounce delay 200ms
        });
    </script>
@endsection