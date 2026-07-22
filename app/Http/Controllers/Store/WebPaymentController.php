<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use App\Models\WebPayment;
use App\Modules\Sales\Services\WebPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Pantau & kelola pembayaran toko online (Transfer Bank + Kode Unik).
 * Lapis konfirmasi manual: admin bisa tandai Lunas / Batalkan langsung dari sini.
 */
class WebPaymentController extends Controller
{
    public function __construct(protected WebPaymentService $service) {}

    public function index(Request $request)
    {
        $status = $request->get('status');

        $payments = WebPayment::with(['salesOrder.customer', 'confirmedBy'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(per_page_size())->withQueryString();

        $statuses = [
            ''                            => 'Semua',
            WebPayment::STATUS_AWAITING   => 'Menunggu Transfer',
            WebPayment::STATUS_CLAIMED    => 'Diklaim Bayar',
            WebPayment::STATUS_MATCHED    => 'Nominal Cocok',
            WebPayment::STATUS_CONFIRMED  => 'Lunas',
            WebPayment::STATUS_EXPIRED    => 'Kedaluwarsa',
            WebPayment::STATUS_CANCELLED  => 'Dibatalkan',
        ];

        $accounts = PaymentSetting::singleton()->accounts();

        return view('erp.store.web-payments.index', compact('payments', 'statuses', 'status', 'accounts'));
    }

    public function confirm(int $id, Request $request)
    {
        // Rekening penerima yang dipilih admin (posting ke akun kas bank tsb).
        $key    = $request->input('account_key');
        $cashId = $key !== null && $key !== ''
            ? PaymentSetting::singleton()->cashIdFor((int) $key)
            : null;

        $this->service->confirm($id, 'manual', 'manual:' . Auth::id(), Auth::id(), $cashId);
        return back()->with('success', 'Pembayaran ditandai LUNAS.');
    }

    public function cancel(int $id)
    {
        $this->service->expire($id, WebPayment::STATUS_CANCELLED, 'Dibatalkan manual oleh ' . (Auth::user()->name ?? 'admin'));
        return back()->with('success', 'Order dibatalkan & reservasi stok dilepas.');
    }
}
