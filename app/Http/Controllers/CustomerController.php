<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\CustomerBranch;

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

        $customer = Customer::create($data);
        $customer->catatKeberatan($request->boolean('wa_opt_out'));
        $this->simpanNomorNotifikasi($customer, $request);

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

    /**
     * Nomor tambahan yang ikut dikabari — ditulis ulang seluruhnya tiap simpan.
     *
     * Barisnya cuma label + nomor, tidak dirujuk dokumen mana pun, jadi tak ada
     * yang hilang dengan menghapus lalu menulis ulang; memasangkan baris lama
     * dengan baris form cuma menambah kerumitan tanpa ada yang dijaga.
     * Baris bernomor kosong dibuang diam-diam: itu baris yang ditambahkan lalu
     * tidak jadi diisi, bukan kesalahan yang perlu dilaporkan.
     */
    private function simpanNomorNotifikasi(Customer $customer, Request $request): void
    {
        $data = $request->validate([
            'nomor_notifikasi'           => 'nullable|array|max:10',
            'nomor_notifikasi.*.label'   => 'nullable|string|max:100',
            'nomor_notifikasi.*.phone'   => 'nullable|string|max:30',
        ]);

        $customer->notificationPhones()->delete();

        foreach ($data['nomor_notifikasi'] ?? [] as $baris) {
            $nomor = trim((string) ($baris['phone'] ?? ''));

            if ($nomor === '') {
                continue;
            }

            $customer->notificationPhones()->create([
                'label' => trim((string) ($baris['label'] ?? '')) ?: null,
                'phone' => $nomor,
            ]);
        }
    }

    public function edit($id)
    {
        $customer = Customer::with('notificationPhones')->findOrFail($id);

        return view('erp.master.customers.edit', compact('customer'));
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $customer->update($this->customerFormData($request));
        $customer->catatKeberatan($request->boolean('wa_opt_out'));
        $this->simpanNomorNotifikasi($customer, $request);

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

        $phone = trim((string) ($data['phone'] ?? '')) ?: null;

        // Kode berbasis detik bentrok bila dua pelanggan dibuat di detik yang sama
        // (kolomnya unik → simpan gagal 500). Ekor acak hanya dipasang saat bentrok,
        // jadi bentuk kode yang biasa tidak berubah.
        $code = 'CUST-' . time();
        while (Customer::where('code', $code)->exists()) {
            $code = 'CUST-' . time() . '-' . strtoupper(\Illuminate\Support\Str::random(3));
        }

        $customer = Customer::create([
            'name'          => $data['name'],
            'phone'         => $phone,
            // Ikut jadi No. HP penerima: kartu Pengiriman membacanya dari sini, dan
            // pelanggan baru hampir selalu menerima paketnya sendiri.
            'recipient_phone' => $phone,
            'address'       => $data['address'] ?? null,
            'code'          => $code,
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
    /**
     * Info alamat pengiriman (untuk panel Pengiriman di SO/Invoice).
     *
     * `?branch=` menentukan alamat SIAPA yang dibaca. Tanpa itu panel selalu
     * menunjukkan alamat pusat, dan ongkir pesanan untuk cabang dihitung ke
     * kota yang salah tanpa gejala apa pun.
     */
    public function shippingInfo(Request $request, $id)
    {
        Customer::findOrFail($id);

        return response()->json($this->shippingPayload(
            CustomerBranch::tujuanUntuk($id, $request->input('branch'))
        ));
    }

    /**
     * Simpan/ubah alamat pengiriman dari popup "Edit/Tambah Alamat".
     *
     * Bila sebuah cabang sedang dipilih, yang disunting adalah alamat CABANG.
     * Tanpa pembedaan ini, membetulkan alamat dari form SO untuk pesanan cabang
     * akan diam-diam menimpa alamat kantor pusat — dan pesanan berikutnya untuk
     * pusat berangkat ke kota cabang.
     */
    public function updateShipping(Request $request, $id)
    {
        $c = Customer::findOrFail($id);
        $cabang = $request->filled('branch')
            ? CustomerBranch::where('id', $request->input('branch'))->where('customer_id', $c->id)->first()
            : null;

        $data = $request->validate([
            'recipient_phone'  => 'nullable|string|max:30',
            'shipping_address' => 'nullable|string|max:2000',
            'province'         => 'nullable|string|max:100',
            'city'             => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'postal_code'      => 'nullable|string|max:10',
            'biteship_area_id' => 'nullable|string|max:100',
            'kiriminaja_area_id' => 'nullable|string|max:100',
            // Kartu Pengiriman sudah lama mengirimnya, tapi tanpa baris ini ia
            // dibuang validate() — dan pemesanan resi Jubelio membacanya.
            'jubelio_area_id'  => 'nullable|string|max:100',
            // Titik lokasi (untuk kurir instant) — boleh link Google Maps atau "lat,long".
            'location_point'   => 'nullable|string|max:500',
        ]);

        $point = parse_lat_long($data['location_point'] ?? null);
        unset($data['location_point']);
        $data['latitude']  = $point['latitude'];
        $data['longitude'] = $point['longitude'];

        $sasaran = $cabang ?: $c;
        $sasaran->update($data);

        return response()->json($this->shippingPayload($sasaran->fresh()));
    }

    /**
     * Bentuk alamat untuk panel Pengiriman.
     *
     * Menerima Customer maupun CustomerBranch: nama kolomnya memang sengaja
     * dibuat sama persis, jadi satu perakit cukup untuk keduanya.
     */
    private function shippingPayload($c): array
    {
        if ($c instanceof Customer) {
            $c = $c->sebagaiCabang();
        }

        $line = collect([$c->shipping_address, $c->district, $c->city, $c->province, $c->postal_code])
            ->filter()->implode(', ');

        return [
            'id'               => $c->customer_id ?? $c->id,
            'branch_id'        => $c->exists ? $c->id : null,
            'name'             => $c->name,
            'recipient_phone'  => $c->recipient_phone,
            'shipping_address' => $c->shipping_address,
            'province'         => $c->province,
            'city'             => $c->city,
            'district'         => $c->district,
            'postal_code'      => $c->postal_code,
            'biteship_area_id' => $c->biteship_area_id,
            'kiriminaja_area_id' => $c->kiriminaja_area_id,
            'jubelio_area_id'  => $c->jubelio_area_id,
            'latitude'         => $c->latitude,
            'longitude'        => $c->longitude,
            'location_point'   => ($c->latitude !== null && $c->longitude !== null) ? ($c->latitude . ',' . $c->longitude) : '',
            'has_coordinate'   => ($c->latitude !== null && $c->longitude !== null),
            'full_address'     => $line,
            'has_area'         => !empty($c->biteship_area_id) || !empty($c->kiriminaja_area_id) || !empty($c->jubelio_area_id),
        ];
    }

    /**
     * Satu baris hasil pencarian — induk, atau salah satu cabangnya.
     *
     * `id` SELALU id pelanggan induk, juga untuk baris cabang. Itu yang membuat
     * piutang tidak pernah terbelah: dokumen tetap menempel ke induk, dan cabang
     * hanya menambah `branch_id` di sampingnya. Bonusnya, setiap pemakai lama
     * kotak cari ini tetap bekerja tanpa diubah — yang belum tahu soal cabang
     * cukup mengabaikan satu field baru.
     */
    private function barisPicker(Customer $c, ?CustomerBranch $cabang = null): array
    {
        $nomor = $cabang ? ($cabang->phone ?: $c->phone) : $c->phone;
        $nama  = $cabang ? $cabang->name : $c->name;
        $ekor  = trim((string) $nomor) ?: trim((string) $c->code);

        return [
            'id'                    => $c->id,
            'branch_id'             => $cabang?->id,
            'name'                  => $nama,
            'code'                  => $c->code,
            'label'                 => $ekor !== '' ? $nama . ' · ' . $ekor : $nama,
            'phone'                 => $nomor,
            'is_marketplace'        => (bool) $c->is_marketplace,
            'marketplace_hold_name' => $c->marketplace_hold_name ?: 'Overpay Customer',
        ];
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->q);

        // Kurungnya WAJIB: tanpa itu `is_active` cuma menempel pada cabang nama, dan
        // pelanggan arsip tetap bocor lewat pencarian kode.
        $customers = Customer::aktif()
            ->with(['branches' => fn ($b) => $b->aktif()])
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('code', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        /*
         * Cabang yang namanya cocok walau induknya tidak.
         *
         * Orang mencari "Bandung", bukan "PT Sumber Jaya" — justru nama cabang
         * yang diingat saat menyiapkan pesanan untuk cabang. Tanpa ini, cabang
         * hanya bisa ditemukan oleh yang sudah hafal nama induknya.
         */
        $cabangLepas = CustomerBranch::aktif()
            ->where('name', 'like', "%{$q}%")
            ->whereHas('customer', fn ($w) => $w->where('is_active', true))
            ->with('customer')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->reject(fn ($b) => $customers->contains('id', $b->customer_id));

        $results = collect();

        foreach ($customers as $c) {
            $results->push($this->barisPicker($c));

            // Cabang ditaruh tepat di bawah induknya, bukan di daftar terpisah:
            // yang dipilih orang adalah "Sumber Jaya yang mana", satu keputusan.
            foreach ($c->branches as $cabang) {
                $results->push($this->barisPicker($c, $cabang));
            }
        }

        foreach ($cabangLepas as $cabang) {
            $results->push($this->barisPicker($cabang->customer, $cabang));
        }

        return response()->json($results->values());
    }
}
