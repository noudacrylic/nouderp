<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\LaporStok;
use App\Modules\Purchasing\Services\LaporStokService;
use Illuminate\Http\Request;

/**
 * Tombol "Lapor Stok" di layar kerja divisi (Proses Produksi, Pemrosesan Pesanan). Siapa pun
 * yang login boleh melapor — yang di depan layar adalah karyawan divisi itu sendiri.
 */
class LaporStokController extends Controller
{
    public function __construct(private LaporStokService $lapor) {}

    /** Isi popup: daftar barang + laporan terakhir divisi ini beserta statusnya. */
    public function pilihan(Request $request)
    {
        $divisi = mb_substr((string) $request->input('divisi', 'Lainnya'), 0, 60);

        return response()->json([
            'barang'  => $this->lapor->pilihan($divisi),
            'laporan' => $this->lapor->terakhir($divisi)->map(fn (LaporStok $l) => [
                'barang'  => $l->namaBarang(),
                'kondisi' => LaporStok::KONDISI[$l->kondisi],
                'pelapor' => $l->namaPelapor(),
                'waktu'   => $l->created_at->format('d/m H:i'),
                'status'  => $l->statusLabel(),
            ]),
        ]);
    }

    public function simpan(Request $request)
    {
        $data = $request->validate([
            'divisi'       => 'required|string|max:60',
            'product_id'   => 'nullable|integer|exists:products,id|required_without:nama_barang',
            'nama_barang'  => 'nullable|string|max:150|required_without:product_id',
            'kondisi'      => 'required|in:' . implode(',', array_keys(LaporStok::KONDISI)),
            'catatan'      => 'nullable|string|max:255',
            'pelapor_nama' => 'nullable|string|max:100',
        ], [
            'product_id.required_without'  => 'Pilih barangnya, atau isi nama barang di "Lainnya".',
            'nama_barang.required_without' => 'Pilih barangnya, atau isi nama barang di "Lainnya".',
        ]);

        return back()->with('success', $this->lapor->lapor($data, $request->user()));
    }
}
