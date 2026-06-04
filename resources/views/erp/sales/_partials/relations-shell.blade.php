{{--
    Shell partial untuk menampilkan keterkaitan dokumen.
    Variabel:
      $title    string  judul section (default "Keterkaitan Dokumen")
      $hint     string  penjelasan kecil di header
      $groups   array   [
          [
              'label'    => 'Surat Jalan',
              'items'    => [
                  [
                      'number'    => 'DO/...',
                      'date'      => '01 Jan 2026',
                      'status'    => 'posted',                    // string raw (lowercased)
                      'status_label' => 'Posted',                  // human readable
                      'is_active' => true,                          // blokir void?
                      'url'       => '/erp/sales/...',              // optional
                      'extra'     => 'Rp 100.000',                  // optional info kecil
                  ],
                  ...
              ],
          ],
          ...
      ]
      $canVoid  bool    apakah dokumen utama bisa di-void
--}}
@php
    $title    = $title ?? 'Keterkaitan Dokumen';
    $hint     = $hint ?? 'Daftar dokumen yang terkait dengan dokumen ini.';
    $groups   = $groups ?? [];
    $canVoid  = $canVoid ?? null;

    $blockingCount = 0;
    foreach ($groups as $g) {
        foreach ($g['items'] ?? [] as $it) {
            if (!empty($it['is_active'])) $blockingCount++;
        }
    }

    $statusClass = function ($s) {
        $s = strtolower($s ?? '');
        return match (true) {
            in_array($s, ['posted', 'paid', 'open', 'partial', 'received', 'repaired', 'shipped', 'confirmed']) => 'bg-green-100 text-green-700',
            in_array($s, ['draft']) => 'bg-yellow-100 text-yellow-700',
            in_array($s, ['void', 'cancelled']) => 'bg-gray-200 text-gray-500',
            default => 'bg-blue-100 text-blue-700',
        };
    };
@endphp

<div class="bg-white border rounded shadow-sm mt-4">
    <div class="px-4 py-3 border-b flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">{{ $title }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ $hint }}</p>
        </div>
        @if($canVoid !== null)
            @if($canVoid)
                <span class="px-2 py-1 rounded text-xs font-bold bg-green-100 text-green-700">
                    ✓ Aman di-void — tidak ada keterkaitan aktif
                </span>
            @else
                <span class="px-2 py-1 rounded text-xs font-bold bg-red-100 text-red-700">
                    ✕ Tidak bisa di-void — masih ada {{ $blockingCount }} dokumen aktif
                </span>
            @endif
        @endif
    </div>

    <div class="divide-y">
        @forelse($groups as $group)
            <div class="px-4 py-3">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                    {{ $group['label'] ?? '-' }}
                    <span class="text-gray-400 font-normal normal-case ml-1">({{ count($group['items'] ?? []) }})</span>
                </div>
                @if(!empty($group['items']))
                    <div class="space-y-1.5">
                        @foreach($group['items'] as $it)
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if(!empty($it['is_active']))
                                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-red-500" title="Memblokir void"></span>
                                    @else
                                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                    @endif
                                    @if(!empty($it['url']))
                                        <a href="{{ $it['url'] }}" class="font-medium text-blue-600 hover:underline truncate">
                                            {{ $it['number'] ?? '-' }}
                                        </a>
                                    @else
                                        <span class="font-medium text-gray-700 truncate">{{ $it['number'] ?? '-' }}</span>
                                    @endif
                                    @if(!empty($it['date']))
                                        <span class="text-xs text-gray-400 whitespace-nowrap">· {{ $it['date'] }}</span>
                                    @endif
                                    @if(!empty($it['extra']))
                                        <span class="text-xs text-gray-500 whitespace-nowrap">· {{ $it['extra'] }}</span>
                                    @endif
                                </div>
                                @if(!empty($it['status_label']))
                                    <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusClass($it['status'] ?? '') }}">
                                        {{ $it['status_label'] }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-xs text-gray-400 italic">Tidak ada.</div>
                @endif
            </div>
        @empty
            <div class="px-4 py-6 text-center text-sm text-gray-400 italic">
                Tidak ada dokumen terkait.
            </div>
        @endforelse
    </div>
</div>
