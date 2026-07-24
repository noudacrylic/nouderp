<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\WebPayment;
use Illuminate\Http\Request;

/**
 * Faktur versi WEB untuk pesanan etalase — bukti pembayaran gaya marketplace yang
 * bisa dilihat & di-download pembeli. BUKAN faktur/invoice resmi ERP (yang butuh
 * SalesInvoice + akuntansi); ini hanya struk dari data pesanan (SO) + status bayar.
 * Tanpa tanda tangan. Diakses publik via public_token WebPayment (tanpa login).
 */
class WebFakturController extends Controller
{
    public function show(string $token)
    {
        $wp = WebPayment::where('public_token', $token)->first();

        if (! $wp || ! $wp->salesOrder) {
            return response()->view('faktur.web_invalid', ['reason' => 'Faktur tidak ditemukan.'], 404);
        }
        if (! $wp->isConfirmed()) {
            return response()->view('faktur.web_invalid', ['reason' => 'Faktur akan tersedia setelah pembayaran diterima.'], 403);
        }

        $order = $wp->salesOrder->load(['items.product', 'customer']);
        $profile = BusinessProfile::instance();

        return view('faktur.web', compact('wp', 'order', 'profile'));
    }
}
