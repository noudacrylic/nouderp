<?php

namespace App\Modules\SDM\Controllers;

use App\Modules\SDM\Models\AdmsAccessLog;
use App\Modules\SDM\Models\FingerprintMachine;
use App\Modules\SDM\Services\AdmsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * ADMS endpoint untuk fingerprint machine ZKTeco-compatible.
 */
class AdmsController extends Controller
{
    public function __construct(protected AdmsService $svc) {}

    public function ping(Request $request)
    {
        return $this->finish($request, response('OK', 200), null);
    }

    public function cdata(Request $request)
    {
        $sn = $request->query('SN');
        $machine = $sn ? $this->svc->findMachineBySerial($sn) : null;

        if (! $machine) {
            return $this->finish($request, response('Unauthorized SN', 401), null);
        }

        $this->svc->touchSeen($machine);

        if ($request->isMethod('GET')) {
            return $this->finish(
                $request,
                response($this->svc->buildHandshakeConfig($machine), 200)->header('Content-Type', 'text/plain'),
                $machine
            );
        }

        $table = $request->query('table');
        $body = $request->getContent();

        try {
            if ($table === 'ATTLOG') {
                $count = $this->svc->ingestAttLog($machine, $body);
                return $this->finish($request, response("OK: {$count}", 200), $machine);
            }
            if ($table === 'OPERLOG') {
                $count = $this->svc->ingestOperLog($machine, $body);
                return $this->finish($request, response("OK: {$count}", 200), $machine);
            }
        } catch (\Throwable $e) {
            Log::error('ADMS cdata error', ['err' => $e->getMessage(), 'sn' => $sn, 'table' => $table]);
            return $this->finish($request, response("ERROR: {$e->getMessage()}", 500), $machine);
        }

        return $this->finish($request, response('OK', 200), $machine);
    }

    public function getrequest(Request $request)
    {
        $sn = $request->query('SN');
        $machine = $sn ? $this->svc->findMachineBySerial($sn) : null;
        if (! $machine) return $this->finish($request, response('Unauthorized SN', 401), null);

        $this->svc->touchSeen($machine);
        return $this->finish(
            $request,
            response($this->svc->pullPendingCommands($machine), 200)->header('Content-Type', 'text/plain'),
            $machine
        );
    }

    public function devicecmd(Request $request)
    {
        $sn = $request->query('SN');
        $machine = $sn ? $this->svc->findMachineBySerial($sn) : null;
        if (! $machine) return $this->finish($request, response('Unauthorized SN', 401), null);

        $this->svc->ackCommand($machine, $request->getContent());
        return $this->finish($request, response('OK', 200), $machine);
    }

    /**
     * Catch-all untuk path /iclock/* yang tidak terdaftar — log saja, return 404.
     * Berguna untuk debugging: kalau mesin nge-hit path yang berbeda dari ADMS standar,
     * kita masih lihat aktivitasnya di diagnostic panel.
     */
    public function catchAll(Request $request)
    {
        $sn = $request->query('SN');
        $machine = $sn ? $this->svc->findMachineBySerial($sn) : null;
        return $this->finish($request, response('Not Found', 404), $machine);
    }

    /**
     * Log request + response ke sdm_adms_access_log untuk diagnostic panel.
     */
    protected function finish(Request $request, $response, ?FingerprintMachine $machine)
    {
        try {
            $body = $request->getContent();
            AdmsAccessLog::create([
                'occurred_at'     => now(),
                'method'          => $request->method(),
                'path'            => '/' . ltrim($request->path(), '/'),
                'query_string'    => $request->getQueryString(),
                'serial_number'   => $request->query('SN'),
                'client_ip'       => $request->ip(),
                'body_sample'     => $body ? mb_substr($body, 0, 1024) : null,
                'response_code'   => $response->getStatusCode(),
                'response_sample' => mb_substr((string) $response->getContent(), 0, 500),
                'machine_id'      => $machine?->id,
            ]);

            // Auto-prune: keep last 500 rows
            if (random_int(1, 20) === 1) {
                AdmsAccessLog::prune(500);
            }
        } catch (\Throwable $e) {
            // jangan break response kalau logging gagal
            Log::warning('ADMS access log failed', ['err' => $e->getMessage()]);
        }

        return $response;
    }
}
