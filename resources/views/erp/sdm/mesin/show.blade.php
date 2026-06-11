@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">{{ $machine->name }} <span class="text-gray-400 font-mono text-sm">{{ $machine->code }}</span></h1>
        <div class="text-xs text-gray-500 mt-1">
            @if($machine->isOnline())
                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">●  Online</span>
            @elseif($machine->last_seen_at)
                <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Offline · last seen {{ $machine->last_seen_at->diffForHumans() }}</span>
            @else
                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded">Belum pernah contact server</span>
            @endif
        </div>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('sdm.mesin.edit', $machine->id) }}" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Edit</a>
        <a href="{{ route('sdm.mesin.logs', $machine->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700">Lihat Semua Log</a>
        <a href="{{ route('sdm.mesin.index') }}" class="text-gray-600 px-3 py-1.5 text-sm">Kembali</a>
    </div>
</div>


<div class="grid grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded shadow p-3 text-center">
        <div class="text-xs text-gray-500">Total Log</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($stats['total_logs']) }}</div>
    </div>
    <div class="bg-white rounded shadow p-3 text-center">
        <div class="text-xs text-gray-500">Hari Ini</div>
        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['today']) }}</div>
    </div>
    <div class="bg-white rounded shadow p-3 text-center">
        <div class="text-xs text-gray-500">Belum Diproses</div>
        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['unprocessed']) }}</div>
    </div>
    <div class="bg-white rounded shadow p-3 text-center">
        <div class="text-xs text-gray-500">Belum Match Karyawan</div>
        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['unmatched']) }}</div>
    </div>
</div>

