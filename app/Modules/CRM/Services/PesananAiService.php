<?php

namespace App\Modules\CRM\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\Services\StokTersediaService;
use App\Core\Inventory\Warehouse;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\PromotionService;
use App\Modules\Shipping\Services\OngkirEtalaseService;

/**
 * Pesanan produk READY yang disusun agen AI WhatsApp (alat `buat_pesanan`).
 *
 * Pagar di KODE, bukan di prompt (keputusan owner 16 Nov 2026): hanya produk katalog
 * ready & stok cukup, harga dari ERP (harga web + promo), ongkir ditarik ulang dari
 * provider, total maksimal Rp2 juta. Alat hanya MENYIAPKAN; SO baru dibuat saat balasan
 * benar-benar dikirim (mode Otomatis) atau saat admin klik "Buat pesanan" (mode saran),
 * dan saat itu semua pagar diperiksa ULANG — stok & tarif bisa berubah sejak disiapkan.
 * SO lahir sebagai DRAFT tanpa kunci stok, sama dengan tombol Buat SO admin (owner 22 Nov).
 */
class PesananAiService
{
    public const BATAS_TOTAL = 2000000;

    /** Shareloc pembeli yang dipakai kurir instan (sama dengan cek_ongkir). */
    public const JAM_SHARELOC = 72;

    public function __construct(
        private StokTersediaService $stok,
        private PromotionService $promosi,
        private OngkirEtalaseService $ongkir,
        private PesananChatService $pesanan,
    ) {}

