{{-- Badge umur dokumen sejak tanggal dibuat. Param: $date (Carbon/string).
     Warna menonjol untuk dokumen lama (mudah spot SO/Invoice/Penawaran yang lama tak diproses). --}}
@php
    $__show = $show ?? true; // hanya tampil untuk dokumen yang belum diproses
    $__d = ($__show && $date) ? \Carbon\Carbon::parse($date)->startOfDay() : null;
@endphp
@if($__d)
    @php
        $__days = (int) $__d->diffInDays(\Carbon\Carbon::today());
        if ($__days <= 0)      { $__label = 'Hari ini'; }
        elseif ($__days < 7)   { $__label = $__days . ' hari lalu'; }
        elseif ($__days < 30)  { $__label = intdiv($__days, 7) . ' minggu lalu'; }
        elseif ($__days < 365) { $__label = intdiv($__days, 30) . ' bulan lalu'; }
        else                   { $__label = intdiv($__days, 365) . ' tahun lalu'; }
        $__cls = $__days >= 30 ? 'bg-red-100 text-red-700'
               : ($__days >= 7 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500');
    @endphp
    <span class="ml-1.5 inline-block text-[10px] px-1.5 py-0.5 rounded font-semibold {{ $__cls }} align-middle" title="Dibuat {{ $__d->format('d M Y') }} · umur {{ $__days }} hari">⏳ {{ $__label }}</span>
@endif
