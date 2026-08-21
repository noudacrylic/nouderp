<?php

namespace App\Modules\Analysis\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analysis\Services\ProductionCalendarService;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\MachineDowntime;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Kalender Produksi — satu hari, jam per jam, per mesin & per operator.
 *
 * Halaman ini sengaja TIDAK menghitung HPP apa pun. Tugasnya cuma satu: memperlihatkan
 * bentuk hari kerja apa adanya, supaya keputusan soal kuota jam mesin diambil dari
 * gambaran yang utuh, bukan dari satu angka persentase.
 */
class ProductionCalendarController extends Controller
{
    public function __construct(protected ProductionCalendarService $service)
    {
    }

    public function index(Request $request)
    {
        $date = $this->resolveDate($request->query('date'));

        return view('erp.analisa.kalender.index', [
            'data'       => $this->service->build($date),
            'lastActive' => $this->service->lastActiveDate(),
            'executors'  => DepartmentExecutor::selectable()->with('department')->orderBy('department_id')->orderBy('name')->get(),
            'reasons'    => MachineDowntime::REASONS,
        ]);
    }

    /**
     * Catat henti mesin. Bukan koreksi timer — ini keterangan untuk waktu yang memang TIDAK
     * produktif, supaya lubang di kalender punya nama alih-alih jadi tuduhan diam-diam.
     */
    public function storeDowntime(Request $request)
    {
        $data = $request->validate([
            'executor_id' => 'required|exists:production_department_executors,id',
            'tanggal'     => 'required|date',
            'jam_mulai'   => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i|after:jam_mulai',
            'reason'      => 'required|in:' . implode(',', array_keys(MachineDowntime::REASONS)),
            'notes'       => 'nullable|string|max:255',
        ], [], [
            'jam_selesai' => 'jam selesai',
        ]);

        MachineDowntime::create([
            'executor_id' => $data['executor_id'],
            'started_at'  => $data['tanggal'] . ' ' . $data['jam_mulai'] . ':00',
            'ended_at'    => $data['tanggal'] . ' ' . $data['jam_selesai'] . ':00',
            'reason'      => $data['reason'],
            'notes'       => $data['notes'] ?? null,
            'created_by'  => auth()->user()->name ?? null,
        ]);

        return back()->with('success', 'Henti mesin dicatat.');
    }

    public function destroyDowntime(int $id)
    {
        MachineDowntime::findOrFail($id)->delete();

        return back()->with('success', 'Catatan henti mesin dihapus.');
    }

    /**
     * Tanpa parameter tanggal, buka hari produksi terakhir — bukan hari ini. Kalau dibuka
     * pagi-pagi sebelum ada yang menekan mulai, hari ini masih kosong dan halamannya
     * terlihat rusak padahal datanya memang belum ada.
     */
    protected function resolveDate(?string $raw): Carbon
    {
        if ($raw) {
            try {
                return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
            } catch (\Throwable) {
                // tanggal ngawur di URL → jatuh ke perilaku bawaan
            }
        }

        return $this->service->lastActiveDate() ?? Carbon::today();
    }
}
