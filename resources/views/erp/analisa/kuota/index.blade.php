@extends('layouts.erp')

@php
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp ' . number_format($v, 0, ',', '.');

    $slots = $data['slots'];
    $tot   = $data['totals'];
    $cost  = $data['cost'];
@endphp

@section('content')
<div class="w-full px-6 py-4">

    @include('erp.analisa._hitung-ulang')

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Kuota Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Berapa slot-jam yang pabrik punya sebulan, dan berapa yang benar-benar terpakai.
                Satu baris = satu slot: mesin di CNC, orang di Assembling.
            </p>
        </div>

        <form method="GET" class="flex items-end gap-2">
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Pemakaian diamati</label>
                <select name="window_days" onchange="this.form.submit()"
                        class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                    @foreach([14 => '14 hari terakhir', 30 => '30 hari terakhir', 60 => '60 hari terakhir', 90 => '90 hari terakhir'] as $v => $label)
                        <option value="{{ $v }}" @selected((int) $filters['window_days'] === $v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                {{-- Periode ini menentukan biaya & hari kerja/bulan, bukan sisi pemakaian. --}}
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Biaya &amp; hari kerja</label>
                <select name="months" onchange="this.form.submit()"
                        class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                    @foreach([1 => '1 bulan terakhir', 2 => '2 bulan terakhir', 3 => '3 bulan terakhir', 6 => '6 bulan terakhir'] as $v => $label)
                        <option value="{{ $v }}" @selected((int) $filters['months'] === $v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    {{-- Ringkasan --}}
    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm px-5 py-4 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-5">
            <div>
                <div class="text-sm font-bold text-gray-800">
                    {{ $data['window']['from']->translatedFormat('d M') }} – {{ $data['window']['to']->translatedFormat('d M Y') }}
                </div>
                <div class="text-[11px] text-gray-500 mt-0.5">
                    {{ $tot['slot_count'] }} slot · {{ $data['window']['days'] }} hari kerja diamati
                    @if($tot['has_assumption'])
                        · <span class="text-indigo-600 font-semibold">memakai asumsi</span>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap gap-6 text-right">
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Tersedia/bulan</div>
                    <div class="text-lg font-black text-gray-800">{{ $angka($tot['available_month'], 0) }} <span class="text-xs font-semibold text-gray-400">slot-jam</span></div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Terpakai</div>
                    <div class="text-lg font-black text-emerald-600">{{ $angka($tot['used_month'], 0) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Utilisasi</div>
                    <div class="text-lg font-black text-blue-600">{{ $angka($tot['utilization']) }}%</div>
                </div>
                <div class="border-l border-gray-100 pl-6">
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Tarif fixed cost</div>
                    <div class="text-lg font-black text-gray-800">{{ $rp($cost['rate_per_slot_hour']) }}<span class="text-xs font-semibold text-gray-400">/slot-jam</span></div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Tidak terserap</div>
                    <div class="text-lg font-black text-amber-600">{{ $rp($cost['unabsorbed']) }}</div>
                    <div class="text-[10px] text-gray-400">{{ $angka($cost['unabsorbed_percent']) }}% dari {{ $rp($cost['fixed_total']) }}</div>
                </div>
            </div>
        </div>
        <p class="text-[11px] text-gray-400 mt-3 leading-relaxed border-t border-gray-100 pt-3">
            Biaya packing {{ $rp($cost['packing_total']) }} <strong>tidak masuk tarif ini</strong> — kerja membungkus
            mengikuti jumlah paket, bukan lamanya barang dibuat, jadi ia punya sukunya sendiri di HPP.
            Pembagi HPP memakai jam <strong>tersedia</strong>, bukan terpakai — jam yang dibayar tetap dibayar
            walau menganggur, itulah arti fixed cost. Akibatnya biaya tidak terserap habis; sisanya muncul di
            kolom "tidak terserap" apa adanya. <strong>Angka ini analisa, tidak pernah masuk jurnal.</strong>
        </p>
    </div>

    @if(!empty($data['contaminated']))
        <div class="bg-red-50 border border-red-200 text-red-900 rounded-2xl px-5 py-3 mb-4 text-xs leading-relaxed">
            <strong>{{ count($data['contaminated']) }} hari dikeluarkan otomatis karena ada timer yang belum ditutup.</strong>
            Timer yang menggantung membentang sepanjang hari, sehingga slot yang sebenarnya menganggur akan
            terbaca sibuk penuh. Tutup dulu timernya supaya hari-hari ini bisa ikut dihitung:
            <div class="mt-1.5 space-y-0.5">
                @foreach($data['contaminated'] as $c)
                    <div>· <a href="{{ route('analisa.kalender.index', ['date' => $c['tanggal']->toDateString()]) }}"
                              class="font-semibold underline">{{ $c['tanggal']->translatedFormat('l, d M Y') }}</a>
                        — {{ implode(', ', array_unique($c['slots'])) }}</div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Tabel per slot --}}
    <form method="POST" action="{{ route('analisa.kuota.slots.save') }}"
          class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden mb-4">
        @csrf
        <div class="px-5 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-sm font-bold text-gray-800">Kapasitas per Slot</div>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Kolom <strong>terpakai</strong> dan <strong>lubang</strong> untuk diagnosis — melihat satu mesin
                    cuma 70-an persen adalah undangan menyelidiki, bukan sesuatu yang otomatis mengubah HPP.
                    Yang mengubah HPP hanya kolom asumsi jam.
                </p>
            </div>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">
                Simpan Asumsi Jam
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:1080px">
                <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                    <tr>
                        <th class="px-5 py-2 text-left font-bold">Slot</th>
                        <th class="px-3 py-2 text-right font-bold">Jam/hari<br><span class="normal-case font-normal">tersedia</span></th>
                        <th class="px-3 py-2 text-right font-bold">Jam/hari<br><span class="normal-case font-normal">terpakai</span></th>
                        <th class="px-3 py-2 text-right font-bold">Lubang<br><span class="normal-case font-normal">/hari</span></th>
                        <th class="px-3 py-2 text-right font-bold">Utilisasi</th>
                        <th class="px-3 py-2 text-right font-bold bg-indigo-50/60">Jam/hari<br><span class="normal-case font-normal">asumsi</span></th>
                        <th class="px-3 py-2 text-right font-bold bg-indigo-50/60">Hari/bulan<br><span class="normal-case font-normal">asumsi</span></th>
                        <th class="px-3 py-2 text-center font-bold bg-indigo-50/60">Pakai</th>
                        <th class="px-5 py-2 text-right font-bold">Slot-jam<br>/bulan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @php $deptSekarang = null; @endphp
                    @foreach($slots as $s)
                        @if($deptSekarang !== $s['department'])
                            @php $deptSekarang = $s['department']; @endphp
                            <tr class="bg-gray-100"><td colspan="9" class="px-5 py-1.5 text-[11px] font-black text-gray-600 uppercase tracking-wide">{{ $s['department'] }}</td></tr>
                        @endif

                        <tr class="{{ $s['use_assumption'] ? 'bg-indigo-50/40' : '' }} hover:bg-blue-50/30">
                            <td class="px-5 py-2">
                                <div class="font-semibold text-gray-800 flex items-center gap-1.5">
                                    <span>{{ $s['is_machine'] ? '⚙' : '👤' }}</span>
                                    {{ $s['name'] }}
                                    @if($s['is_virtual'])
                                        <span class="text-[10px] font-bold bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">PENGANDAIAN</span>
                                    @endif
                                </div>
                                @if($s['is_virtual'])
                                    <form method="POST" action="{{ route('analisa.kuota.virtual.destroy', $s['virtual_id']) }}"
                                          onsubmit="return confirm('Hapus slot pengandaian ini?');">
                                        @csrf @method('DELETE')
                                        <button class="text-[11px] text-red-600 hover:underline">Hapus</button>
                                    </form>
                                @endif
                            </td>

                            @if($s['is_virtual'])
                                <td colspan="4" class="px-3 py-2 text-center text-[11px] text-gray-400 italic">belum ada — tidak punya riwayat pemakaian</td>
                            @else
                                <td class="px-3 py-2 text-right text-gray-700">{{ $angka($s['hours_per_day_real'], 1) }}</td>
                                <td class="px-3 py-2 text-right text-emerald-700 font-semibold">{{ $angka($s['used_per_day'], 1) }}</td>
                                <td class="px-3 py-2 text-right text-amber-600">{{ $angka($s['gap_per_day'], 1) }}</td>
                                <td class="px-3 py-2 text-right font-bold {{ ($s['utilization'] ?? 100) < 80 ? 'text-amber-600' : 'text-gray-800' }}">
                                    {{ $angka($s['utilization']) }}%
                                </td>
                            @endif

                            <td class="px-3 py-2 text-right bg-indigo-50/40">
                                @if($s['is_virtual'])
                                    <span class="font-semibold text-gray-700">{{ $angka($s['assumed_hours_per_day'], 1) }}</span>
                                @else
                                    <input type="number" step="0.25" min="0" max="24"
                                           name="slot[{{ $s['executor_id'] }}][assumed_hours_per_day]"
                                           value="{{ $s['assumed_hours_per_day'] }}" placeholder="{{ $angka($s['hours_per_day_real'], 1) }}"
                                           class="w-24 border border-gray-200 rounded-lg px-2 py-1 text-sm text-right">
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right bg-indigo-50/40">
                                @if($s['is_virtual'])
                                    <span class="font-semibold text-gray-700">{{ $angka($s['assumed_working_days'] ?? $s['working_days_real'], 1) }}</span>
                                @else
                                    <input type="number" step="0.5" min="0" max="31"
                                           name="slot[{{ $s['executor_id'] }}][assumed_working_days]"
                                           value="{{ $s['assumed_working_days'] }}" placeholder="{{ $angka($s['working_days_real'], 1) }}"
                                           class="w-24 border border-gray-200 rounded-lg px-2 py-1 text-sm text-right">
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center bg-indigo-50/40">
                                @if($s['is_virtual'])
                                    <span class="text-emerald-600 font-bold">✓</span>
                                @else
                                    <input type="hidden" name="slot[{{ $s['executor_id'] }}][use_assumption]" value="0">
                                    <input type="checkbox" name="slot[{{ $s['executor_id'] }}][use_assumption]" value="1"
                                           @checked($s['use_assumption']) class="w-4 h-4">
                                @endif
                            </td>
                            <td class="px-5 py-2 text-right">
                                <span class="font-black text-gray-800">{{ $angka($s['available_month'], 0) }}</span>
                                <div class="text-[10px] text-gray-400">{{ $angka($s['hours_per_day'], 1) }} jam × {{ $angka($s['working_days'], 1) }} hari</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                    <tr class="font-bold text-gray-800">
                        <td class="px-5 py-2.5">TOTAL</td>
                        <td colspan="3"></td>
                        <td class="px-3 py-2.5 text-right">{{ $angka($tot['utilization']) }}%</td>
                        <td colspan="3" class="px-3 py-2.5 text-right text-[11px] font-normal text-gray-500">kapasitas sebulan →</td>
                        <td class="px-5 py-2.5 text-right text-base">{{ $angka($tot['available_month'], 0) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Slot pengandaian --}}
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <div class="text-sm font-bold text-gray-800">Tambah Slot Pengandaian</div>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    "Kalau beli mesin keempat?" atau "kalau tambah satu orang assembling?" — tambahkan di sini
                    tanpa mendaftarkan mesin fiktif ke data produksi yang sesungguhnya.
                    Ingat: menambah <em>operator</em> CNC tidak menambah slot (yang membatasi mesinnya), tapi
                    menambah biaya di Fixed Cost.
                </p>
            </div>
            <form method="POST" action="{{ route('analisa.kuota.virtual.store') }}" class="p-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Divisi</label>
                    <select name="department_id" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Nama</label>
                    <input type="text" name="label" required maxlength="100" placeholder="Mesin 4"
                           class="w-32 border border-gray-200 rounded-xl px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Jam/hari</label>
                    <input type="number" name="assumed_hours_per_day" step="0.25" min="0.25" max="24" value="7" required
                           class="w-24 border border-gray-200 rounded-xl px-3 py-2 text-sm text-right">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Hari/bulan</label>
                    <input type="number" name="assumed_working_days" step="0.5" min="0" max="31" placeholder="ikut nyata"
                           class="w-28 border border-gray-200 rounded-xl px-3 py-2 text-sm text-right">
                </div>
                <button class="border border-indigo-200 text-indigo-700 hover:bg-indigo-50 px-4 py-2 rounded-xl text-sm font-semibold">
                    Tambah
                </button>
            </form>
        </div>

        {{-- Hari yang dikecualikan --}}
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <div class="text-sm font-bold text-gray-800">Hari yang Dikecualikan</div>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Hanya untuk hari yang datanya <strong>rusak</strong> — produksi jalan tapi tidak terekam sama sekali.
                    Hari sepi tetap harus ikut, karena sepi itu kenyataan.
                </p>
            </div>

            <form method="POST" action="{{ route('analisa.kuota.excluded.store') }}" class="px-4 py-3 border-b border-gray-100 bg-gray-50/60 flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Tanggal</label>
                    <input type="date" name="tanggal" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                </div>
                <div class="flex-1 min-w-48">
                    <label class="block text-[11px] font-bold text-gray-500 mb-1">Alasan (wajib)</label>
                    <input type="text" name="reason" required maxlength="255" placeholder="kenapa datanya tidak bisa dipercaya"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                </div>
                <button class="border border-gray-300 text-gray-700 hover:bg-gray-100 px-4 py-2 rounded-xl text-sm font-semibold">Kecualikan</button>
            </form>

            @if(empty($data['excluded']))
                <div class="px-5 py-4 text-xs text-gray-400">Tidak ada hari yang dikecualikan di jendela ini.</div>
            @else
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-50">
                        @foreach($data['excluded'] as $e)
                            <tr>
                                <td class="px-5 py-2 whitespace-nowrap font-semibold text-gray-800">{{ $e['tanggal']->translatedFormat('d M Y') }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ $e['reason'] }}</td>
                                <td class="px-5 py-2 text-right">
                                    <form method="POST" action="{{ route('analisa.kuota.excluded.destroy', $e['id']) }}"
                                          onsubmit="return confirm('Kembalikan tanggal ini ke rata-rata?');">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Batalkan</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="text-[11px] text-gray-400 mt-4 leading-relaxed space-y-1">
        <p>
            Jam/hari tersedia dibaca dari kalender, jadi sudah menghormati tukar hari, cuti, dan tanggal merah —
            itu sebabnya sebuah slot bisa tertulis 6,7 jam alih-alih 7,0.
        </p>
        <p>
            Tarif fixed cost per slot-jam di atas adalah satu-satunya angka yang diambil HPP dari halaman ini.
            Waktu per unit diandaikan di halaman <strong>Waktu Produksi</strong> — satu tuas per halaman.
        </p>
    </div>
</div>
@endsection
