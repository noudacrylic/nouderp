@php
    $current = $current ?? null;
    $routeName = \Illuminate\Support\Facades\Route::currentRouteName() ?? '';
    if (!$current) {
        if (str_starts_with($routeName, 'tasks.schedules')) {
            $current = 'jadwal';
        } elseif (str_starts_with($routeName, 'tasks.automation.rules') && (request()->route('automationType') === 'stock')) {
            $current = 'stok';
        } elseif (str_starts_with($routeName, 'tasks.automation.rules') && (request()->route('automationType') === 'order')) {
            $current = 'pesanan';
        }
    }
@endphp

<div class="flex items-center gap-2 mb-4 border-b pb-2">
    <span class="text-xs font-semibold text-gray-500 mr-2">Otomasi:</span>
    <a href="{{ route('tasks.schedules.index') }}"
       class="px-3 py-1.5 rounded text-sm {{ $current === 'jadwal' ? 'bg-blue-600 text-white font-semibold' : 'text-gray-600 hover:bg-gray-100' }}">
        Jadwal
    </a>
    <a href="{{ route('tasks.automation.rules.index', ['automationType' => 'stock']) }}"
       class="px-3 py-1.5 rounded text-sm {{ $current === 'stok' ? 'bg-blue-600 text-white font-semibold' : 'text-gray-600 hover:bg-gray-100' }}">
        Stok
    </a>
    <a href="{{ route('tasks.automation.rules.index', ['automationType' => 'order']) }}"
       class="px-3 py-1.5 rounded text-sm {{ $current === 'pesanan' ? 'bg-blue-600 text-white font-semibold' : 'text-gray-600 hover:bg-gray-100' }}">
        Pesanan
    </a>
</div>
