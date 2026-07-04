@extends('layouts.erp')

@section('content')
<style>
    /* Resizable column handle untuk tabel rekonsiliasi */
    #reconTable th.resizable {
        position: relative;
        border-right: 1px solid #e5e7eb;  /* visual divider antar kolom */
    }
    #reconTable th .col-resizer {
        position: absolute;
        top: 0;
        right: -4px;
        width: 8px;
        height: 100%;
        cursor: col-resize;
        user-select: none;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    /* Garis vertikal indikator handle — selalu terlihat tipis */
    #reconTable th .col-resizer::after {
        content: '';
        width: 2px;
        height: 60%;
        background: #cbd5e1;
        border-radius: 1px;
        transition: background 0.12s, width 0.12s;
    }
    #reconTable th .col-resizer:hover::after,
    #reconTable.resizing th .col-resizer.active::after {
        background: #16a34a;
        width: 3px;
        height: 100%;
    }
    #reconTable th .col-resizer:hover {
        background: rgba(34, 197, 94, 0.08);
    }
    #reconTable.resizing { cursor: col-resize; user-select: none; }
    #reconTable.resizing th .col-resizer.active {
        background: rgba(34, 197, 94, 0.15);
    }
    #reconTable td { word-wrap: break-word; overflow-wrap: break-word; }
</style>

@php
    $refMap = function($jl) {
        $type   = $jl->reference_type ?: ($jl->journal->reference_type ?? null);
        $id     = $jl->reference_id   ?: ($jl->journal->reference_id ?? null);
        $refNum = $jl->reference_number ?: ($jl->journal->reference_number ?? null);
        $url = null; $label = 'Jurnal Umum'; $color = 'gray';
        switch ($type) {
            case 'sales_invoice':
                $label = 'Faktur Penjualan'; $color = 'blue';
                $url = $id ? route('sales.invoices.show', $id) : null; break;
            case 'purchase_invoice':
                $label = 'Faktur Pembelian'; $color = 'amber';
                $url = $id ? route('purchasing.invoices.show', $id) : null; break;
            case 'supplier_payment':
                $label = 'Bayar Pemasok'; $color = 'rose';
                $url = $id ? route('purchasing.payments.show', $id) : null; break;
            case 'cash_receipt':
                $label = 'Pemasukan Kas'; $color = 'green';
                $url = $id ? route('finance.cash-bank.receipts.show', $id) : null; break;
            case 'cash_disbursement':
                $label = 'Pengeluaran Kas'; $color = 'red';
                $url = $id ? route('finance.cash-bank.disbursements.show', $id) : null; break;
            case 'bank_transfer':
                $label = 'Transfer Bank'; $color = 'purple';
                $url = $id ? route('finance.cash-bank.transfers.show', $id) : null; break;
            case 'marketplace_settlement':
                $label = 'Settlement Marketplace'; $color = 'indigo';
                $url = $id ? route('finance.cash-bank.settlements.show', $id) : null; break;
            case 'warranty_order':
                $label = 'Order Garansi'; $color = 'teal';
                $url = $id ? route('sales.warranty.show', $id) : null; break;
            case 'sales_advance':
                $label = 'DP Pelanggan'; $color = 'cyan'; break;
            case 'purchase_return':
                $label = 'Retur Pembelian'; $color = 'orange';
                $url = $id ? route('purchasing.returns.show', $id) : null; break;
            case 'sales_return':
                $label = 'Retur Penjualan'; $color = 'orange';
                $url = $id ? route('sales.returns.show', $id) : null; break;
            case 'fixed_asset_depreciation_period':
                $label = 'Penyusutan Aset'; $color = 'slate'; break;
            case null: case '':
                $label = 'Jurnal Umum'; $color = 'gray'; break;
            default:
                $label = ucwords(str_replace('_',' ', $type)); $color = 'gray'; break;
        }
        return ['label' => $label, 'color' => $color, 'url' => $url, 'ref_number' => $refNum];
    };
    $colorCls = fn($c) => match($c) {
        'blue'   => 'bg-blue-100 text-blue-700',
        'amber'  => 'bg-amber-100 text-amber-700',
        'rose'   => 'bg-rose-100 text-rose-700',
        'green'  => 'bg-green-100 text-green-700',
        'red'    => 'bg-red-100 text-red-700',
        'purple' => 'bg-purple-100 text-purple-700',
        'indigo' => 'bg-indigo-100 text-indigo-700',
        'teal'   => 'bg-teal-100 text-teal-700',
        'cyan'   => 'bg-cyan-100 text-cyan-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'slate'  => 'bg-slate-100 text-slate-700',
        default  => 'bg-gray-100 text-gray-700',
    };
@endphp

<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Rekonsiliasi {{ $br->number }}</h1>
        <div class="text-xs text-gray-500">{{ $br->account->code }} — {{ $br->account->name }} · {{ $br->start_date->format('d M Y') }} – {{ $br->end_date->format('d M Y') }}</div>
    </div>
    <div class="flex items-center gap-2">
        <button type="button" onclick="openTransferModal()"
                class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded text-sm font-semibold">
            + Transfer
        </button>
        <button type="button" onclick="openQuickModal('disbursement')"
                class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded text-sm font-semibold">
            + Pengeluaran
        </button>
        <button type="button" onclick="openQuickModal('receipt')"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded text-sm font-semibold">
            + Pemasukan
        </button>
        <button type="button" onclick="openStatementModal()"
                class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-semibold inline-flex items-center gap-1">
            ⬆ Upload Koran
            @if(($statementMatch['total'] ?? 0) > 0)
                <span class="bg-white/25 rounded px-1.5 text-[11px]">{{ $statementMatch['total'] }}</span>
            @endif
        </button>
        <a href="{{ route('finance.cash-bank.reconciliations.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← Daftar</a>
    </div>
