{{-- Kabari pembeli bahwa barangnya sudah bisa diambil. Param: $r (row kartu).

     Hanya muncul untuk Ambil di Toko yang belum diambil. Pemindaian otomatis tiap 15 menit
     sudah menandai pesanan yang kesiapannya bisa disimpulkan ERP; tombol ini untuk yang
     barangnya selesai lebih dulu daripada yang tercatat — dan supaya keadaan "sudah dikabari
     atau belum" tidak perlu ditebak siapa pun. --}}
@if(($r['is_pickup'] ?? false) && ($r['pickup_status'] ?? null) !== 'picked_up')
    <div class="mt-1 flex items-center justify-end gap-2 flex-wrap">
        @if(!empty($r['ready_at']))
            <span class="text-[11px] text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-2 py-0.5"
                  title="Pembeli sudah dikabari lewat WhatsApp bahwa barangnya siap diambil">
                🔔 Siap diambil · dikabari {{ \Carbon\Carbon::parse($r['ready_at'])->format('d/m H:i') }}
            </span>
            <form action="{{ route('pos.fulfillment.batal-siap-diambil', $r['id']) }}" method="POST"
                  onsubmit="return confirm('Tarik kembali penandaan siap diambil {{ $r['number'] }}? Notifikasi yang belum berangkat ikut dibatalkan.')">
                @csrf
                <button type="submit" class="text-[11px] text-gray-500 hover:text-red-600 font-semibold">tarik kembali</button>
            </form>
        @else
            <form action="{{ route('pos.fulfillment.siap-diambil', $r['id']) }}" method="POST"
                  onsubmit="return confirm('Tandai {{ $r['number'] }} siap diambil? Pembeli dikabari lewat WhatsApp, menyesuaikan jam buka toko.')">
                @csrf
                <button type="submit"
                        class="text-[11px] px-2 py-0.5 rounded border border-amber-300 text-amber-700 hover:bg-amber-50 font-semibold">
                    🔔 Tandai Siap Diambil
                </button>
            </form>
        @endif
    </div>
@endif
