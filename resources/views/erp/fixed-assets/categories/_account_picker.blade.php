{{--
    Komponen live-search akun.
    Param: $name, $label, $required (bool), $value (id), $displayValue (label), $searchTypes (csv), $helper
--}}
<div>
    <label class="block text-xs text-gray-500 mb-1">
        {{ $label }} @if($required ?? false)<span class="text-red-500">*</span>@endif
    </label>
    <div class="acc-pick" data-types="{{ $searchTypes ?? '' }}">
        <input type="text"
               class="acc-pick-input w-full border rounded px-2 py-1.5 pr-7"
               placeholder="Ketik kode / nama akun..."
               autocomplete="off"
               value="{{ $displayValue ?? '' }}"
               @if($required ?? false) required @endif>
        <input type="hidden" name="{{ $name }}" class="acc-pick-id" value="{{ $value ?? '' }}">
        <span class="acc-pick-clear" title="Clear">×</span>
        <div class="acc-pick-dropdown"></div>
    </div>
    @if(!empty($helper))
        <p class="text-xs text-gray-400 mt-1">{{ $helper }}</p>
    @endif
</div>
