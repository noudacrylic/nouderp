{{-- Tombol Intercom di pojok kanan atas (di samping lonceng). Isi panelnya
     dirender oleh public/js/intercom.js — lihat _intercom_mesin.blade.php. --}}
@if (config('intercom.enabled'))
<div class="ic-wadah">
    <button type="button" class="ic-tombol" title="Intercom">
        <span>📞</span><span class="ic-titik abu"></span>
    </button>
    <div class="ic-panel">
        <div class="ic-kepala">
            <span>Intercom Divisi</span>
            <a href="{{ route('intercom.index') }}">Layar stasiun ›</a>
        </div>
        <div data-ic-isi="kecil"></div>
    </div>
</div>
@endif
