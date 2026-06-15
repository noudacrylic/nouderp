<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::withSum('overpayments', 'amount')
            ->when($request->q, function ($query, $term) {
                $query->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                      ->orWhere('code', 'like', "%{$term}%")
                      ->orWhere('phone', 'like', "%{$term}%")
                      ->orWhere('city', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Customer yang sudah dipakai di transaksi → hanya bisa diarsipkan, tak bisa dihapus.
        $usedIds = $this->usedCustomerIds($customers->pluck('id')->all());
        foreach ($customers as $c) {
            $c->is_used = in_array((int) $c->id, $usedIds, true);
        }

        return view('erp.master.customers.index', compact('customers'));
    }

    /** ID customer yang sudah dipakai di transaksi mana pun (penawaran/SO/faktur/retur/garansi/DP/lebih-bayar). */
    private function usedCustomerIds(array $ids): array
    {
        if (empty($ids)) return [];

        $tables = [
            'sales_quotations', 'sales_orders', 'sales_invoices', 'sales_returns',
            'warranty_orders', 'sales_advances', 'customer_overpayments',
        ];

        $merged = [];
        foreach ($tables as $t) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($t)) continue;
            $merged = array_merge(
                $merged,
                \DB::table($t)->whereIn('customer_id', $ids)->pluck('customer_id')->all()
            );
        }

        return array_values(array_unique(array_map('intval', array_filter($merged, fn ($v) => $v !== null))));
    }

    /** Arsipkan customer (nonaktif) — aman walau sudah dipakai transaksi. */
    public function archive($id)
    {
        Customer::findOrFail($id)->update(['is_active' => 0]);
        return back()->with('success', 'Customer diarsipkan (nonaktif).');
    }

    /** Aktifkan kembali customer yang diarsipkan. */
    public function restore($id)
    {
        Customer::findOrFail($id)->update(['is_active' => 1]);
        return back()->with('success', 'Customer diaktifkan kembali.');
    }

    /** Hapus permanen — HANYA bila customer belum dipakai di transaksi mana pun. */
    public function destroy($id)
    {
        $customer = Customer::findOrFail($id);

        if (! empty($this->usedCustomerIds([(int) $id]))) {
            return back()->with('error', 'Customer tidak bisa dihapus karena sudah dipakai di transaksi. Gunakan Arsipkan.');
        }

        $customer->delete();
        return back()->with('success', 'Customer dihapus permanen.');
    }

    public function create()
    {
        return view('erp.master.customers.create');
    }

    public function store(Request $request)
    {
        Customer::create([
            'code' => 'CUST-' . time(),
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'customer_type' => $request->customer_type ?? 'regular',
            'is_active' => true
        ]);

        return redirect(list_url('customers.index'));
    }

    public function edit($id)
    {
        $customer = Customer::findOrFail($id);

        return view('erp.master.customers.edit', compact('customer'));
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $customer->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'customer_type' => $request->customer_type
        ]);

        return redirect(list_url('customers.index'));
    }

    public function storeAjax(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required|string|max:200',
            'phone'   => 'nullable|string|max:30',
            'address' => 'nullable|string|max:2000',
        ]);

        $customer = Customer::create([
            'name'          => $data['name'],
            'phone'         => $data['phone'] ?? null,
            'address'       => $data['address'] ?? null,
            'code'          => 'CUST-' . time(),
            'customer_type' => 'regular',
            'is_active'     => true,
        ]);

        return response()->json([
            'id'      => $customer->id,
            'name'    => $customer->name,
            'phone'   => $customer->phone,
            'address' => $customer->address,
        ]);
    }
    /** Info alamat pengiriman customer (untuk panel Pengiriman di SO/Invoice). */
    public function shippingInfo($id)
    {
        $c = Customer::findOrFail($id);
        return response()->json($this->shippingPayload($c));
    }

    /** Simpan/ubah alamat pengiriman customer dari popup. */
    public function updateShipping(Request $request, $id)
    {
        $c = Customer::findOrFail($id);

        $data = $request->validate([
            'recipient_phone'  => 'nullable|string|max:30',
            'shipping_address' => 'nullable|string|max:2000',
            'province'         => 'nullable|string|max:100',
            'city'             => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'postal_code'      => 'nullable|string|max:10',
            'biteship_area_id' => 'nullable|string|max:100',
            'kiriminaja_area_id' => 'nullable|string|max:100',
            // Titik lokasi (untuk kurir instant) — boleh link Google Maps atau "lat,long".
            'location_point'   => 'nullable|string|max:500',
        ]);

        $point = parse_lat_long($data['location_point'] ?? null);
        unset($data['location_point']);
        $data['latitude']  = $point['latitude'];
        $data['longitude'] = $point['longitude'];

        $c->update($data);

        return response()->json($this->shippingPayload($c->fresh()));
    }

    private function shippingPayload(Customer $c): array
    {
        $line = collect([$c->shipping_address, $c->district, $c->city, $c->province, $c->postal_code])
            ->filter()->implode(', ');

        return [
            'id'               => $c->id,
            'name'             => $c->name,
            'recipient_phone'  => $c->recipient_phone,
            'shipping_address' => $c->shipping_address,
            'province'         => $c->province,
            'city'             => $c->city,
            'district'         => $c->district,
            'postal_code'      => $c->postal_code,
            'biteship_area_id' => $c->biteship_area_id,
            'kiriminaja_area_id' => $c->kiriminaja_area_id,
            'latitude'         => $c->latitude,
            'longitude'        => $c->longitude,
            'location_point'   => ($c->latitude !== null && $c->longitude !== null) ? ($c->latitude . ',' . $c->longitude) : '',
            'has_coordinate'   => ($c->latitude !== null && $c->longitude !== null),
            'full_address'     => $line,
            'has_area'         => !empty($c->biteship_area_id) || !empty($c->kiriminaja_area_id),
        ];
    }

    public function search(Request $request)
    {
        $q = $request->q;
        $customers = Customer::where('name', 'like', "%$q%")
            ->orWhere('code', 'like', "%$q%")
            ->limit(10)
            ->get();

        $results = $customers->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'is_marketplace' => (bool)$c->is_marketplace,
                'marketplace_hold_name' => $c->marketplace_hold_name ?: 'Overpay Customer'
            ];
        });

        return response()->json($results);
    }
}
