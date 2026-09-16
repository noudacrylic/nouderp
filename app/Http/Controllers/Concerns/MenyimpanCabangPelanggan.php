<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CustomerBranch;
use Illuminate\Http\Request;

/**
 * Menerima pilihan cabang dari form dokumen penjualan.
 *
 * Satu tempat karena ada satu pagar yang tidak boleh terlewat di mana pun:
 * cabang yang dikirim form HARUS milik pelanggan dokumen itu. Nilainya datang
 * dari kolom tersembunyi, dan kolom tersembunyi bisa disetel siapa saja —
 * tanpa pemeriksaan ini, satu angka yang diganti sudah cukup untuk membuat
 * pesanan tercetak dengan alamat perusahaan lain.
 *
 * Cabang yang tidak cocok diperlakukan sebagai "tidak ada cabang", bukan
 * kesalahan yang dilempar: bentuk yang sama juga muncul saat pelanggan diganti
 * di form tanpa memilih ulang cabangnya, dan itu kejadian sehari-hari yang
 * jawabannya memang "berarti atas nama induk".
 */
trait MenyimpanCabangPelanggan
{
    protected function cabangDariRequest(Request $request, $customerId): ?int
    {
        $cabangId = (int) $request->input('customer_branch_id');

        if (! $cabangId || ! $customerId) {
            return null;
        }

        return CustomerBranch::where('id', $cabangId)
            ->where('customer_id', (int) $customerId)
            ->value('id');
    }
}
