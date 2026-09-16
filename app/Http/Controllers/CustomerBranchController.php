<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cabang pelanggan: alamat kirim tambahan di bawah satu pelanggan.
 *
 * Editornya sengaja halaman tersendiri, bukan popup di dalam form pelanggan.
 * Alasannya teknis sekaligus praktis: `_shipping_fields.blade.php` memakai id
 * elemen tetap (`cust_area`, `cust_postal`, …) yang dipakai pencarian areanya,
 * jadi dua salinan di satu halaman akan saling menimpa diam-diam. Halaman
 * sendiri juga memberi ruang lebih lega untuk alamat yang panjang.
 */
class CustomerBranchController extends Controller
{
    public function create(Customer $customer)
    {
        return view('erp.master.customers.branches.create', compact('customer'));
    }

    public function store(Request $request, Customer $customer)
    {
        $customer->branches()->create($this->formData($request) + ['is_active' => true]);

        return redirect()->route('customers.edit', $customer->id)
            ->with('success', 'Cabang ditambahkan.');
    }

    public function edit(Customer $customer, CustomerBranch $cabang)
    {
        $this->pastikanMilik($customer, $cabang);

        return view('erp.master.customers.branches.edit', compact('customer', 'cabang'));
    }

    public function update(Request $request, Customer $customer, CustomerBranch $cabang)
    {
        $this->pastikanMilik($customer, $cabang);

        $cabang->update($this->formData($request));

        return redirect()->route('customers.edit', $customer->id)
            ->with('success', 'Cabang diperbarui.');
    }

    /** Arsipkan — aman walau cabangnya sudah dipakai dokumen. */
    public function archive(Customer $customer, CustomerBranch $cabang)
    {
        $this->pastikanMilik($customer, $cabang);
        $cabang->update(['is_active' => false]);

        return back()->with('success', 'Cabang diarsipkan (nonaktif).');
    }

    public function restore(Customer $customer, CustomerBranch $cabang)
    {
        $this->pastikanMilik($customer, $cabang);
        $cabang->update(['is_active' => true]);

        return back()->with('success', 'Cabang diaktifkan kembali.');
    }

    /** Hapus permanen — HANYA bila belum pernah dipakai di dokumen mana pun. */
    public function destroy(Customer $customer, CustomerBranch $cabang)
    {
        $this->pastikanMilik($customer, $cabang);

        if ($this->terpakai($cabang)) {
            return back()->with('error', 'Cabang tidak bisa dihapus karena sudah dipakai di dokumen. Gunakan Arsipkan.');
        }

        $cabang->delete();

        return back()->with('success', 'Cabang dihapus permanen.');
    }

    /**
     * Cabang ini sudah menempel di dokumen mana pun?
     *
     * Kolom `customer_branch_id` baru lahir bersama tahap berikutnya, jadi
     * pemeriksaannya menanyakan keberadaan kolom lebih dulu. Ditulis sekarang
     * dan bukan nanti supaya tombol Hapus tidak pernah sempat ada dalam versi
     * yang diam-diam membuang alamat nota lama — kembaran dari
     * CustomerController::usedCustomerIds() yang menjaga hal yang sama.
     */
    private function terpakai(CustomerBranch $cabang): bool
    {
        $tables = ['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_deliveries'];

        foreach ($tables as $t) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'customer_branch_id')) {
                continue;
            }

            if (DB::table($t)->where('customer_branch_id', $cabang->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cabang harus benar-benar milik pelanggan di URL-nya.
     *
     * Tanpa ini, mengganti angka pelanggan di alamat browser akan memindahkan
     * cabang orang lain ke pelanggan ini — dan pesanan berikutnya terkirim ke
     * alamat perusahaan yang salah.
     */
    private function pastikanMilik(Customer $customer, CustomerBranch $cabang): void
    {
        abort_unless((int) $cabang->customer_id === (int) $customer->id, 404);
    }

    /** Kolom yang sama dengan alamat kirim induk — lihat CustomerController::customerFormData(). */
    private function formData(Request $request): array
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'pic_name'           => 'nullable|string|max:255',
            'phone'              => 'nullable|string|max:30',
            'recipient_phone'    => 'nullable|string|max:30',
            'shipping_address'   => 'nullable|string|max:2000',
            'province'           => 'nullable|string|max:100',
            'city'               => 'nullable|string|max:100',
            'district'           => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:10',
            'biteship_area_id'   => 'nullable|string|max:100',
            'kiriminaja_area_id' => 'nullable|string|max:100',
            'jubelio_area_id'    => 'nullable|string|max:100',
            'location_point'     => 'nullable|string|max:500',
        ]);

        $point = parse_lat_long($data['location_point'] ?? null);
        unset($data['location_point']);
        $data['latitude']  = $point['latitude'];
        $data['longitude'] = $point['longitude'];

        return $data;
    }
}
