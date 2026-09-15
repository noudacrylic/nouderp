{{-- Kabar pelunasan untuk pesanan KIRIM di "Belum Lunas". Param: $r (row kartu).

     Pemindaian otomatis tiap 15 menit (pos:pindai-pelunasan) sudah mengabari; tombol ini untuk
     yang ingin mengabari sekarang, dan supaya "pembeli sudah tahu atau belum" tidak ditebak.
     Pesannya TANPA tautan bayar — tautan dibagikan dari nomor admin. --}}
@if(! ($r['is_pickup'] ?? false) && empty($r['is_marketplace']) && empty($r['is_tempo']))
    @php $kabarPelunasan = \App\Modules\POS\Services\PelunasanNoticeService::terakhir($r['id']); @endphp
    <div class="mt-1 flex items-center justify-end gap-2 flex-wrap">
        @if($kabarPelunasan && $kabarPelunasan->status !== \App\Modules\CRM\Models\CrmOutboxMessage::STATUS_DILEWATI)
            <span class="text-[11px] text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-2 py-0.5"
                  title="Pembeli dikabari lewat WhatsApp untuk menyelesaikan pelunasan (tanpa tautan bayar)">
                🔔 Pelunasan ·
                @if($kabarPelunasan->status === \App\Modules\CRM\Models\CrmOutboxMessage::STATUS_TERKIRIM)
                    dikabari {{ $kabarPelunasan->sent_at?->format('d/m H:i') }}
                @elseif($kabarPelunasan->status === \App\Modules\CRM\Models\CrmOutboxMessage::STATUS_GAGAL)
                    <span class="text-red-600">gagal terkirim</span>
                @else
                    menunggu kirim{{ $kabarPelunasan->scheduled_at ? ' ' . $kabarPelunasan->scheduled_at->format('d/m H:i') : '' }}
                @endif
            </span>
        @else
            @if($kabarPelunasan)
                <span class="text-[11px] text-gray-500" title="{{ $kabarPelunasan->reason }}">Tidak dikabari: {{ \Illuminate\Support\Str::limit($kabarPelunasan->reason, 60) }}</span>
            @endif
            <form action="{{ route('pos.fulfillment.kabari-pelunasan', $r['id']) }}" method="POST"
                  onsubmit="return confirm('Kabari pembeli {{ $r['number'] }} untuk pelunasan sisa {{ rupiah($r['remaining']) }}? Dikirim lewat WhatsApp tanpa tautan bayar, menyesuaikan jam buka toko.')">
                @csrf
                <button type="submit"
                        class="text-[11px] px-2 py-0.5 rounded border border-amber-300 text-amber-700 hover:bg-amber-50 font-semibold">
                    🔔 Kabari Pelunasan
                </button>
            </form>
        @endif
    </div>
@endif
