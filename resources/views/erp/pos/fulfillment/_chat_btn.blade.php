{{-- Tombol chat pembeli di header kartu SO. Param: $r baris dari FulfillmentReadinessService.

     Pesanan Shopee memakai pola kartu Desain di Meja Kerja (DesainSkill::aksi): Chat Shopee bila
     percakapannya sudah ada; bila belum, Mulai Chat Shopee (toko menyapa duluan) dengan Seller
     Centre sebagai cadangan — nomor disalin, tempel di kolom cari. Tautan chat WA tetap tampil.

     Lainnya: marketplace hanya bila pesanannya sudah ditautkan ke chat (tab Pesanan CRM) —
     pelanggannya "Tokopedia", bukan orang yang chat. Chat mana yang dibuka ditentukan saat
     diklik: CrmInboxController::chatPesanan. --}}
@php $chat = $r['chat'] ?? []; @endphp
@if(($r['kind'] ?? null) === 'so' && user_can_access('crm.inbox'))
    @if(!empty($chat['order_sn']))
        @if(!empty($chat['shopee']))
            <a href="{{ route('crm.inbox.show', $chat['shopee']) }}" target="_blank" rel="noopener"
               class="px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 ring-1 ring-orange-300 hover:bg-orange-50"
               title="Buka chat Shopee pembeli ini di CRM">💬 Chat Shopee</a>
        @else
            @if(!empty($chat['bisa_sapa']))
                {{-- Pesan ke pembeli: teksnya ditunjukkan & bisa diubah dulu, baru dikirim. --}}
                <details class="relative">
                    <summary class="list-none cursor-pointer px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 ring-1 ring-orange-300 hover:bg-orange-50"
                             title="Pembeli belum pernah chat — toko menyapa duluan">💬 Mulai Chat Shopee</summary>
                    <form method="POST" action="{{ route('tasks.meja.chat-shopee', $r['id']) }}" target="_blank"
                          class="absolute z-20 mt-1 w-80 max-w-[80vw] bg-white border rounded shadow p-2 space-y-1">@csrf
                        <div class="text-[11px] text-gray-500">Dikirim ke pembeli: kartu pesanan, lalu pesan ini.</div>
                        <textarea name="teks" rows="4" maxlength="1000" required class="w-full border rounded px-2 py-1 text-xs text-gray-800">{{ \App\Modules\CRM\Services\ChatPesananService::SAPAAN }}</textarea>
                        <button class="w-full px-2 py-1 rounded text-xs font-semibold bg-orange-500 text-white hover:bg-orange-600">Kirim & buka chat</button>
                    </form>
                </details>
            @endif
            <a href="https://seller.shopee.co.id/portal/sale/order" target="_blank" rel="noopener" data-copy="{{ $chat['order_sn'] }}"
               class="js-copy px-2 py-0.5 rounded text-[10px] font-bold {{ !empty($chat['bisa_sapa']) ? 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50' : 'bg-orange-100 text-orange-700 ring-1 ring-orange-300 hover:bg-orange-50' }}"
               title="Nomor pesanan disalin — tempel di kolom cari Seller Centre">{{ !empty($chat['bisa_sapa']) ? 'Seller Centre' : '💬 Chat di Seller Centre' }}</a>
        @endif
        @if(!empty($chat['wa']))
            <a href="{{ route('crm.inbox.show', $chat['wa']) }}" target="_blank" rel="noopener"
               class="px-2 py-0.5 rounded text-[10px] font-bold bg-white text-emerald-700 ring-1 ring-emerald-300 hover:bg-emerald-50"
               title="Buka chat WhatsApp yang ditautkan ke pesanan ini">💬 Chat WA</a>
        @endif
    @elseif(empty($r['is_marketplace']) || !empty($r['chat_tertaut']))
        <a href="{{ route('crm.inbox.chat-pesanan', $r['id']) }}"
           class="px-2 py-0.5 rounded text-[10px] font-bold bg-white text-emerald-700 ring-1 ring-emerald-300 hover:bg-emerald-50"
           title="{{ !empty($r['chat_tertaut']) ? 'Buka chat yang ditautkan ke pesanan ini' : 'Buka chat pembeli di CRM' }}">💬 Chat</a>
    @endif
@endif
