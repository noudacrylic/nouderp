{{--
    Date range filter. Auto-submit ketika berubah.
    Variabel:
      $labelFrom (opsional) — default "Dari"
      $labelTo (opsional) — default "Sampai"
      $fromName (opsional) — default 'date_from'
      $toName (opsional) — default 'date_to'
--}}
@php
    $labelFrom = $labelFrom ?? 'Dari';
    $labelTo   = $labelTo   ?? 'Sampai';
    $fromName  = $fromName  ?? 'date_from';
    $toName    = $toName    ?? 'date_to';
@endphp
<div>
    <label class="block text-xs text-gray-500 mb-1">{{ $labelFrom }}</label>
    <input type="date" name="{{ $fromName }}" value="{{ request($fromName) }}"
           class="filter-auto border rounded px-2 py-1.5 text-sm">
</div>
<div>
    <label class="block text-xs text-gray-500 mb-1">{{ $labelTo }}</label>
    <input type="date" name="{{ $toName }}" value="{{ request($toName) }}"
           class="filter-auto border rounded px-2 py-1.5 text-sm">
</div>
@if(request($fromName) || request($toName))
    <div class="self-end">
        <a href="{{ url()->current() . '?' . http_build_query(collect(request()->all())->except([$fromName, $toName])->toArray()) }}"
           class="text-xs text-gray-500 hover:text-gray-700 underline pb-1.5 inline-block">reset tanggal</a>
    </div>
@endif
