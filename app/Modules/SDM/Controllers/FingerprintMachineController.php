<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\AdmsAccessLog;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\FingerprintMachine;
use App\Modules\SDM\Services\AdmsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class FingerprintMachineController extends Controller
{
    public function index(Request $request)
    {
        $showArchived = $request->boolean('archived');

        $machines = FingerprintMachine::query()
            ->when(! $showArchived, fn($q) => $q->where('is_active', true))
            ->orderBy('is_active', 'desc')
            ->orderBy('code')
            ->get();

        $archivedCount = FingerprintMachine::where('is_active', false)->count();

        return view('erp.sdm.mesin.index', compact('machines', 'showArchived', 'archivedCount'));
    }

    public function create()
    {
        return view('erp.sdm.mesin.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        FingerprintMachine::create($data);
        return redirect(list_url('sdm.mesin.index'))->with('success', 'Mesin berhasil ditambahkan.');
    }

    public function show(int $id)
    {
        $machine = FingerprintMachine::with(['logs' => fn($q) => $q->latest('scan_at')->limit(50)])->findOrFail($id);

        $stats = [
            'total_logs'      => FingerprintLog::where('machine_id', $id)->count(),
            'unprocessed'     => FingerprintLog::where('machine_id', $id)->where('processed', false)->count(),
            'unmatched'       => FingerprintLog::where('machine_id', $id)->whereNull('karyawan_id')->count(),
            'today'           => FingerprintLog::where('machine_id', $id)->whereDate('scan_at', today())->count(),
        ];

        $serverHost = request()->getSchemeAndHttpHost();
        $serverIp = @gethostbyname(gethostname()) ?: '127.0.0.1';

        return view('erp.sdm.mesin.show', compact('machine', 'stats', 'serverHost', 'serverIp'));
    }

    public function edit(int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);
        return view('erp.sdm.mesin.edit', compact('machine'));
    }

    public function update(Request $request, int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);
        $data = $this->validateData($request, $id);
        $machine->update($data);
        return redirect(list_url('sdm.mesin.index'))->with('success', 'Mesin berhasil diperbarui.');
    }

    public function destroy(int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);

        if (! $machine->canBeDeleted()) {
            $logCount = $machine->logs()->count();
            return back()->with('error',
                "Mesin '{$machine->name}' tidak bisa dihapus karena sudah punya {$logCount} log scan. "
                . "Gunakan tombol Arsipkan supaya jejak attendance tidak hilang."
            );
        }

        $machine->delete();
        return back()->with('success', 'Mesin dihapus.');
    }

    public function archive(int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);
        $machine->update(['is_active' => false]);
        return back()->with('success', "Mesin '{$machine->name}' diarsipkan. Tidak terima scan baru, log lama tetap tersimpan.");
    }

    public function unarchive(int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);
        $machine->update(['is_active' => true]);
        return back()->with('success', "Mesin '{$machine->name}' diaktifkan kembali.");
    }

    /**
     * Test TCP socket connection ke IP:port mesin.
     */
    public function testConnection(int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);
        if (! $machine->ip_address) {
            return back()->with('error', 'IP Address belum diisi.');
        }

        $errno = 0;
        $errstr = '';
        $start = microtime(true);
        $sock = @fsockopen($machine->ip_address, $machine->port, $errno, $errstr, 3.0);
        $elapsed = round((microtime(true) - $start) * 1000);

        if ($sock === false) {
            return back()->with('error', "Gagal konek ke {$machine->ip_address}:{$machine->port} — {$errstr} (errno {$errno})");
        }
        fclose($sock);

        return back()->with('success', "Mesin terjangkau di {$machine->ip_address}:{$machine->port} ({$elapsed} ms). Catatan: ini hanya cek port terbuka, bukan validasi protokol.");
    }

    /**
     * Manual sync: replay all unprocessed logs ke attendance.
     */
    public function syncAttendance(int $id, AdmsService $svc)
    {
        $machine = FingerprintMachine::findOrFail($id);
        $result = $svc->syncAllUnprocessedForMachine($machine);

        $msg = "Sync selesai. Total log: {$result['total']}, dipetakan ke attendance: {$result['matched']}, tidak match karyawan: {$result['unmatched']}.";
        return back()->with('success', $msg);
    }

    /**
     * JSON endpoint untuk diagnostic panel auto-refresh.
     */
    public function admsActivity(int $id)
    {
        $machine = FingerprintMachine::findOrFail($id);
        $sn = $machine->serial_number;

        $logs = AdmsAccessLog::where(function ($q) use ($machine, $sn) {
                $q->where('machine_id', $machine->id);
                if ($sn) $q->orWhere('serial_number', $sn);
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id','occurred_at','method','path','query_string','serial_number','client_ip','response_code','body_sample']);

        // juga tampilkan request unknown (SN null) — untuk ngeliat kalau mesin coba protokol lain
        $unknownRecent = AdmsAccessLog::whereNull('machine_id')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id','occurred_at','method','path','query_string','serial_number','client_ip','response_code','body_sample']);

        return response()->json([
            'machine_id'   => $machine->id,
            'last_seen_at' => optional($machine->last_seen_at)->toIso8601String(),
            'is_online'    => $machine->isOnline(),
            'logs'         => $logs,
            'unknown'      => $unknownRecent,
            'now'          => now()->toIso8601String(),
        ]);
    }

    public function logs(int $id, Request $request)
    {
        $machine = FingerprintMachine::findOrFail($id);
        $query = FingerprintLog::with('karyawan')->where('machine_id', $id);

        if ($request->filled('processed')) {
            $query->where('processed', $request->processed === 'yes');
        }
        if ($request->filled('matched')) {
            if ($request->matched === 'yes') $query->whereNotNull('karyawan_id');
            else $query->whereNull('karyawan_id');
        }
        if ($request->filled('date')) {
            $query->whereDate('scan_at', $request->date);
        }

        $logs = $query->orderByDesc('scan_at')->paginate(50)->withQueryString();
        return view('erp.sdm.mesin.logs', compact('machine', 'logs'));
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        // Normalize IP: strip leading zeros tiap octet (mesin sering display 192.168.001.224)
        if ($ip = $request->input('ip_address')) {
            $clean = implode('.', array_map(
                fn($oct) => (string) (int) $oct,
                explode('.', trim($ip))
            ));
            $request->merge(['ip_address' => $clean]);
        }

        $unique = $id ? ",{$id}" : '';
        return $request->validate([
            'code'             => 'required|string|max:30|unique:sdm_fingerprint_machines,code' . $unique,
            'name'             => 'required|string|max:100',
            'serial_number'    => 'nullable|string|max:60|unique:sdm_fingerprint_machines,serial_number' . $unique,
            'ip_address'       => 'nullable|ip',
            'port'             => 'nullable|integer|min:1|max:65535',
            'model'            => 'nullable|string|max:60',
            'firmware_version' => 'nullable|string|max:60',
            'location'         => 'nullable|string|max:150',
            'comm_key'         => 'nullable|string|max:60',
            'is_active'        => 'nullable|boolean',
            'notes'            => 'nullable|string',
        ]) + ['is_active' => $request->boolean('is_active', true), 'port' => (int) ($request->port ?: 5005)];
    }
}