    /**
     * Periksa & susun pesanan dari masukan alat.
     *
     * @return array{ok: bool, pesan: string, data?: array, ringkasan?: string, total?: float}
     */
    public function siapkan(array $input, CrmConversation $chat): array
    {
        $kirim = (string) ($input['pengiriman'] ?? '');
        if (! in_array($kirim, ['ambil', 'kurir', 'instan'], true)) {
            return $this->gagal('Pilih pengiriman: ambil (di toko), kurir, atau instan.');
        }

        $nama = trim((string) ($input['nama_pemesan'] ?? ''));
        if (! $chat->customer_id && $nama === '') {
            return $this->gagal('Nama pemesan belum ada. Tanyakan nama untuk nota dulu.');
        }

        // ---- barang: hanya katalog ready, stok cukup, harga ERP
        $barang = collect($input['barang'] ?? [])->filter(fn ($b) => is_array($b) && trim((string) ($b['sku'] ?? '')) !== '');
        if ($barang->isEmpty()) {
            return $this->gagal('Barang belum ada. Pakai SKU dari cek_stok + jumlah.');
        }
        $produk = Product::where('is_sellable', true)->where('is_active', true)
            ->whereIn('sku', $barang->pluck('sku')->map(fn ($s) => trim((string) $s))->all())
            ->get()->keyBy(fn ($p) => mb_strtolower($p->sku));
        $stok = $this->stok->untuk($produk->pluck('id'));

        $items = [];
        $promoInput = [];
        foreach ($barang as $b) {
            $p   = $produk->get(mb_strtolower(trim((string) $b['sku'])));
            $qty = max(1, (int) ($b['jumlah'] ?? 1));
            if (! $p) {
                return $this->gagal('SKU ' . $b['sku'] . ' tidak dikenal. Pakai SKU persis dari cek_stok.');
            }
            if ($p->isMadeToOrder() || $p->isPreorder()) {
                return $this->gagal($p->name . ' bukan produk ready (custom/pre-order) — tidak boleh lewat buat_pesanan, lempar ke admin.');
            }
            if ((float) ($stok[$p->id] ?? 0) < $qty) {
                return $this->gagal('Stok ' . $p->name . ' tinggal ' . (float) ($stok[$p->id] ?? 0) . ', kurang dari ' . $qty . ' — ikuti K5 (stok tidak cukup).');
            }
            $harga = (float) ($p->display_price ?? 0);
            $promoInput[] = ['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $harga];
            $items[] = ['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $harga,
                'discount_type' => 'nominal', 'discount_value' => 0, '_nama' => $p->name];
        }

        // Promo item (harga coret) → diskon per unit; promo total belanja → diskon global.
        // Sama persis dengan checkout web, supaya harga di WA tak pernah beda dengan etalase.
        $diskon = $this->promosi->resolveItemDiscounts($promoInput);
        $subtotal = 0.0;
        foreach ($items as &$it) {
            $d = $diskon[$it['product_id']] ?? null;
            if ($d) {
                $it['discount_value'] = round(((float) $d['discount_amount']) / $it['qty']);
            }
            $subtotal += $it['qty'] * ($it['unit_price'] - $it['discount_value']);
        }
        unset($it);
        $keranjang = $this->promosi->resolveCartTotalDiscount($subtotal);
        $potonganBelanja = $keranjang ? (float) min($keranjang['discount_amount'], $subtotal) : 0.0;

        $data = [
            'warehouse_id'          => Warehouse::defaultId(),
            'items'                 => array_map(fn ($i) => array_diff_key($i, ['_nama' => 1]), $items),
            'global_discount_type'  => 'nominal',
            'global_discount_value' => $potonganBelanja,
            'delivery_method'       => $kirim === 'ambil' ? 'ambil_toko' : 'kurir',
            // Barang ready wajib lunas sebelum dikirim/diambil (DP hanya untuk custom).
            'min_dp_percent'        => 100,
            'notes'                 => 'Pesanan WhatsApp (disusun AI)',
        ];
        $data += $chat->customer_id ? ['customer_id' => (int) $chat->customer_id] : ['customer_name' => $nama];

        // ---- ongkir: ditarik ulang dari provider, tak pernah dari angka yang diketik model
        $ongkirBaris = 'Pengiriman: diambil di toko';
        $ongkirBayar = 0.0;
        if ($kirim !== 'ambil') {
            $alamat = trim((string) ($input['alamat'] ?? ''));
            $layanan = trim((string) ($input['kurir'] ?? ''));
            if ($layanan === '') {
                return $this->gagal('Kurir belum dipilih. Pakai nama layanan persis dari hasil cek_ongkir.');
            }

            if ($kirim === 'instan') {
                $titik = $this->sharelocTerakhir($chat);
                if (! $titik) {
                    return $this->gagal('Belum ada shareloc pembeli — minta kirim Lokasi WhatsApp dulu.');
                }
                $w = $this->ongkir->wilayahDariTitik($titik['lat'], $titik['lng']);
                if (! $w['postal_code']) {
                    return $this->gagal('Lokasi pembeli belum dikenali kurir — lempar ke admin gudang.');
                }
                $tujuan = ['mode' => 'instant', 'destination_area_id' => $w['area_id'], 'destination_postal_code' => $w['postal_code'],
                    'destination_latitude' => $titik['lat'], 'destination_longitude' => $titik['lng']];
                $alamat = $alamat !== '' ? $alamat : (string) ($w['address'] ?? '');
                $area = array_filter(['postal_code' => $w['postal_code'], 'jubelio_area_id' => $w['area_id'],
                    'latitude' => $titik['lat'], 'longitude' => $titik['lng']], fn ($v) => $v !== null && $v !== '');
            } else {
                $wilayah = $this->ongkir->cariWilayahLonggar((string) ($input['tujuan'] ?? ''));
                if (! $wilayah) {
                    return $this->gagal('Wilayah tujuan tidak ditemukan. Tanyakan kecamatan + kota (atau kode pos).');
                }
                $a = $wilayah[0];
                $tujuan = ['destination_area_id' => $a['id'] ?? null, 'destination_postal_code' => $a['postal_code'] ?? null];
                $area = array_filter(['district' => $a['district'] ?? null, 'city' => $a['city'] ?? null, 'province' => $a['province'] ?? null,
                    'postal_code' => $a['postal_code'] ?? null, 'jubelio_area_id' => $a['id'] ?? null], fn ($v) => $v !== null && $v !== '');
            }
            if (mb_strlen($alamat) < 10) {
                return $this->gagal('Alamat lengkap (jalan, nomor rumah, RT/RW) belum ada. Tanyakan dulu.');
            }

            $tarif = $this->ongkir->tarif($tujuan, $items);
            $pilih = collect($tarif['rates'])->first(fn ($r) => mb_strtolower(OngkirEtalaseService::namaLayanan($r)) === mb_strtolower($layanan))
                ?? collect($tarif['rates'])->first(fn ($r) => str_contains(mb_strtolower(OngkirEtalaseService::namaLayanan($r)), mb_strtolower($layanan)));
            if (! $pilih) {
                $ada = collect($tarif['rates'])->sortBy('price')->take(6)->map(fn ($r) => OngkirEtalaseService::namaLayanan($r))->implode(', ');

                return $this->gagal('Kurir "' . $layanan . '" tidak tersedia ke tujuan ini' . ($ada ? '. Yang tersedia: ' . $ada : '') . '. Tanyakan pilihan pembeli lagi.');
            }

            $gross = (float) $pilih['price'];
            $pot   = (float) ($pilih['discount'] ?? 0);
            $data += [
                'shipping_gross'          => $gross,
                'shipping_discount_type'  => 'nominal',
                'shipping_discount_value' => $pot,
                'courier_name'            => OngkirEtalaseService::namaLayanan($pilih),
                'shipping_provider'       => $pilih['provider'] ?? $this->ongkir->providerKey(),
                'shipping_courier_code'   => $pilih['courier_code'] ?? null,
                'shipping_service_code'   => $pilih['service_code'] ?? null,
                'shipping_address'        => $alamat,
                'shipping_area'           => $area,
            ];
            $ongkirBayar = $gross - $pot;
            $ongkirBaris = 'Ongkir ' . OngkirEtalaseService::namaLayanan($pilih) . ': ' . $this->rp($gross)
                . ($pot > 0 ? ' − potongan ongkir ' . $this->rp($pot) . ' = ' . $this->rp($ongkirBayar) : '')
                . ' | alamat: ' . $alamat;
        }

        $total = $subtotal - $potonganBelanja + $ongkirBayar;
        if ($total > self::BATAS_TOTAL) {
            return $this->gagal('Total ' . $this->rp($total) . ' melebihi batas pesanan AI ' . $this->rp(self::BATAS_TOTAL) . ' — lempar ke admin keuangan.');
        }

        $baris = array_map(fn ($i) => '- ' . $i['_nama'] . ' × ' . $i['qty'] . ' @ ' . $this->rp($i['unit_price'] - $i['discount_value'])
            . ' = ' . $this->rp($i['qty'] * ($i['unit_price'] - $i['discount_value'])), $items);
        $ringkasan = implode("\n", $baris)
            . ($potonganBelanja > 0 ? "\nPotongan belanja: −" . $this->rp($potonganBelanja) : '')
            . "\n" . $ongkirBaris
            . "\nTotal: " . $this->rp($total)
            . ($chat->customer_id ? '' : "\nAtas nama: " . $nama);

        return ['ok' => true, 'pesan' => $ringkasan, 'data' => $data, 'ringkasan' => $ringkasan, 'total' => $total];
    }

    /**
     * Wujudkan pesanan yang disiapkan sebuah saran: periksa ulang, buat SO, kembalikan
     * rincian + tautan bayar. Sekali saja per saran — klik kedua memakai SO yang sama.
     *
     * @return array{teks: string, url: ?string, so: SalesOrder}
     *
     * @throws \RuntimeException pesan siap tampil bila pagar gagal saat diperiksa ulang
     */
    public function wujudkan(CrmSaranAi $saran, ?int $userId = null): array
    {
        $chat = $saran->conversation;
        if (! $chat || ! $saran->pesanan) {
            throw new \RuntimeException('Saran ini tidak membawa pesanan.');
        }

        $so = $saran->pesanan_so_id ? SalesOrder::find($saran->pesanan_so_id) : null;
        if (! $so) {
            $siap = $this->siapkan((array) $saran->pesanan, $chat);
            if (! $siap['ok']) {
                throw new \RuntimeException('Pesanan tidak bisa dibuat: ' . $siap['pesan']);
            }
            $so = $this->pesanan->buat($chat, $siap['data'], $userId);
            $saran->forceFill(['pesanan_so_id' => $so->id])->save();
        }

        return $this->pesanan->rincian($so, $userId) + ['so' => $so];
    }

    /** @return array{lat: float, lng: float}|null shareloc terbaru pembeli */
    public function sharelocTerakhir(CrmConversation $chat): ?array
    {
        return CrmMessage::where('conversation_id', $chat->id)
            ->where('direction', CrmMessage::MASUK)
            ->where('sent_at', '>=', now()->subHours(self::JAM_SHARELOC))
            ->latest('sent_at')->limit(50)->get()
            ->map(fn ($m) => $m->lokasi())->filter()->first();
    }

    private function gagal(string $pesan): array
    {
        return ['ok' => false, 'pesan' => $pesan];
    }

    private function rp(float $n): string
    {
        return 'Rp' . number_format($n, 0, ',', '.');
    }
}
