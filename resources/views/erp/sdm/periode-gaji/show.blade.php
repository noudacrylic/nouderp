@extends('layouts.erp')

@section('content')
@php
    $colors = [
        'draft'     => 'bg-gray-100 text-gray-600',
        'imported'  => 'bg-blue-100 text-blue-700',
        'finalized' => 'bg-green-100 text-green-700',
        'void'      => 'bg-red-100 text-red-700',
    ];
    $totalGaji = $slips->sum('total_gaji');
    $paidSet   = collect($paidKaryawanIds ?? [])->flip();
    $bulanNames = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $currentYear = (int) $periode->tahun;
    $yearOptions = range(max($currentYear - 3, 2020), $currentYear + 1);
@endphp

<div class="flex items-center justify-between mb-4">
    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
        <button type="button" @click="open = !open"
                class="flex items-start gap-2 text-left rounded px-2 py-1 -mx-2 -my-1 hover:bg-gray-100 focus:bg-gray-100 focus:outline-none">
            <div>
                <h1 class="text-lg font-semibold flex items-center gap-1.5">
                    Periode {{ $periode->label }}
                    <span class="text-gray-400 font-mono text-sm">({{ $periode->code }})</span>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </h1>
                <div class="text-xs text-gray-500 mt-1">
                    {{ $periode->start_date->format('d M Y') }} – {{ $periode->end_date->format('d M Y') }}
                    <span class="ml-2 px-2 py-0.5 rounded {{ $colors[$periode->status] ?? '' }}">{{ $periode->status }}</span>
                </div>
            </div>
        </button>

        <div x-show="open" x-cloak x-transition.opacity
             class="absolute left-0 top-full mt-1 z-30 bg-white border rounded-lg shadow-lg w-80">
            <form method="POST" action="{{ route('sdm.periode-gaji.open-or-create') }}" class="p-3 border-b">
                @csrf
                <div class="text-[11px] uppercase tracking-wider text-gray-400 mb-2">Buka / Buat Periode</div>
                <div class="flex gap-2">
                    <select name="bulan" class="border rounded px-2 py-1.5 text-sm flex-1">
                        @foreach($bulanNames as $num => $name)
                            <option value="{{ $num }}" {{ $num === (int) $periode->bulan ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    <select name="tahun" class="border rounded px-2 py-1.5 text-sm w-24">
                        @foreach($yearOptions as $y)
                            <option value="{{ $y }}" {{ $y === $currentYear ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded text-sm font-semibold">Buka</button>
                </div>
            </form>
            @if($allPeriodes->count())
            <div class="max-h-72 overflow-y-auto py-1">
                <div class="px-3 py-1 text-[11px] uppercase tracking-wider text-gray-400">Periode Tersimpan</div>
                @foreach($allPeriodes as $p)
                    <a href="{{ route('sdm.periode-gaji.show', $p->id) }}"
                       class="flex items-center justify-between px-3 py-1.5 text-sm hover:bg-gray-50 {{ $p->id === $periode->id ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-700' }}">
                        <span>{{ $p->label }} <span class="text-gray-400 font-mono text-xs">({{ $p->code }})</span></span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded {{ $colors[$p->status] ?? '' }}">{{ $p->status }}</span>
                    </a>
                @endforeach
            </div>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2">
        @if($slips->count())
            <a href="{{ route('sdm.attendance.index', $periode->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700 hover:bg-gray-50">Rincian Kehadiran</a>
            <a href="{{ route('sdm.periode-gaji.print-all', $periode->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700 hover:bg-gray-50">Cetak Semua</a>
            <a href="{{ route('sdm.periode-gaji.print-all', ['id' => $periode->id, 'pdf' => 1]) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700 hover:bg-gray-50">PDF Semua</a>
        @endif
        @if($periode->canBeFinalized() && $periode->status !== 'finalized')
            <form method="POST" action="{{ route('sdm.periode-gaji.finalize', $periode->id) }}" onsubmit="return confirm('Finalisasi periode? Setelah ini slip tidak bisa diedit.')">
                @csrf
                <button class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded text-sm font-semibold">Finalisasi</button>
            </form>
        @endif
    </div>
</div>


<div x-data="periodeShow()" x-init="init()">

{{-- Filter & bulk action bar --}}
<div class="bg-white rounded shadow p-3 mb-3 flex flex-wrap items-center gap-3">
    <div class="flex-1 min-w-[200px]">
        <input type="text" x-model="search" @input="applyFilter()"
               placeholder="Cari nama / staf code / divisi..."
               class="border rounded px-3 py-1.5 w-full text-sm">
    </div>
    <div class="text-xs text-gray-500">
        <span x-text="selectedIds.length"></span> dari <span x-text="visibleCount"></span> terpilih
    </div>
    <div class="flex items-center gap-1.5">
        <button type="button" @click="bulkPrint()" :disabled="selectedIds.length === 0"
                class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded text-xs disabled:opacity-40 disabled:cursor-not-allowed">
            🖨 Cetak Terpilih
        </button>
        <button type="button" @click="bulkPdf()" :disabled="selectedIds.length === 0"
                class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded text-xs disabled:opacity-40 disabled:cursor-not-allowed">
            📄 PDF Terpilih
        </button>
        <button type="button" @click="openBulkBayar()" :disabled="selectedIds.length === 0"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded text-xs font-semibold disabled:opacity-40 disabled:cursor-not-allowed">
            💰 Bayar Terpilih
        </button>
    </div>
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-2 py-2 w-10 text-center">
                    <input type="checkbox" @change="toggleAll($event.target.checked)" :checked="allVisibleSelected()" :indeterminate.prop="someSelected()">
                </th>
                <th class="px-3 py-2 text-left">Staf Code</th>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-left">Divisi</th>
                <th class="px-3 py-2 text-center w-16">Hadir</th>
                <th class="px-3 py-2 text-center w-16">½ Hari</th>
                <th class="px-3 py-2 text-center w-16">Lembur (jam)</th>
                <th class="px-3 py-2 text-right w-32">Subtotal</th>
                <th class="px-3 py-2 text-right w-36">Total Gaji</th>
                <th class="px-3 py-2 text-center w-20">Status</th>
                <th class="px-3 py-2 text-right w-56">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($slips as $s)
                @php
                    $isPaid    = $paidSet->has($s->karyawan_id);
                    $searchKey = strtolower(($s->karyawan->staf_code ?? '') . ' ' . ($s->karyawan->name ?? '') . ' ' . ($s->karyawan->department->name ?? ''));
                @endphp
                <tr class="border-b hover:bg-blue-50 slip-row cursor-pointer" data-search="{{ $searchKey }}" data-id="{{ $s->id }}"
                    data-detail-url="{{ route('sdm.slip-gaji.show', $s->id) }}"
                    @click="rowClick($event, {{ $s->id }})">
                    <td class="px-2 py-2 text-center" @click.stop>
                        <input type="checkbox" class="row-check" value="{{ $s->id }}" @change="toggleOne({{ $s->id }}, $event.target.checked)">
                    </td>
                    <td class="px-3 py-2 font-mono">{{ $s->karyawan->staf_code ?? '-' }}</td>
                    <td class="px-3 py-2 font-medium">{{ $s->karyawan->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $s->karyawan->department->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-center">{{ $s->hari_hadir_penuh }}</td>
                    <td class="px-3 py-2 text-center">{{ $s->hari_setengah }}</td>
                    <td class="px-3 py-2 text-center">{{ rtrim(rtrim(number_format($s->total_jam_lembur, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="px-3 py-2 text-right">{{ rupiah($s->subtotal) }}</td>
                    <td class="px-3 py-2 text-right font-bold">{{ rupiah($s->total_gaji) }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="text-xs px-2 py-0.5 rounded {{ $s->status === 'finalized' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ $s->status }}</span>
                        @if($isPaid)<span class="text-[10px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded ml-1">dibayar</span>@endif
                    </td>
                    <td class="px-3 py-2 text-right" @click.stop>
                        <div class="inline-flex items-center gap-1">
                            <a href="{{ route('sdm.slip-gaji.print', $s->id) }}" title="Cetak" class="text-xs text-gray-600 hover:underline">Cetak</a>
                            <span class="text-gray-300">|</span>
                            <a href="{{ route('sdm.slip-gaji.print', ['id' => $s->id, 'pdf' => 1]) }}" title="Unduh PDF" class="text-xs text-gray-600 hover:underline">PDF</a>
                            @if(! $isPaid)
                                <span class="text-gray-300">|</span>
                                <a href="{{ route('finance.cash-bank.salary-payments.create', ['karyawan_id' => $s->karyawan_id, 'periode_bulan' => $periode->bulan, 'periode_tahun' => $periode->tahun]) }}"
                                   title="Bayar" class="text-xs text-emerald-600 font-semibold hover:underline">Bayar</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-3 py-6 text-center text-gray-400">Belum ada slip karyawan aktif untuk periode ini.</td></tr>
            @endforelse
        </tbody>
        @if($slips->count())
        <tfoot class="bg-gray-50 border-t font-bold">
            <tr>
                <td colspan="8" class="px-3 py-2 text-right uppercase tracking-widest text-[10px]">Total</td>
                <td class="px-3 py-2 text-right">{{ rupiah($totalGaji) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

{{-- Modal Bulk Bayar --}}
<div x-show="bulkBayarOpen" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" @click.self="bulkBayarOpen = false" @keydown.escape.window="bulkBayarOpen = false">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <form method="POST" action="{{ route('finance.cash-bank.salary-payments.bulk-from-slips') }}">
            @csrf
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="slip_ids[]" :value="id">
            </template>

            <div class="flex items-center justify-between px-5 py-3 border-b">
                <h3 class="text-base font-semibold">Bayar Gaji Terpilih</h3>
                <button type="button" @click="bulkBayarOpen = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
            </div>

            <div class="px-5 py-4 space-y-3">
                <p class="text-xs text-gray-600">
                    Akan dibuat <strong x-text="selectedIds.length"></strong> draft Pembayaran Gaji untuk periode
                    <strong>{{ $periode->label }}</strong>. Karyawan yang sudah punya pembayaran aktif akan dilewati otomatis.
                </p>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tanggal Pembayaran</label>
                    <input type="date" name="date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2 w-full text-sm">
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Akun Kas/Bank <span class="text-red-500">*</span></label>
                    <select name="cash_account_id" required class="border rounded px-3 py-2 w-full text-sm">
                        <option value="">— Pilih akun —</option>
                        @foreach($cashAccounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->code }} — {{ $acc->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 px-5 py-3 border-t bg-gray-50">
                <button type="button" @click="bulkBayarOpen = false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Batal</button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm font-semibold">Buat Draft Pembayaran</button>
            </div>
        </form>
    </div>
</div>

</div> {{-- /x-data --}}

<div class="mt-5 flex justify-end items-center gap-3">
    @if($periode->canBeVoided())
        <form method="POST" action="{{ route('sdm.periode-gaji.void', $periode->id) }}"
              onsubmit="return confirm('Void periode ini? Slip-slip yang ada tetap tersimpan.')"
              class="flex items-center gap-2">
            @csrf
            <input type="text" name="void_reason" placeholder="Alasan void (opsional)" class="border rounded px-3 py-1.5 text-sm w-64">
            <button class="border border-red-200 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded text-sm">Void Periode</button>
        </form>
    @endif
</div>

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak] { display: none !important; }</style>
<script>
function periodeShow() {
    const PRINT_ALL = "{{ route('sdm.periode-gaji.print-all', $periode->id) }}";

    return {
        search: '',
        selectedIds: [],
        visibleCount: 0,
        bulkBayarOpen: false,

        init() {
            this.applyFilter();
        },

        applyFilter() {
            const q = (this.search || '').toLowerCase().trim();
            let visible = 0;
            document.querySelectorAll('.slip-row').forEach(row => {
                const match = !q || row.dataset.search.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
                // Kalau row di-hide, lepas dari selection
                if (!match) {
                    const id = parseInt(row.dataset.id);
                    const idx = this.selectedIds.indexOf(id);
                    if (idx >= 0) {
                        this.selectedIds.splice(idx, 1);
                        const cb = row.querySelector('.row-check');
                        if (cb) cb.checked = false;
                    }
                }
            });
            this.visibleCount = visible;
        },

        toggleAll(checked) {
            this.selectedIds = [];
            document.querySelectorAll('.slip-row').forEach(row => {
                if (row.style.display === 'none') return;
                const id = parseInt(row.dataset.id);
                const cb = row.querySelector('.row-check');
                if (cb) cb.checked = checked;
                if (checked) this.selectedIds.push(id);
            });
        },

        toggleOne(id, checked) {
            const idx = this.selectedIds.indexOf(id);
            if (checked && idx < 0) this.selectedIds.push(id);
            else if (!checked && idx >= 0) this.selectedIds.splice(idx, 1);
        },

        allVisibleSelected() {
            return this.visibleCount > 0 && this.selectedIds.length === this.visibleCount;
        },
        someSelected() {
            return this.selectedIds.length > 0 && this.selectedIds.length < this.visibleCount;
        },

        bulkPrint() {
            if (!this.selectedIds.length) return;
            const url = PRINT_ALL + '?ids=' + this.selectedIds.join(',');
            window.location.href = url;
        },
        bulkPdf() {
            if (!this.selectedIds.length) return;
            const url = PRINT_ALL + '?pdf=1&ids=' + this.selectedIds.join(',');
            window.location.href = url;
        },
        openBulkBayar() {
            if (!this.selectedIds.length) return;
            this.bulkBayarOpen = true;
        },

        rowClick(event, id) {
            const row = event.currentTarget;
            const url = row.dataset.detailUrl;
            if (!url) return;
            // Buka di tab baru kalau Ctrl/Cmd-click atau klik tengah
            if (event.ctrlKey || event.metaKey || event.button === 1) {
                window.open(url, '_blank');
            } else {
                window.location.href = url;
            }
        },
    };
}
</script>
@endpush
@endsection