</div>

<form method="POST" action="{{ route('finance.cash-bank.reconciliations.update', $br->id) }}">
    @csrf
    @method('PUT')

    @if(session('error') || $errors->any())
        <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-3">
            {{ session('error') }}
            @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
        </div>
    @endif

    {{-- Header ringkas 1 baris --}}
    <div class="bg-white rounded shadow px-3 py-2 mb-3 grid grid-cols-6 gap-3 text-sm items-end">
        <div>
            <div class="text-[11px] text-gray-500">Saldo Awal</div>
            <div class="font-semibold">{{ number_format($br->opening_balance, 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="text-[11px] text-gray-500">Saldo Buku</div>
            <div class="font-semibold">{{ number_format($br->book_balance, 0, ',', '.') }}</div>
        </div>
        <div>
            <label class="block text-[11px] text-gray-500 mb-0.5">Saldo Per Rek. Koran</label>
            <input type="text" inputmode="numeric" name="statement_balance" id="statementInput"
                   value="{{ $br->statement_balance }}" class="border rounded px-2 py-1 w-full text-right text-sm rupiah-input">
        </div>
        <div>
            <div class="text-[11px] text-gray-500">Selisih (Buku − Koran)</div>
            <div id="diffDisplay" class="font-semibold">{{ number_format($br->difference, 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="text-[11px] text-gray-500">Σ Sudah Cocok (Dr−Cr)</div>
            <div class="font-semibold text-green-700" id="matchedSum">{{ number_format($matchedSum, 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="text-[11px] text-gray-500">Σ Belum Cocok</div>
            <div class="font-semibold text-amber-700" id="unmatchedSum">{{ number_format($unmatchedSum, 0, ',', '.') }}</div>
        </div>
    </div>

    {{-- Ringkasan pencocokan rekening koran --}}
    @if(($statementMatch['total'] ?? 0) > 0)
        <div class="bg-white rounded shadow px-3 py-2 mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            <span class="font-semibold text-gray-700">Rekening Koran:</span>
            <span class="text-gray-600">{{ $statementMatch['total'] }} baris</span>
            <span class="inline-flex items-center gap-1 text-green-700">
                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                {{ $statementMatch['exact'] }} cocok persis
            </span>
            <span class="inline-flex items-center gap-1 text-amber-700">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                {{ $statementMatch['amount_only'] }} nilai cocok (tanggal beda)
            </span>
            <span class="inline-flex items-center gap-1 text-rose-700">
                <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                {{ $statementMatch['unmatched_count'] }} belum ada di ERP
            </span>
            @if($statementMatch['exact'] > 0)
                <button type="button" id="matchExactBtn"
                        class="ml-auto bg-green-600 hover:bg-green-700 text-white px-2.5 py-1 rounded text-xs font-semibold">
                    ✓ Cocokkan semua yang cocok persis ({{ $statementMatch['exact'] }})
                </button>
            @endif
        </div>
    @endif

    {{-- Tabel transaksi --}}
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="text-sm" id="reconTable" style="table-layout: fixed; width: 100%;">
            <colgroup>
                <col data-col-key="tanggal" style="width: 110px">
                <col data-col-key="sumber" style="width: 240px">
                <col data-col-key="keterangan">
                <col data-col-key="debit" style="width: 140px">
                <col data-col-key="kredit" style="width: 140px">
                <col data-col-key="saldo" style="width: 140px">
                <col data-col-key="koran" style="width: 170px">
                <col data-col-key="status" style="width: 140px">
            </colgroup>
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left resizable">Tanggal<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-left resizable">Sumber / Transaksi<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-left resizable">Keterangan<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-right resizable">Debit (Masuk)<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-right resizable">Kredit (Keluar)<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-right resizable">Saldo<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-left resizable">Rek. Koran<span class="col-resizer"></span></th>
                    <th class="px-3 py-2 text-right">Status Cocok</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b bg-gray-50/70 italic text-gray-500">
                    <td class="px-3 py-1.5 whitespace-nowrap">{{ $br->start_date->format('d M Y') }}</td>
                    <td class="px-3 py-1.5" colspan="2">Saldo awal periode</td>
                    <td></td>
                    <td></td>
                    <td class="px-3 py-1.5 text-right font-semibold font-mono">{{ number_format($br->opening_balance, 0, ',', '.') }}</td>
                    <td></td>
                    <td></td>
                </tr>
                @php $running = (float) $br->opening_balance; @endphp
                @forelse($br->lines as $line)
                    @php
                        $jl = $line->journalLine;
                        $ref = $refMap($jl);
                        $running += (float)$jl->debit - (float)$jl->credit;
                        $sm = $statementMatch['lineMatch'][$jl->id] ?? null;
                    @endphp
                    <tr class="border-b match-row {{ $line->is_matched ? 'bg-green-50' : ($sm && $sm['status']==='amount' ? 'bg-amber-50/50' : '') }}">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ \Carbon\Carbon::parse($jl->journal->date)->format('d M Y') }}</td>
                        <td class="px-3 py-1.5">
                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold {{ $colorCls($ref['color']) }}">{{ $ref['label'] }}</span>
                            @if($ref['url'])
                                <a href="{{ $ref['url'] }}" class="text-blue-600 hover:underline ml-1 font-mono text-xs" target="_blank" rel="noopener">
                                    {{ $ref['ref_number'] ?: ($jl->journal->journal_number ?? '#'.$jl->journal_id) }}
                                </a>
                            @else
                                <span class="ml-1 font-mono text-xs text-gray-700">{{ $ref['ref_number'] ?: ($jl->journal->journal_number ?? '#'.$jl->journal_id) }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-1.5 text-gray-700">{{ $jl->description ?: ($jl->journal->description ?? '-') }}</td>
                        <td class="px-3 py-1.5 text-right">{{ (float)$jl->debit > 0 ? number_format($jl->debit, 0, ',', '.') : '' }}</td>
                        <td class="px-3 py-1.5 text-right">{{ (float)$jl->credit > 0 ? number_format($jl->credit, 0, ',', '.') : '' }}</td>
                        <td class="px-3 py-1.5 text-right font-mono">{{ number_format($running, 0, ',', '.') }}</td>
                        <td class="px-3 py-1.5">
                            @if($sm && $sm['status'] === 'exact')
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-700">
                                    ● Cocok persis
                                </span>
                            @elseif($sm && $sm['status'] === 'amount')
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700"
                                      title="Nilai sama tapi tanggal di rekening koran berbeda — kemungkinan tanggal transaksi ERP salah.">
                                    ● Nilai cocok
                                </span>
                                <div class="text-[10px] text-amber-600 mt-0.5">Koran: {{ \Carbon\Carbon::parse($sm['date'])->format('d M Y') }}</div>
                            @else
                                <span class="text-gray-300 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-1.5 text-right">
                            <input type="hidden" name="matched_journal_line_ids[]" value="{{ $jl->id }}"
                                   class="match-input" {{ $line->is_matched ? '' : 'disabled' }}>
                            <button type="button"
                                    class="match-btn px-2.5 py-1 rounded text-xs font-semibold border transition
                                           {{ $line->is_matched ? 'bg-green-600 text-white border-green-600 hover:bg-green-700' : ($sm && $sm['status']==='exact' ? 'bg-white text-green-700 border-green-400 hover:bg-green-50 ring-1 ring-green-300' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-100') }}"
                                    data-debit="{{ (float) $jl->debit }}"
                                    data-credit="{{ (float) $jl->credit }}"
                                    data-koran="{{ $sm['status'] ?? '' }}">
                                {{ $line->is_matched ? '✓ Sudah Cocok' : 'Cocokkan' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Tidak ada transaksi pada periode ini.</td></tr>
                @endforelse
                <tr class="border-t bg-gray-50/70 italic text-gray-600">
                    <td class="px-3 py-1.5 whitespace-nowrap">{{ $br->end_date->format('d M Y') }}</td>
                    <td class="px-3 py-1.5" colspan="2">Saldo akhir buku</td>
                    <td></td>
                    <td></td>
                    <td class="px-3 py-1.5 text-right font-semibold font-mono">{{ number_format($running, 0, ',', '.') }}</td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Baris rekening koran yang belum ada padanannya di ERP --}}
    @if(!empty($statementMatch['unmatched']))
        <div class="bg-white rounded shadow mt-3 border border-rose-200">
            <div class="px-3 py-2 border-b border-rose-100 bg-rose-50 rounded-t flex items-center gap-2">
                <span class="text-rose-700 font-semibold text-sm">⚠ Transaksi rekening koran belum ada di ERP ({{ count($statementMatch['unmatched']) }})</span>
                <span class="text-xs text-rose-500">Buat transaksinya lewat tombol <b>+ Pengeluaran</b> / <b>+ Pemasukan</b> di atas, lalu klik Cocokkan pada barisnya.</span>
            </div>
            <table class="text-sm w-full">
                <thead class="bg-gray-50 border-b text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Tanggal</th>
                        <th class="px-3 py-2 text-left">Keterangan</th>
                        <th class="px-3 py-2 text-right">Uang Masuk</th>
                        <th class="px-3 py-2 text-right">Uang Keluar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($statementMatch['unmatched'] as $u)
                        <tr class="border-b">
                            <td class="px-3 py-1.5 whitespace-nowrap">{{ \Carbon\Carbon::parse($u['date'])->format('d M Y') }}</td>
                            <td class="px-3 py-1.5 text-gray-700">{{ $u['desc'] ?: '-' }}</td>
                            <td class="px-3 py-1.5 text-right font-mono text-green-700">{{ $u['amount'] > 0 ? number_format($u['amount'], 0, ',', '.') : '' }}</td>
                            <td class="px-3 py-1.5 text-right font-mono text-red-600">{{ $u['amount'] < 0 ? number_format(abs($u['amount']), 0, ',', '.') : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Catatan paling bawah --}}
    <div class="bg-white rounded shadow p-3 mt-3">
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="2" class="border rounded px-2 py-1.5 w-full text-sm">{{ $br->notes }}</textarea>
    </div>

    <div class="flex justify-between border-t pt-3 mt-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('finance.cash-bank.reconciliations.index') }}" class="text-gray-500 text-sm">← Kembali</a>
            <button type="button" id="toggleAll" class="text-xs text-blue-600 hover:underline">Cocokkan / Batalkan Semua</button>
        </div>
        <div class="flex gap-2">
            <button type="submit" name="_after_save" value="" class="bg-gray-600 text-white px-4 py-2 rounded text-sm">Simpan Draf</button>
            <button type="submit" name="_after_save" value="complete" class="bg-green-600 text-white px-4 py-2 rounded text-sm"
                    onclick="return confirm('Selesaikan rekonsiliasi? Status berubah jadi selesai.')">Selesai</button>
        </div>
    </div>
</form>

<script>
const bookBalance = {{ (float) $br->book_balance }};
function fmt(n){ return Math.round(n).toLocaleString('id-ID'); }

function setRowState(btn, matched) {
    const row = btn.closest('tr');
    const input = row.querySelector('.match-input');
    input.disabled = !matched;
    if (matched) {
        row.classList.add('bg-green-50');
        btn.className = 'match-btn px-2.5 py-1 rounded text-xs font-semibold border transition bg-green-600 text-white border-green-600 hover:bg-green-700';
        btn.textContent = '✓ Sudah Cocok';
    } else {
        row.classList.remove('bg-green-50');
        btn.className = 'match-btn px-2.5 py-1 rounded text-xs font-semibold border transition bg-white text-gray-600 border-gray-300 hover:bg-gray-100';
        btn.textContent = 'Cocokkan';
    }
}

function recalc(){
    let matched = 0, unmatched = 0;
    document.querySelectorAll('.match-btn').forEach(btn => {
        const signed = parseFloat(btn.dataset.debit||0) - parseFloat(btn.dataset.credit||0);
        const isMatched = !btn.closest('tr').querySelector('.match-input').disabled;
        if (isMatched) matched += signed; else unmatched += signed;
    });
    document.getElementById('matchedSum').textContent = fmt(matched);
    document.getElementById('unmatchedSum').textContent = fmt(unmatched);
    const stmt = window.cleanNumber(document.getElementById('statementInput').value) || 0;
    const diff = bookBalance - stmt;
    document.getElementById('diffDisplay').textContent = fmt(diff);
    document.getElementById('diffDisplay').className = 'font-semibold ' + (Math.abs(diff) > 0.01 ? 'text-red-600' : 'text-green-700');
}

document.querySelectorAll('.match-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const isCurrentlyMatched = !btn.closest('tr').querySelector('.match-input').disabled;
        setRowState(btn, !isCurrentlyMatched);
        recalc();
    });
});

document.getElementById('statementInput').addEventListener('input', recalc);
document.getElementById('toggleAll').addEventListener('click', () => {
    const buttons = document.querySelectorAll('.match-btn');
    const anyUnmatched = Array.from(buttons).some(b => b.closest('tr').querySelector('.match-input').disabled);
    buttons.forEach(b => setRowState(b, anyUnmatched));
    recalc();
});

// Cocokkan semua baris yang cocok persis dgn rekening koran (tanggal & nilai sama)
const matchExactBtn = document.getElementById('matchExactBtn');
if (matchExactBtn) {
    matchExactBtn.addEventListener('click', () => {
        document.querySelectorAll('.match-btn[data-koran="exact"]').forEach(b => setRowState(b, true));
        recalc();
    });
}

recalc();

// ============================================================
// Resizable columns — drag handle di kanan tiap th, simpan ke localStorage
// ============================================================
(function setupResizableColumns() {
    const STORAGE_KEY = 'recon_table_col_widths_v1';
    const table = document.getElementById('reconTable');
    if (!table) return;

    const cols = table.querySelectorAll('colgroup col[data-col-key]');
    const ths  = table.querySelectorAll('thead th.resizable');

    // Restore widths
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        cols.forEach(c => {
            const k = c.dataset.colKey;
            if (saved[k]) c.style.width = saved[k];
        });
    } catch(e) {}

    function saveWidths() {
        const out = {};
        cols.forEach(c => { out[c.dataset.colKey] = c.style.width; });
        localStorage.setItem(STORAGE_KEY, JSON.stringify(out));
    }

    ths.forEach((th, idx) => {
        const handle = th.querySelector('.col-resizer');
        if (!handle) return;
        const col = cols[idx];
        if (!col) return;

        handle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const startX = e.clientX;
            const startWidth = th.getBoundingClientRect().width;
            handle.classList.add('active');
            table.classList.add('resizing');

            function onMove(ev) {
                const delta = ev.clientX - startX;
                const newW = Math.max(60, startWidth + delta);
                col.style.width = newW + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                handle.classList.remove('active');
                table.classList.remove('resizing');
                saveWidths();
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Double-click reset to auto width
        handle.addEventListener('dblclick', (e) => {
            e.preventDefault();
            e.stopPropagation();
            col.style.width = '';
            saveWidths();
        });
    });
})();
</script>

{{-- ============ QUICK-ADD MODAL: Pengeluaran / Pemasukan Umum ============ --}}
<div id="quickModal" class="fixed inset-0 bg-black/50 z-[60] hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div id="quickModalHeader" class="flex items-center justify-between px-4 py-3 border-b">
            <h3 id="quickModalTitle" class="text-base font-semibold">Tambah Transaksi</h3>
            <button type="button" onclick="closeQuickModal()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <form id="quickModalForm" onsubmit="submitQuickModal(event)" class="p-4 space-y-3 text-sm">
            <input type="hidden" id="qmKind" value="">
            <div id="qmError" class="hidden bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-xs"></div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
                    <input type="date" id="qmDate" required
                           min="{{ $br->start_date->format('Y-m-d') }}"
                           max="{{ $br->end_date->format('Y-m-d') }}"
                           value="{{ now()->between($br->start_date, $br->end_date) ? now()->format('Y-m-d') : $br->end_date->format('Y-m-d') }}"
                           class="border rounded px-2 h-9 w-full">
                    <div class="text-[10px] text-gray-400 mt-0.5">Wajib di periode {{ $br->start_date->format('d M') }} – {{ $br->end_date->format('d M Y') }}</div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jumlah (Rp) <span class="text-red-500">*</span></label>
                    <input type="text" inputmode="numeric" id="qmAmount" required
                           class="border rounded px-2 h-9 w-full text-right rupiah-input">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1">
                    <span id="qmAccountLabel" class="text-gray-700">Akun Lawan</span> <span class="text-red-500">*</span>
                </label>
                <input type="text" id="qmAccountSearch" list="qmAccountList" required
                       class="border rounded px-2 h-9 w-full" placeholder="Ketik kode/nama akun…">
                <input type="hidden" id="qmAccountId">
                <datalist id="qmAccountList"></datalist>
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">Keterangan</label>
                <input type="text" id="qmDescription" maxlength="255"
                       class="border rounded px-2 h-9 w-full" placeholder="Mis. Biaya admin bank Mei 2026">
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">No. Referensi (opsional)</label>
                <input type="text" id="qmReference" maxlength="255"
                       class="border rounded px-2 h-9 w-full" placeholder="Mis. nomor mutasi rekening">
            </div>

            <div class="bg-gray-50 rounded px-3 py-2 text-xs text-gray-600">
                <div><b>Sumber Kas/Bank:</b> {{ $br->account->code }} — {{ $br->account->name }}</div>
                <div class="mt-0.5">Otomatis dipakai sebagai akun kas. Setelah simpan, transaksi langsung POSTED & muncul di tabel rekonsiliasi.</div>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t">
                <button type="button" onclick="closeQuickModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm">Batal</button>
                <button type="submit" id="qmSubmit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded text-sm font-semibold">Simpan & Post</button>
            </div>
        </form>
    </div>
</div>

<script>
const QM_CASH_ACCOUNT_ID = {{ (int) $br->account_id }};
const QM_BR_EDIT_URL     = @json(route('finance.cash-bank.reconciliations.edit', $br->id));
const QM_QUICK_DISB_URL  = @json(route('finance.cash-bank.disbursements.quick-store'));
const QM_QUICK_RCPT_URL  = @json(route('finance.cash-bank.receipts.quick-store'));
const QM_CSRF            = @json(csrf_token());

const QM_EXPENSE_ACCOUNTS = @json($disbursementAccounts->map(fn($a) => [
    'id' => $a->id, 'label' => $a->code . ' — ' . $a->name,
])->values());
const QM_REVENUE_ACCOUNTS = @json($revenueAccounts->map(fn($a) => [
    'id' => $a->id, 'label' => $a->code . ' — ' . $a->name,
])->values());

let qmAccountByLabel = {};

function openQuickModal(kind) {
    const modal = document.getElementById('quickModal');
    const title = document.getElementById('quickModalTitle');
    const header = document.getElementById('quickModalHeader');
    const accLabel = document.getElementById('qmAccountLabel');
    const dataList = document.getElementById('qmAccountList');
    const submit = document.getElementById('qmSubmit');

    if (kind === 'disbursement') {
        title.textContent = '+ Pengeluaran Umum';
        header.className = 'flex items-center justify-between px-4 py-3 border-b border-red-200 bg-red-50 rounded-t-lg';
        accLabel.textContent = 'Akun Beban / Pengeluaran / Prive';
        accLabel.className = 'text-red-700';
        submit.className = 'bg-red-600 hover:bg-red-700 text-white px-4 py-1.5 rounded text-sm font-semibold';
        populateAccountList(QM_EXPENSE_ACCOUNTS);
    } else {
        title.textContent = '+ Pemasukan Umum';
        header.className = 'flex items-center justify-between px-4 py-3 border-b border-emerald-200 bg-emerald-50 rounded-t-lg';
        accLabel.textContent = 'Akun Pendapatan';
        accLabel.className = 'text-emerald-700';
        submit.className = 'bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-1.5 rounded text-sm font-semibold';
        populateAccountList(QM_REVENUE_ACCOUNTS);
    }

    document.getElementById('qmKind').value = kind;
    document.getElementById('qmError').classList.add('hidden');
    document.getElementById('qmAmount').value = '';
    document.getElementById('qmAccountSearch').value = '';
    document.getElementById('qmAccountId').value = '';
    document.getElementById('qmDescription').value = '';
    document.getElementById('qmReference').value = '';

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('qmDate').focus(), 50);
}

function closeQuickModal() {
    const modal = document.getElementById('quickModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function populateAccountList(accounts) {
    const dataList = document.getElementById('qmAccountList');
    dataList.innerHTML = '';
    qmAccountByLabel = {};
    accounts.forEach(a => {
        const opt = document.createElement('option');
        opt.value = a.label;
        opt.dataset.id = a.id;
        dataList.appendChild(opt);
        qmAccountByLabel[a.label] = a.id;
    });
}

document.getElementById('qmAccountSearch').addEventListener('input', (e) => {
    document.getElementById('qmAccountId').value = qmAccountByLabel[e.target.value] || '';
});

// Close on backdrop click & ESC
document.getElementById('quickModal').addEventListener('click', (e) => {
    if (e.target.id === 'quickModal') closeQuickModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !document.getElementById('quickModal').classList.contains('hidden')) {
        closeQuickModal();
    }
});

async function submitQuickModal(e) {
    e.preventDefault();
    const errBox = document.getElementById('qmError');
    errBox.classList.add('hidden');

    const kind   = document.getElementById('qmKind').value;
    const accId  = document.getElementById('qmAccountId').value;
    if (!accId) {
        errBox.textContent = 'Akun harus dipilih dari daftar.';
        errBox.classList.remove('hidden');
        return;
    }

    const submit = document.getElementById('qmSubmit');
    const origText = submit.textContent;
    submit.disabled = true;
    submit.textContent = 'Menyimpan…';

    const fd = new FormData();
    fd.append('_token', QM_CSRF);
    fd.append('date', document.getElementById('qmDate').value);
    fd.append('cash_account_id', QM_CASH_ACCOUNT_ID);
    fd.append('amount', window.cleanNumber(document.getElementById('qmAmount').value));
    fd.append('description', document.getElementById('qmDescription').value);
    fd.append('reference', document.getElementById('qmReference').value);
    fd.append(kind === 'disbursement' ? 'expense_account_id' : 'revenue_account_id', accId);

    const url = kind === 'disbursement' ? QM_QUICK_DISB_URL : QM_QUICK_RCPT_URL;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': QM_CSRF },
            body: fd,
        });
        const j = await res.json();
        if (!res.ok || !j.success) {
            errBox.textContent = j.error || ('HTTP ' + res.status);
            errBox.classList.remove('hidden');
            submit.disabled = false;
            submit.textContent = origText;
            return;
        }
        // Reload page so syncLines pickup transaksi baru
        window.location.href = QM_BR_EDIT_URL + '?_added=' + encodeURIComponent(j.number);
    } catch (err) {
        errBox.textContent = 'Error: ' + err.message;
        errBox.classList.remove('hidden');
        submit.disabled = false;
        submit.textContent = origText;
    }
}
</script>

{{-- ============ QUICK-ADD MODAL: Transfer Antar Bank ============ --}}
<div id="transferModal" class="fixed inset-0 bg-black/50 z-[60] hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between px-4 py-3 border-b border-purple-200 bg-purple-50 rounded-t-lg">
            <h3 class="text-base font-semibold text-purple-800">+ Transfer Antar Bank</h3>
            <button type="button" onclick="closeTransferModal()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <form id="transferModalForm" onsubmit="submitTransferModal(event)" class="p-4 space-y-3 text-sm">
            <div id="tmError" class="hidden bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-xs"></div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
                    <input type="date" id="tmDate" required
                           min="{{ $br->start_date->format('Y-m-d') }}"
                           max="{{ $br->end_date->format('Y-m-d') }}"
                           value="{{ now()->between($br->start_date, $br->end_date) ? now()->format('Y-m-d') : $br->end_date->format('Y-m-d') }}"
                           class="border rounded px-2 h-9 w-full">
                    <div class="text-[10px] text-gray-400 mt-0.5">Wajib di periode {{ $br->start_date->format('d M') }} – {{ $br->end_date->format('d M Y') }}</div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jumlah (Rp) <span class="text-red-500">*</span></label>
                    <input type="text" inputmode="numeric" id="tmAmount" required
                           class="border rounded px-2 h-9 w-full text-right rupiah-input">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Arah Transfer <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 border rounded px-2 h-9 cursor-pointer has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50">
                        <input type="radio" name="tmDirection" value="out" checked class="text-purple-600">
                        <span class="text-xs">Keluar dari rekening ini</span>
                    </label>
                    <label class="flex items-center gap-2 border rounded px-2 h-9 cursor-pointer has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50">
                        <input type="radio" name="tmDirection" value="in" class="text-purple-600">
                        <span class="text-xs">Masuk ke rekening ini</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                    <span id="tmCounterLabel">Rekening Tujuan</span> <span class="text-red-500">*</span>
                </label>
                <input type="text" id="tmCounterSearch" list="tmCounterList" required
                       class="border rounded px-2 h-9 w-full" placeholder="Ketik kode/nama rekening kas/bank…">
                <input type="hidden" id="tmCounterId">
                <datalist id="tmCounterList"></datalist>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Biaya Admin (Rp)</label>
                    <input type="text" inputmode="numeric" id="tmAdminFee"
                           class="border rounded px-2 h-9 w-full text-right rupiah-input" placeholder="0">
                    <div class="text-[10px] text-gray-400 mt-0.5">Ditanggung rekening sumber.</div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Akun Beban Admin</label>
                    <input type="text" id="tmAdminFeeSearch" list="tmAdminFeeList"
                           class="border rounded px-2 h-9 w-full" placeholder="Wajib jika ada biaya admin">
                    <input type="hidden" id="tmAdminFeeId" value="{{ $defaultAdminFeeAccountId }}">
                    <datalist id="tmAdminFeeList"></datalist>
                </div>
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">No. Referensi / Keterangan (opsional)</label>
                <input type="text" id="tmReference" maxlength="255"
                       class="border rounded px-2 h-9 w-full" placeholder="Mis. nomor mutasi rekening">
            </div>

            <div class="bg-gray-50 rounded px-3 py-2 text-xs text-gray-600">
                <div><b>Rekening ini:</b> {{ $br->account->code }} — {{ $br->account->name }}</div>
                <div class="mt-0.5">Setelah simpan, transfer langsung POSTED & muncul di tabel rekonsiliasi.</div>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t">
                <button type="button" onclick="closeTransferModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm">Batal</button>
                <button type="submit" id="tmSubmit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-1.5 rounded text-sm font-semibold">Simpan & Post</button>
            </div>
        </form>
    </div>
</div>

<script>
const TM_RECON_ACCOUNT_ID = {{ (int) $br->account_id }};
const TM_QUICK_URL        = @json(route('finance.cash-bank.reconciliations.quick-transfer'));

const TM_CASH_ACCOUNTS = @json($transferAccounts->map(fn($a) => [
    'id' => $a->id, 'label' => $a->code . ' — ' . $a->name,
])->values());
const TM_EXPENSE_ACCOUNTS = @json($expenseAccounts->map(fn($a) => [
    'id' => $a->id, 'label' => $a->code . ' — ' . $a->name,
])->values());

let tmCounterByLabel = {}, tmAdminByLabel = {};

function tmPopulate(listId, accounts, map) {
    const dl = document.getElementById(listId);
    dl.innerHTML = '';
    Object.keys(map).forEach(k => delete map[k]);
    accounts.forEach(a => {
        const opt = document.createElement('option');
        opt.value = a.label;
        dl.appendChild(opt);
        map[a.label] = a.id;
    });
}

function openTransferModal() {
    const modal = document.getElementById('transferModal');
    tmPopulate('tmCounterList', TM_CASH_ACCOUNTS, tmCounterByLabel);
    tmPopulate('tmAdminFeeList', TM_EXPENSE_ACCOUNTS, tmAdminByLabel);

    document.getElementById('tmError').classList.add('hidden');
    document.getElementById('tmAmount').value = '';
    document.getElementById('tmCounterSearch').value = '';
    document.getElementById('tmCounterId').value = '';
    document.getElementById('tmAdminFee').value = '';
    document.getElementById('tmReference').value = '';
    document.querySelector('input[name="tmDirection"][value="out"]').checked = true;
    updateCounterLabel();

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('tmDate').focus(), 50);
}

function closeTransferModal() {
    const modal = document.getElementById('transferModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function updateCounterLabel() {
    const dir = document.querySelector('input[name="tmDirection"]:checked').value;
    document.getElementById('tmCounterLabel').textContent = dir === 'out' ? 'Rekening Tujuan' : 'Rekening Sumber';
}

document.querySelectorAll('input[name="tmDirection"]').forEach(r =>
    r.addEventListener('change', updateCounterLabel));

document.getElementById('tmCounterSearch').addEventListener('input', (e) => {
    document.getElementById('tmCounterId').value = tmCounterByLabel[e.target.value] || '';
});
document.getElementById('tmAdminFeeSearch').addEventListener('input', (e) => {
    document.getElementById('tmAdminFeeId').value = tmAdminByLabel[e.target.value] || '';
});

document.getElementById('transferModal').addEventListener('click', (e) => {
    if (e.target.id === 'transferModal') closeTransferModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !document.getElementById('transferModal').classList.contains('hidden')) {
        closeTransferModal();
    }
});

async function submitTransferModal(e) {
    e.preventDefault();
    const errBox = document.getElementById('tmError');
    errBox.classList.add('hidden');

    const counterId = document.getElementById('tmCounterId').value;
    if (!counterId) {
        errBox.textContent = 'Rekening lawan harus dipilih dari daftar.';
        errBox.classList.remove('hidden');
        return;
    }
    const adminFee = window.cleanNumber(document.getElementById('tmAdminFee').value) || 0;
    const adminFeeId = document.getElementById('tmAdminFeeId').value;
    if (adminFee > 0 && !adminFeeId) {
        errBox.textContent = 'Akun beban admin wajib dipilih saat ada biaya admin.';
        errBox.classList.remove('hidden');
        return;
    }

    const submit = document.getElementById('tmSubmit');
    const origText = submit.textContent;
    submit.disabled = true;
    submit.textContent = 'Menyimpan…';

    const fd = new FormData();
    fd.append('_token', QM_CSRF);
    fd.append('date', document.getElementById('tmDate').value);
    fd.append('reconciliation_account_id', TM_RECON_ACCOUNT_ID);
    fd.append('direction', document.querySelector('input[name="tmDirection"]:checked').value);
    fd.append('counterparty_account_id', counterId);
    fd.append('amount', window.cleanNumber(document.getElementById('tmAmount').value));
    fd.append('admin_fee', adminFee);
    if (adminFeeId) fd.append('admin_fee_account_id', adminFeeId);
    fd.append('reference', document.getElementById('tmReference').value);
    fd.append('notes', document.getElementById('tmReference').value);

    try {
        const res = await fetch(TM_QUICK_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': QM_CSRF },
            body: fd,
        });
        const j = await res.json();
        if (!res.ok || !j.success) {
            errBox.textContent = j.error || ('HTTP ' + res.status);
            errBox.classList.remove('hidden');
            submit.disabled = false;
            submit.textContent = origText;
            return;
        }
        window.location.href = QM_BR_EDIT_URL + '?_added=' + encodeURIComponent(j.number);
    } catch (err) {
        errBox.textContent = 'Error: ' + err.message;
        errBox.classList.remove('hidden');
        submit.disabled = false;
        submit.textContent = origText;
    }
}
</script>

{{-- ============ MODAL: Upload Rekening Koran (Excel) ============ --}}
<div id="statementModal" class="fixed inset-0 bg-black/50 z-[60] hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-4 py-3 border-b border-blue-200 bg-blue-50 rounded-t-lg">
            <h3 class="text-base font-semibold text-blue-800">⬆ Upload Rekening Koran (Excel)</h3>
            <button type="button" onclick="closeStatementModal()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>

        <div class="p-4 space-y-4 text-sm">
            @if(($statementMatch['total'] ?? 0) > 0)
                <div class="bg-green-50 border border-green-200 rounded px-3 py-2 text-green-700 flex items-center justify-between">
                    <span>Sudah ada <b>{{ $statementMatch['total'] }}</b> baris koran ter-upload.</span>
                    <form method="POST" action="{{ route('finance.cash-bank.reconciliations.statement-clear', $br->id) }}"
                          onsubmit="return confirm('Hapus semua data rekening koran yang ter-upload?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-rose-600 hover:underline text-xs font-semibold">Hapus data koran</button>
                    </form>
                </div>
            @endif

            {{-- 1. Konversi PDF → Excel via AI --}}
            <div>
                <div class="font-semibold text-gray-700 mb-1">1. Ubah PDF rekening koran → Excel pakai AI</div>
                <p class="text-xs text-gray-500 mb-1">Salin prompt ini, tempel di AI (mis. ChatGPT/Gemini/Claude) bersama file PDF rekening koran, lalu simpan hasilnya sebagai Excel.</p>
                <textarea id="aiPromptText" rows="7" readonly
                          class="border rounded px-2 py-1.5 w-full text-xs font-mono bg-gray-50 leading-relaxed">Ubah rekening koran / mutasi rekening dalam file terlampir menjadi tabel Excel dengan TEPAT 4 kolom dan header berikut di baris pertama:
Tanggal | Keterangan | Uang Masuk | Uang Keluar

Aturan:
- Tanggal: format YYYY-MM-DD (contoh 2026-06-08).
- Keterangan: uraian transaksi apa adanya.
- Uang Masuk: nominal dana yang MASUK ke rekening (angka polos tanpa titik/koma ribuan, tanpa "Rp"). Kosongkan jika bukan transaksi masuk.
- Uang Keluar: nominal dana yang KELUAR dari rekening (angka polos). Kosongkan jika bukan transaksi keluar.
- Satu baris untuk satu mutasi. Jangan sertakan baris saldo, subtotal, atau ringkasan.
- Jangan tambah kolom lain. Keluarkan sebagai file Excel (.xlsx) siap unduh.</textarea>
                <button type="button" onclick="copyAiPrompt(this)"
                        class="mt-1 bg-gray-200 hover:bg-gray-300 text-gray-700 px-2.5 py-1 rounded text-xs font-semibold">📋 Salin Prompt</button>
            </div>

            {{-- 2. Upload --}}
            <div>
                <div class="font-semibold text-gray-700 mb-1">2. Upload file Excel</div>
                <div class="text-xs text-gray-500 mb-2">
                    Kolom wajib urut: <b>Tanggal | Keterangan | Uang Masuk | Uang Keluar</b>.
                    <a href="{{ route('finance.cash-bank.reconciliations.statement-template') }}" class="text-blue-600 hover:underline">Download template</a>
                </div>
                <form method="POST" action="{{ route('finance.cash-bank.reconciliations.statement-import', $br->id) }}"
                      enctype="multipart/form-data" class="flex items-center gap-2">
                    @csrf
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                           class="border rounded px-2 py-1.5 w-full text-xs">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded text-sm font-semibold whitespace-nowrap">Upload</button>
                </form>
                <p class="text-[11px] text-gray-400 mt-1">Upload baru akan menggantikan data koran sebelumnya. Cocok/tidaknya dihitung otomatis berdasarkan tanggal &amp; nilai.</p>
            </div>
        </div>

        <div class="flex justify-end gap-2 px-4 py-3 border-t">
            <button type="button" onclick="closeStatementModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm">Tutup</button>
        </div>
    </div>
</div>

<script>
function openStatementModal() {
    const m = document.getElementById('statementModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeStatementModal() {
    const m = document.getElementById('statementModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
function copyAiPrompt(btn) {
    const ta = document.getElementById('aiPromptText');
    ta.select();
    navigator.clipboard.writeText(ta.value).then(() => {
        const t = btn.textContent; btn.textContent = '✓ Tersalin';
        setTimeout(() => btn.textContent = t, 1500);
    }).catch(() => { document.execCommand('copy'); });
}
document.getElementById('statementModal').addEventListener('click', (e) => {
    if (e.target.id === 'statementModal') closeStatementModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !document.getElementById('statementModal').classList.contains('hidden')) {
        closeStatementModal();
    }
});
</script>
@endsection
