@php
    $isAbsensi       = request()->routeIs('sdm.absensi.*') || request()->routeIs('sdm.attendance.*');
    $isPeriodeSetting = request()->routeIs('sdm.periode-gaji.settings');
    $isSlipGaji      = (request()->routeIs('sdm.periode-gaji.*') && ! $isPeriodeSetting) || request()->routeIs('sdm.slip-gaji.*');
    $isIzin         = request()->routeIs('sdm.izin.*');
    $isSp           = request()->routeIs('sdm.sp.*');
    $isPengajuanIzin = request()->routeIs('sdm.pengajuan-izin.*');
    $isIzinSanksi   = $isIzin || $isSp || $isPengajuanIzin;
    $isKebijakan    = request()->routeIs('sdm.kebijakan.*') && !request()->routeIs('sdm.kebijakan.pengaturan.*');
    $isPengaturan   = request()->routeIs('sdm.kebijakan.pengaturan.*');
    $isLibur        = request()->routeIs('sdm.libur.*');
    $isKasbon       = request()->routeIs('sdm.kasbon.*') || request()->routeIs('sdm.kasbon-pembayaran.*');
@endphp

<div class="bg-white rounded shadow mb-3 border-b flex text-[13px] overflow-x-auto">
    <a href="{{ route('sdm.absensi.index') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isAbsensi ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Daftar Absensi
    </a>
    <a href="{{ route('sdm.izin.index') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isIzinSanksi ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Izin &amp; Sanksi
    </a>
    <a href="{{ route('sdm.kasbon.index') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isKasbon ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Kasbon
    </a>
    <a href="{{ route('sdm.kebijakan.index') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isKebijakan ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Kebijakan
    </a>
    <a href="{{ route('sdm.libur.index') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isLibur ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Hari Libur Nasional
    </a>
    <a href="{{ route('sdm.kebijakan.pengaturan.edit') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isPengaturan ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Pengaturan Akun &amp; Rate
    </a>
    <a href="{{ route('sdm.periode-gaji.index') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isSlipGaji ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Slip Gaji
    </a>
    <a href="{{ route('sdm.periode-gaji.settings') }}"
       class="px-3 py-2 whitespace-nowrap {{ $isPeriodeSetting ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
        Periode
    </a>
</div>

@if($isIzinSanksi)
    <div class="bg-white rounded shadow mb-3 border-b flex text-[13px] overflow-x-auto">
        <a href="{{ route('sdm.izin.index') }}"
           class="px-3 py-2 whitespace-nowrap {{ $isIzin ? 'border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
            Izin
        </a>
        <a href="{{ route('sdm.sp.index') }}"
           class="px-3 py-2 whitespace-nowrap {{ $isSp ? 'border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
            Surat Peringatan
        </a>
        <a href="{{ route('sdm.pengajuan-izin.index') }}"
           class="px-3 py-2 whitespace-nowrap {{ $isPengajuanIzin ? 'border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
            Pengajuan Izin
            @php $pendingIzin = \App\Modules\SDM\Models\IzinRequest::where('status', 'pending')->count(); @endphp
            @if($pendingIzin > 0)
                <span class="ml-1 text-[10px] bg-amber-500 text-white px-1.5 py-0.5 rounded-full">{{ $pendingIzin }}</span>
            @endif
        </a>
    </div>
@endif

@if($isKasbon)
    @php
        $isKasbonMain = request()->routeIs('sdm.kasbon.*');
        $isKasbonBayar = request()->routeIs('sdm.kasbon-pembayaran.*');
    @endphp
    <div class="bg-white rounded shadow mb-3 border-b flex text-[13px] overflow-x-auto">
        <a href="{{ route('sdm.kasbon.index') }}"
           class="px-3 py-2 whitespace-nowrap {{ $isKasbonMain ? 'border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
            Pengajuan Kasbon
        </a>
        <a href="{{ route('sdm.kasbon-pembayaran.index') }}"
           class="px-3 py-2 whitespace-nowrap {{ $isKasbonBayar ? 'border-b-2 border-emerald-600 text-emerald-700 font-semibold' : 'text-gray-600 hover:text-gray-900' }}">
            Pelunasan Manual
        </a>
    </div>
@endif
