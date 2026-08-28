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
        $data = $this->customerFormData($request);
        $data['code'] = 'CUST-' . time();
        $data['customer_type'] = $request->customer_type ?? 'regular';
        $data['is_active'] = true;

        Customer::create($data);

        return redirect(list_url('customers.index'));
    }

    /**
     * Data dari form master Customer (create/edit), termasuk alamat pengiriman
     * (kolom yang sama dengan popup "Edit/Tambah Alamat" di SO/Invoice).
     */
    private function customerFormData(Request $request): array
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'address'            => 'nullable|string|max:2000',
            'customer_type'      => 'nullable|string|max:50',
            // Alamat pengiriman
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

        // Titik lokasi (link Google Maps atau "lat,long") → latitude/longitude.
        $point = parse_lat_long($data['location_point'] ?? null);
        unset($data['location_point']);
        $data['latitude']  = $point['latitude'];
        $data['longitude'] = $point['longitude'];

        return $data;
    }

    public function edit($id)
    {
        $customer = Customer::findOrFail($id);

        return view('erp.master.customers.edit', compact('customer'));
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $customer->update($this->customerFormData($request));

        return redirect(list_url('customers.index'));
    }

    /**
     * Pelanggan aktif yang namanya sama dengan $nama setelah dirapikan.
     *
     * "Sama" di sini termasuk beda huruf besar-kecil dan beda jumlah spasi
     * ("Dhita  Maharani" vs "Dhita Maharani") — justru bentuk itu yang paling sering
     * lolos jadi kembar tanpa sadar. LIKE-nya sengaja longgar (superset) dan
     * pencocokan tepatnya dikerjakan di PHP, supaya tidak bergantung pada fungsi
     * regex basis data maupun collation-nya.
     */
    private function namaKembar(string $nama)
    {
        $rapi = $this->rapikanNama($nama);
        if ($rapi === '') {
            return collect();
        }

        $pola = str_replace(' ', '%', addcslashes($rapi, '%_\\'));

        return Customer::aktif()
            ->where('name', 'like', $pola)
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn ($c) => $this->rapikanNama((string) $c->name) === $rapi)
            ->take(5)
            ->values();
    }

    /** Huruf kecil, tanpa spasi berlebih di ujung maupun di tengah. */
    private function rapikanNama(string $nama): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nama)));
    }

    public function storeAjax(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required|string|max:200',
            'phone'   => 'nullable|string|max:30',
            'address' => 'nullable|string|max:2000',
        ]);

        // Nama kembar dihadang, bukan dilarang: dua orang boleh saja benar-benar
        // bernama sama. Yang dicegah adalah kembar TANPA SADAR — karena itu daftar
        // yang sudah ada dikembalikan dulu, dan penyimpanan baru jalan kalau penggunanya
        // menegaskan lewat `force`.
        if (! $request->boolean('force')) {
            $kembar = $this->namaKembar($data['name']);

            if ($kembar->isNotEmpty()) {
                return response()->json([
                    'duplicate' => true,
                    'message' => 'Nama ini sudah ada.',
                    'existing' => $kembar->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'code' => $c->code,
                        'label' => $c->picker_label,
                        'phone' => $c->phone,
                    ])->values(),
                ], 409);
            }
        }

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
            'code'    => $customer->code,
            'label'   => $customer->picker_label,
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
        $q = trim((string) $request->q);

        // Kurungnya WAJIB: tanpa itu `is_active` cuma menempel pada cabang nama, dan
        // pelanggan arsip tetap bocor lewat pencarian kode.
        $customers = Customer::aktif()
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('code', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        $results = $customers->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'label' => $c->picker_label,
                'phone' => $c->phone,
                'is_marketplace' => (bool)$c->is_marketplace,
                'marketplace_hold_name' => $c->marketplace_hold_name ?: 'Overpay Customer'
            ];
        });

        return response()->json($results);
    }
}