<div class="grid grid-cols-2 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 text-sm">
        <div class="text-xs uppercase tracking-widest text-gray-400 mb-2">Rincian Mesin</div>
        <table class="w-full text-sm">
            <tr><td class="py-1 text-gray-500 w-32">Serial Number</td><td class="font-mono">{{ $machine->serial_number ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">IP : Port</td><td class="font-mono">{{ $machine->ip_address ?? '-' }}:{{ $machine->port }}</td></tr>
            <tr><td class="py-1 text-gray-500">Model</td><td>{{ $machine->model ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Firmware</td><td>{{ $machine->firmware_version ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Lokasi</td><td>{{ $machine->location ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Last Sync</td><td>{{ optional($machine->last_sync_at)->format('d M Y H:i') ?? '-' }}</td></tr>
        </table>
        <div class="flex gap-2 mt-3 pt-3 border-t">
            <form method="POST" action="{{ route('sdm.mesin.test', $machine->id) }}">
                @csrf
                <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-xs">Test Koneksi TCP</button>
            </form>
            <form method="POST" action="{{ route('sdm.mesin.sync', $machine->id) }}"
                  onsubmit="return confirm('Sync semua log yang belum diproses ke attendance periode aktif?')">
                @csrf
                <button class="bg-blue-600 text-white px-3 py-1.5 rounded text-xs">Sync Log → Attendance</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded shadow p-4 text-sm">
        <div class="text-xs uppercase tracking-widest text-gray-400 mb-2">Setup ADMS Push (Real-time)</div>
        <p class="text-xs text-gray-600 mb-3">Konfigurasi mesin untuk push attendance secara real-time ke ERP. Di mesin: <strong>Menu &rarr; Set COM &rarr; Server Setting</strong>.</p>
        <table class="w-full text-xs">
            <tr><td class="py-1 text-gray-500 w-32">Server IP</td>
                <td><code class="bg-gray-100 px-2 py-0.5 rounded font-mono">{{ $serverIp }}</code></td></tr>
            <tr><td class="py-1 text-gray-500">Server Port</td>
                <td><code class="bg-gray-100 px-2 py-0.5 rounded font-mono">{{ parse_url($serverHost, PHP_URL_PORT) ?: '80' }}</code></td></tr>
            <tr><td class="py-1 text-gray-500">URL Path</td>
                <td><code class="bg-gray-100 px-2 py-0.5 rounded font-mono">/iclock/cdata</code></td></tr>
            <tr><td class="py-1 text-gray-500">Endpoint Penuh</td>
                <td><code class="bg-gray-100 px-2 py-0.5 rounded font-mono break-all">{{ $serverHost }}/iclock/cdata</code></td></tr>
            <tr><td class="py-1 text-gray-500">SN yang dikenali</td>
                <td><code class="bg-gray-100 px-2 py-0.5 rounded font-mono">{{ $machine->serial_number ?? 'BELUM DIISI' }}</code></td></tr>
        </table>
        <div class="mt-3 pt-3 border-t text-[11px] text-gray-500">
            Test endpoint: <a href="/iclock/ping" target="_blank" class="text-blue-600">/iclock/ping</a> → harus respond <code>OK</code>.
        </div>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4"
     x-data="admsMonitor({{ $machine->id }})"
     x-init="start()">
    <div class="flex items-center justify-between mb-2">
        <div>
            <div class="text-sm font-semibold flex items-center gap-2">
                Aktivitas ADMS (Live)
                <span class="w-2 h-2 rounded-full" :class="lastSeenOnline ? 'bg-green-500 animate-pulse' : 'bg-gray-300'"></span>
            </div>
            <div class="text-xs text-gray-500">Auto-refresh tiap 3 detik. Tampilkan request terakhir dari mesin ke endpoint <code>/iclock/*</code>.</div>
        </div>
        <button type="button" @click="paused = !paused" class="text-xs px-3 py-1 rounded border">
            <span x-text="paused ? '▶ Resume' : '⏸ Pause'"></span>
        </button>
    </div>

    <template x-if="logs.length === 0 && unknown.length === 0">
        <div class="text-center py-6 text-xs text-gray-400">
            Belum ada request dari mesin ke server. Setelah Server Setting di mesin di-konfig, request akan muncul di sini.
        </div>
    </template>

    <template x-if="logs.length > 0">
        <div>
            <div class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Request Mesin Anda (SN: {{ $machine->serial_number }})</div>
            <table class="w-full text-xs">
                <thead class="border-b text-gray-500">
                    <tr>
                        <th class="px-2 py-1 text-left w-32">Waktu</th>
                        <th class="px-2 py-1 text-left w-12">Method</th>
                        <th class="px-2 py-1 text-left">Path + Query</th>
                        <th class="px-2 py-1 text-center w-12">Status</th>
                        <th class="px-2 py-1 text-left w-32">From IP</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="log in logs" :key="log.id">
                        <tr class="border-b hover:bg-blue-50">
                            <td class="px-2 py-1 font-mono" x-text="new Date(log.occurred_at).toLocaleTimeString('id')"></td>
                            <td class="px-2 py-1 font-mono" x-text="log.method"></td>
                            <td class="px-2 py-1 font-mono break-all">
                                <span x-text="log.path"></span><span class="text-gray-400" x-text="log.query_string ? '?' + log.query_string : ''"></span>
                                <template x-if="log.body_sample">
                                    <div class="text-[10px] text-gray-400 mt-0.5 truncate max-w-md" x-text="log.body_sample"></div>
                                </template>
                            </td>
                            <td class="px-2 py-1 text-center">
                                <span class="px-1.5 py-0.5 rounded text-white font-bold"
                                      :class="log.response_code < 300 ? 'bg-green-500' : (log.response_code < 500 ? 'bg-yellow-500' : 'bg-red-500')"
                                      x-text="log.response_code"></span>
                            </td>
                            <td class="px-2 py-1 font-mono text-gray-500" x-text="log.client_ip"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <template x-if="unknown.length > 0">
        <div class="mt-4">
            <div class="text-[11px] font-bold text-orange-600 uppercase tracking-widest mb-1">⚠ Request Tanpa SN / SN Tidak Dikenali</div>
            <div class="text-[11px] text-gray-500 mb-1">Mesin coba akses tanpa kirim SN, atau SN-nya berbeda dari yang terdaftar. Cek apakah SN di form Mesin sama dengan yang ada di mesin.</div>
            <table class="w-full text-xs">
                <thead class="border-b text-gray-500">
                    <tr>
                        <th class="px-2 py-1 text-left w-32">Waktu</th>
                        <th class="px-2 py-1 text-left w-12">Method</th>
                        <th class="px-2 py-1 text-left">Path + Query</th>
                        <th class="px-2 py-1 text-center w-12">Status</th>
                        <th class="px-2 py-1 text-left w-32">From IP</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="log in unknown" :key="log.id">
                        <tr class="border-b text-orange-700">
                            <td class="px-2 py-1 font-mono" x-text="new Date(log.occurred_at).toLocaleTimeString('id')"></td>
                            <td class="px-2 py-1 font-mono" x-text="log.method"></td>
                            <td class="px-2 py-1 font-mono break-all">
                                <span x-text="log.path"></span><span class="text-gray-400" x-text="log.query_string ? '?' + log.query_string : ''"></span>
                            </td>
                            <td class="px-2 py-1 text-center">
                                <span class="bg-yellow-500 text-white px-1.5 py-0.5 rounded font-bold" x-text="log.response_code"></span>
                            </td>
                            <td class="px-2 py-1 font-mono" x-text="log.client_ip"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>
</div>

<script>
function admsMonitor(machineId) {
    return {
        machineId: machineId,
        logs: [],
        unknown: [],
        lastSeenOnline: false,
        paused: false,
        timer: null,
        start() {
            this.fetchData();
            this.timer = setInterval(() => { if (!this.paused) this.fetchData(); }, 3000);
        },
        async fetchData() {
            try {
                const r = await fetch(`/erp/sdm/mesin/${this.machineId}/adms-activity`);
                const j = await r.json();
                this.logs = j.logs;
                this.unknown = j.unknown;
                this.lastSeenOnline = j.is_online;
            } catch (e) { /* ignore */ }
        }
    };
}
</script>

<div class="bg-white rounded shadow p-4">
    <div class="flex items-center justify-between mb-2">
        <div class="text-sm font-semibold">50 Log Terbaru</div>
        <a href="{{ route('sdm.mesin.logs', $machine->id) }}" class="text-xs text-blue-600">Lihat semua &rarr;</a>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-2 py-2 text-left">Scan At</th>
                <th class="px-2 py-2 text-left">PIN</th>
                <th class="px-2 py-2 text-left">Karyawan</th>
                <th class="px-2 py-2 text-left">Method</th>
                <th class="px-2 py-2 text-left">Type</th>
                <th class="px-2 py-2 text-center w-20">Diproses</th>
            </tr>
        </thead>
        <tbody>
            @forelse($machine->logs as $log)
                <tr class="border-b">
                    <td class="px-2 py-1.5 font-mono text-xs">{{ $log->scan_at->format('d/m H:i:s') }}</td>
                    <td class="px-2 py-1.5 font-mono">{{ $log->user_id_fingerprint }}</td>
                    <td class="px-2 py-1.5">
                        @if($log->karyawan)
                            {{ $log->karyawan->name }} <span class="text-gray-400 text-xs">({{ $log->karyawan->staf_code }})</span>
                        @else
                            <span class="text-red-500 text-xs">⚠ tidak match</span>
                        @endif
                    </td>
                    <td class="px-2 py-1.5 text-xs">{{ $log->verify_method ?? '-' }}</td>
                    <td class="px-2 py-1.5 text-xs">{{ $log->verify_type ?? '-' }}</td>
                    <td class="px-2 py-1.5 text-center">
                        @if($log->processed)
                            <span class="text-green-600 text-xs">✓</span>
                        @else
                            <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-2 py-6 text-center text-gray-400 text-xs">Belum ada log scan dari mesin ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
