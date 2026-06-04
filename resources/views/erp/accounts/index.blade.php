@extends('layouts.erp')

@section('content')

    <style>
        .section-title {
            font-weight: bold;
            margin-top: 30px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-left: 5px solid #212529;
            text-transform: uppercase;
            font-size: 16px;
            color: #000;
        }

        .category-title {
            font-size: 14px;
            font-weight: 600;
            color: #000;
            margin-top: 18px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
        }

        .category-title::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e9ecef;
            margin-left: 10px;
        }

        .table-coa thead th {
            font-size: 12px;
            color: #000;
            font-weight: 600;
            text-transform: uppercase;
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            padding: 10px 8px;
        }

        .table-coa tbody tr {
            height: 42px;
        }

        .table-coa td {
            vertical-align: middle;
            padding: 8px;
            font-size: 13.5px;
            color: #000;
        }

        .normal-balance {
            font-size: 11px;
            color: #000;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        /* Column Widths */
        .table-coa th:nth-child(1),
        .table-coa td:nth-child(1) {
            width: 90px;
        }

        .table-coa th:nth-child(2),
        .table-coa td:nth-child(2) {
            width: 40%;
            padding-left: 16px;
        }

        .table-coa th:nth-child(3),
        .table-coa td:nth-child(3),
        .table-coa th:nth-child(4),
        .table-coa td:nth-child(4),
        .table-coa th:nth-child(5),
        .table-coa td:nth-child(5) {
            width: 140px;
        }

        .table-coa th:nth-child(6),
        .table-coa td:nth-child(6) {
            width: 180px;
        }

        .btn-action {
            font-size: 12.5px;
            padding: 3px 12px;
            line-height: 1.2;
            border-radius: 4px;
            min-height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            font-weight: 500;
        }

        .action-group {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
        }

        .btn-outline-warning {
            color: #856404;
            border-color: #ffeeba;
            background-color: transparent;
        }

        .btn-outline-warning:hover {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }

        .btn-outline-secondary {
            color: #495057;
            border-color: #dee2e6;
        }

        .btn-outline-secondary:hover {
            background-color: #e9ecef;
            color: #212529;
            border-color: #dee2e6;
        }

        .group-box {
            border-left: 3px solid #dee2e6;
            padding-left: 10px;
            margin-bottom: 30px;
        }
    </style>

    @php
        if (!function_exists('categoryLabel')) {
            function categoryLabel($category)
            {
                return match ($category) {
                    'cash' => '💵 Kas',
                    'cash_equivalent' => '🏦 Setara Kas',
                    'receivable' => '📄 Piutang',
                    'inventory' => '📦 Persediaan',
                    'other' => '⚙️ Akun Lainnya',
                    default => ucfirst(str_replace('_', ' ', $category))
                };
            }
        }

        if (!function_exists('typeLabel')) {
            function typeLabel($type)
            {
                return match ($type) {
                    'asset' => '🟢 ASSET',
                    'liability' => '🔴 LIABILITY',
                    'equity' => '🧱 EQUITY',
                    'revenue' => '💰 REVENUE',
                    'expense' => '📉 EXPENSE',
                    default => strtoupper($type)
                };
            }
        }
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0 fw-bold" style="color: #000;">Chart of Accounts</h2>
        <div class="d-flex gap-2 text-dark">
            
            {{-- STATUS FILTER --}}
            <form method="GET" id="filterForm" class="d-flex gap-2 me-2">
                <select name="status" class="form-select shadow-sm border-dark" onchange="this.form.submit()" style="height: 38px;">
                    <option value="active" {{ request('status', 'active') == 'active' ? 'selected' : '' }}>🟢 Active Accounts</option>
                    <option value="archived" {{ request('status') == 'archived' ? 'selected' : '' }}>📁 Archived Accounts</option>
                    <option value="all" {{ request('status') == 'all' ? 'selected' : '' }}>📂 All Accounts</option>
                </select>
            </form>

            <div style="width: 250px;">
                <input type="text" id="searchAccount" class="form-control shadow-sm border-dark"
                    placeholder="🔍 Cari kode / nama akun..." style="height: 38px; font-size: 14px;">
            </div>
            <a href="{{ route('accounts.create') }}" class="btn btn-primary shadow-sm d-flex align-items-center px-4">
                + Create Account
            </a>
        </div>
    </div>

    @forelse($groups as $type => $categories)

        <div class="group-box account-type-wrapper">
            <h4 class="section-title">{{ typeLabel($type) }}</h4>

            @foreach($categories as $category => $accounts)

                <div class="account-category-wrapper {{ ($type === 'asset' || $category !== 'main') ? 'mb-3' : '' }}">

                    @if($type === 'asset' || $category !== 'main')
                        <div class="category-title">
                            {{ categoryLabel($category) }}
                        </div>
                    @endif

                <div class="card shadow-sm mb-3">
                    <div class="table-responsive">
                        <table class="table table-coa table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Kode</th>
                                        <th>Nama Akun</th>
                                        <th class="text-end">Debit</th>
                                        <th class="text-end">Credit</th>
                                        <th class="text-end">Saldo</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($accounts as $acc)
                                        @php
                                            $isUsed = $acc->journalLines->count() > 0;
                                        @endphp
                                        <tr class="account-row {{ !$acc->is_active ? 'table-secondary opacity-75' : '' }}"
                                            data-code="{{ strtolower($acc->code) }}"
                                            data-name="{{ strtolower($acc->name) }}"
                                            data-href="{{ route('accounts.show', $acc->id) }}"
                                            role="button"
                                            tabindex="0"
                                            title="Klik untuk membuka ledger">
                                            <td class="ps-3 font-monospace" style="color: #000;">{{ $acc->code }}</td>
                                            <td class="text-start">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="fw-medium account-name">{{ $acc->name }}</div>
                                                    @if(!$acc->is_active)
                                                        <span class="badge bg-secondary" style="font-size: 8.5px; padding: 2px 5px;">ARCHIVED</span>
                                                    @endif
                                                </div>
                                                <div class="normal-balance">
                                                    {{ strtoupper($acc->normal_balance) }}
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                {{ number_format($acc->debit ?? 0, 2) }}
                                            </td>
                                            <td class="text-end">
                                                {{ number_format($acc->credit ?? 0, 2) }}
                                            </td>
                                            <td class="text-end fw-bold {{ ($acc->balance ?? 0) < 0 ? 'text-danger' : (($acc->balance ?? 0) > 0 ? 'text-success' : 'text-muted') }}">
                                                {{ number_format($acc->balance ?? 0, 2) }}
                                            </td>
                                            <td class="text-end pe-3 align-middle">
                                                <div class="action-group">
                                                    @if(!$acc->is_system)
                                                        <a href="{{ route('accounts.edit', $acc->id) }}"
                                                            onclick="event.stopPropagation();"
                                                            class="btn btn-outline-warning btn-action" title="Edit">
                                                            Edit
                                                        </a>

                                                        @if($acc->is_active)
                                                            @if($isUsed)
                                                                <form action="{{ route('accounts.archive', $acc->id) }}" method="POST" class="m-0 d-inline">
                                                                    @csrf
                                                                    <button type="submit"
                                                                        onclick="event.stopPropagation(); return confirm('Arsipkan akun ini?')"
                                                                        class="btn btn-outline-secondary btn-action">
                                                                        Archive
                                                                    </button>
                                                                </form>
                                                            @else
                                                                <form action="{{ route('accounts.destroy', $acc->id) }}" method="POST" class="m-0 d-inline">
                                                                    @csrf @method('DELETE')
                                                                    <button type="submit"
                                                                        onclick="event.stopPropagation(); return confirm('Hapus akun ini?')"
                                                                        class="btn btn-outline-danger btn-action">
                                                                        Delete
                                                                    </button>
                                                                </form>
                                                            @endif
                                                        @else
                                                            <form action="{{ route('accounts.restore', $acc->id) }}" method="POST" class="m-0 d-inline">
                                                                @csrf
                                                                <button type="submit" onclick="event.stopPropagation();"
                                                                    class="btn btn-outline-success btn-action">
                                                                    Restore
                                                                </button>
                                                            </form>
                                                        @endif
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

            @endforeach
        </div>

    @empty
        <div class="alert alert-info border-info text-dark">
            <i class="fas fa-info-circle me-2"></i> Belum ada data akun untuk kriteria filter ini.
        </div>
    @endforelseun.
        </div>
    @endforelse

@endsection

@section('scripts')
    <script>
        document.getElementById('searchAccount').addEventListener('keyup', function() {

            const keyword = this.value.toLowerCase();
            const rows = document.querySelectorAll('.account-row');

            rows.forEach(row => {
                const code = row.getAttribute('data-code');
                const name = row.getAttribute('data-name');

                if (code.includes(keyword) || name.includes(keyword)) {
                    row.style.display = '';
                    row.style.backgroundColor = keyword.length > 0 ? '#fff3cd' : '';
                } else {
                    row.style.display = 'none';
                    row.style.backgroundColor = '';
                }
            });

            // Update visibility of category and type wrappers
            document.querySelectorAll('.account-category-wrapper').forEach(categorySection => {
                let visibleRows = categorySection.querySelectorAll('.account-row:not([style*="display: none"])');
                categorySection.style.display = visibleRows.length ? '' : 'none';
            });

            document.querySelectorAll('.account-type-wrapper').forEach(typeSection => {
                let visibleCategories = typeSection.querySelectorAll(
                    '.account-category-wrapper:not([style*="display: none"])');
                typeSection.style.display = visibleCategories.length ? '' : 'none';
            });
        });

        document.querySelectorAll('.account-row').forEach(row => {
            row.addEventListener('click', function (event) {
                if (event.target.closest('.action-group')) {
                    return;
                }
                const href = this.dataset.href;
                if (href) window.location = href;
            });

            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    const href = this.dataset.href;
                    if (href) window.location = href;
                }
            });
        });
    </script>
@endsection
