{{-- Mesin Intercom divisi: konfigurasi + skrip + gaya. Dimuat sekali per
     halaman ERP. Logika ada di public/js/intercom.js. --}}
@if (config('intercom.enabled'))
@php
    $icApp = config('reverb.apps.apps.0');
@endphp
<script>
    window.INTERCOM_CFG = {
        key: @json($icApp['key']),
        host: @json($icApp['options']['host'] ?: request()->getHost()),
        port: @json((int) $icApp['options']['port']),
        scheme: @json($icApp['options']['scheme']),
        authUrl: @json(url('/broadcasting/auth')),
        logUrl: @json(route('intercom.log')),
        csrf: @json(csrf_token()),
        stasiun: @json(config('intercom.stasiun')),
        nadaMs: @json((int) config('intercom.nada_sambung_ms')),
        heningDetik: @json((int) config('intercom.putus_hening_detik')),
        deringDetik: @json((int) config('intercom.batas_dering_detik')),
        maksDetik: @json((int) config('intercom.maks_durasi_detik')),
        iceServers: @json(config('intercom.ice_servers')),
    };
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"></script>
<script src="{{ asset('js/intercom.js') }}?v={{ @filemtime(public_path('js/intercom.js')) }}"></script>
<style>
    .ic-wadah { position: relative; margin-left: auto; flex-shrink: 0; }
    .ic-wadah + .lonceng-erp { margin-left: 8px; }
    .ic-tombol {
        position: relative; display: flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; border-radius: 9px; border: 1px solid #e5e7eb;
        background: #fff; cursor: pointer; font-size: 15px; line-height: 1;
    }
    .ic-tombol:hover { background: #f9fafb; }
    .ic-titik {
        position: absolute; bottom: -3px; right: -3px; width: 11px; height: 11px;
        border-radius: 50%; border: 2px solid #fff; background: #9ca3af;
    }
    .ic-titik.hijau { background: #16a34a; }
    .ic-titik.kuning { background: #f59e0b; }
    .ic-titik.merah { background: #dc2626; animation: icDenyut 1s infinite; }
    @keyframes icDenyut { 50% { opacity: .35; } }

    .ic-panel {
        display: none; position: absolute; top: 42px; right: 0; z-index: 60; width: 300px;
        background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,.10); overflow: hidden;
    }
    .ic-wadah.buka .ic-panel { display: block; }
    .ic-kepala {
        display: flex; align-items: center; justify-content: space-between;
        padding: 9px 12px; border-bottom: 1px solid #f3f4f6;
        font-size: 12px; font-weight: 700; color: #1f2937; background: #f9fafb;
    }
    .ic-kepala a { font-size: 11px; font-weight: 500; color: #047857; text-decoration: none; }

    .ic-daftar { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px; }
    .ic-stasiun {
        display: flex; flex-direction: column; align-items: center; gap: 2px;
        padding: 10px 6px; border: 1px solid #e5e7eb; border-radius: 10px;
        background: #fff; cursor: pointer; font-size: 12px; color: #1f2937;
    }
    .ic-stasiun:hover:not([disabled]) { background: #ecfdf5; border-color: #6ee7b7; }
    .ic-stasiun[disabled] { cursor: not-allowed; opacity: .55; }
    .ic-ikon { font-size: 22px; line-height: 1.1; }
    .ic-nama { font-weight: 700; }
    .ic-st { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #6b7280; }
    .ic-st i { width: 7px; height: 7px; border-radius: 50%; background: #9ca3af; display: inline-block; }
    .ic-on .ic-st i { background: #16a34a; }
    .ic-dnd .ic-st i, .ic-stasiun.ic-dnd .ic-st i { background: #f59e0b; }
    .ic-sibuk .ic-st i { background: #dc2626; }

    .ic-daftar-besar { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; padding: 0; }
    .ic-besar { padding: 28px 10px; border-radius: 16px; font-size: 16px; }
    .ic-besar .ic-ikon { font-size: 46px; }
    .ic-besar .ic-st { font-size: 13px; }
    .ic-besar .ic-st i { width: 10px; height: 10px; }

    .ic-bawah { border-top: 1px solid #f3f4f6; padding: 9px 12px; font-size: 12px; color: #374151; display: grid; gap: 6px; }
    .ic-bawah a { color: #047857; font-size: 11px; }
    .ic-bawah label.ic-dnd { display: flex; align-items: center; gap: 6px; cursor: pointer; }
    .ic-bawah small { color: #9ca3af; }
    .ic-warn { color: #b45309; background: #fffbeb; border-radius: 6px; padding: 5px 7px; }
    .ic-aktif {
        border: 1px solid #10b981; background: #ecfdf5; color: #047857; border-radius: 8px;
        padding: 6px; font-weight: 600; cursor: pointer;
    }
    .ic-pilih { padding: 12px; display: grid; gap: 6px; font-size: 12px; }
    .ic-pilih p { margin: 0 0 2px; font-weight: 600; color: #1f2937; }
    .ic-pilih small { color: #9ca3af; }
    .ic-pil {
        text-align: left; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px;
        background: #fff; cursor: pointer; font-size: 13px;
    }
    .ic-pil:hover { background: #ecfdf5; border-color: #6ee7b7; }

    #icKartu {
        position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 9999;
        display: grid; gap: 8px; width: min(440px, calc(100vw - 32px));
    }
    .ic-kartu {
        background: #1f2937; color: #fff; border-radius: 14px; padding: 14px 16px;
        box-shadow: 0 12px 30px rgba(0,0,0,.25); display: grid; gap: 10px;
    }
    .ic-kartu-live { background: #7f1d1d; }
    .ic-judul { font-size: 15px; font-weight: 600; }
    .ic-live { color: #fca5a5; font-weight: 800; animation: icDenyut 1.2s infinite; }
    .ic-jam { font-variant-numeric: tabular-nums; opacity: .8; margin-left: 4px; }
    .ic-aksi { display: flex; gap: 8px; justify-content: flex-end; }
    .ic-b {
        border: 0; border-radius: 9px; padding: 8px 16px; font-weight: 700; cursor: pointer;
        background: rgba(255,255,255,.15); color: #fff; font-size: 14px;
    }
    .ic-b-on { background: #f59e0b; }
    .ic-b-merah { background: #dc2626; }
    .ic-b-hijau { background: #16a34a; }
    .ic-ketuk { border: 0; border-radius: 9px; padding: 10px; background: #fbbf24; color: #1f2937; font-weight: 700; cursor: pointer; }
    .ic-catatan { opacity: .7; font-size: 11px; }
    .ic-pesan {
        background: #fff; color: #1f2937; border: 1px solid #e5e7eb; border-radius: 10px;
        padding: 10px 14px; font-size: 13px; box-shadow: 0 6px 18px rgba(0,0,0,.12);
    }
</style>
@endif
