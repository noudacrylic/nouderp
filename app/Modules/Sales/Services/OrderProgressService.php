<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesOrder;

/**
 * Penyusun lini masa pesanan untuk halaman lacak milik pembeli.
 *
 * Semua tahapnya DITURUNKAN dari keadaan data yang memang sudah berubah sendiri
 * saat pekerjaan berjalan — pembayaran masuk, order produksi difinalisasi, surat
 * jalan terbit, resi dipindai kurir. Tidak ada satu pun tombol "tandai tahap ini"
 * di ERP, dan itu disengaja: tahap yang butuh diklik manual pasti ada saatnya lupa
 * diklik, dan lini masa yang berbohong lebih buruk daripada tahap yang tidak ada.
 *
 * Jumlah langkahnya berubah-ubah per pesanan. Barang stok siap tidak menampilkan
 * tahap Produksi sama sekali — bukan menampilkannya abu-abu — karena tahap yang
 * tergambar tapi tak pernah menyala terbaca sebagai pesanan yang nyangkut.
 */
class OrderProgressService
{
    /** Pembayaran yang belum apa-apa: satu-satunya keadaan yang menahan pesanan di tahap 1. */
    private const UNPAID = 'unpaid';

    public function for(SalesOrder $so): array
    {
        $payment = $this->payment($so);

        if ($so->status === 'void') {
            return [
                'cancelled'       => true,
                'steps'           => [],
                'current'         => null,
                'payment'         => $payment,
                'delivery_method' => $so->delivery_method,
            ];
        }

        $pickup = $so->delivery_method === 'ambil_toko';

        // Order produksi yang batal tidak dihitung: pesanan yang OP-nya dibatalkan
        // lalu diambil dari stok tidak lagi punya tahap produksi.
        $production = $so->productionOrders()
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'status']);

        $hasProduction   = $production->isNotEmpty();
        $productionDone  = $hasProduction && $production->every(fn ($p) => $p->status === 'finalized');
        $productionMoving = $hasProduction && $production->contains(fn ($p) => $p->status === 'in_progress');

        $delivery = $so->deliveries()->where('status', '!=', 'void')->latest('id')->first();
        $shipped  = (bool) ($delivery?->tracking_number) || in_array($delivery?->shipping_status, ['in_transit', 'delivered'], true);
        $arrived  = $so->getDeliveryStatus() === 'delivered';
        $pickedUp = $so->pickup_status === 'picked_up';

        // Keputusan 18 Agu 2026: DP ikut masuk tahap packing, tidak ditahan di produksi.
        // Pembeli barang custom yang melihat "Produksi" selesai lalu bar-nya diam tanpa
        // penjelasan akan mengira pesanannya terlantar; lebih baik jalan terus dengan
        // keterangan menunggu pelunasan — jujur, sekaligus penagih halus.
        $cleared = $payment['state'] !== self::UNPAID;

        $steps = [];
        $steps[] = ['key' => 'bayar', 'label' => 'Pembayaran', 'note' => $payment['note']];

        if ($hasProduction) {
            $steps[] = [
                'key'   => 'produksi',
                'label' => 'Produksi',
                'note'  => $productionDone
                    ? 'Produksi selesai'
                    : ($productionMoving ? 'Sedang diproduksi' : 'Menunggu giliran produksi'),
            ];
        }

        $steps[] = [
            'key'   => 'packing',
            'label' => $pickup ? 'Disiapkan' : 'Packing',
            'note'  => $this->packingNote($delivery, $payment, $pickup),
        ];

        $steps[] = $pickup
            ? ['key' => 'ambil', 'label' => 'Siap Diambil', 'note' => 'Pesanan bisa diambil di workshop Semarang']
            : ['key' => 'kirim', 'label' => 'Kirim', 'note' => $this->shippingNote($delivery)];

        $steps[] = ['key' => 'selesai', 'label' => 'Selesai', 'note' => $pickup ? 'Pesanan sudah diambil' : 'Pesanan sudah diterima'];

        // Tahap berjalan = tahap PERTAMA yang syaratnya belum terpenuhi.
        $current = 'bayar';
        if ($cleared) {
            $current = 'packing';
            if ($hasProduction && ! $productionDone) {
                $current = 'produksi';
            } elseif ($pickup) {
                if ($pickedUp)                          $current = 'selesai';
                elseif ($so->pickup_status === 'pending') $current = 'ambil';
            } else {
                if ($arrived)      $current = 'selesai';
                elseif ($shipped)  $current = 'kirim';
            }
        }

        $currentIndex = array_search($current, array_column($steps, 'key'), true);
        foreach ($steps as $i => &$s) {
            $s['state'] = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : 'todo');
        }
        unset($s);

        return [
            'cancelled'       => false,
            'steps'           => $steps,
            'current'         => $current,
            'payment'         => $payment,
            'delivery_method' => $so->delivery_method,
            'courier'         => $delivery?->courier_name ?: $delivery?->shipping_courier_code,
            'tracking_number' => $delivery?->tracking_number,
        ];
    }

    /**
     * Keadaan pembayaran: belum · DP · tempo · lunas.
     *
     * Tempo diperiksa SEBELUM "belum dibayar". Pesanan tempo memang belum dibayar dan
     * itu wajar — menandainya merah "belum dibayar" selama berminggu-minggu membuat
     * pembeli menelepon menanyakan sesuatu yang tidak salah.
     */
    private function payment(SalesOrder $so): array
    {
        $total = (float) $so->grand_total;
        $paid  = (float) $so->advances()->where('status', 'posted')->sum('amount');
        $lunas = $total > 0 && $paid + 0.01 >= $total;

        if ($lunas) {
            return ['state' => 'paid', 'label' => 'Lunas', 'note' => 'Pembayaran diterima',
                    'paid' => $paid, 'total' => $total, 'due_date' => null, 'days_left' => null];
        }

        if ($so->is_tempo) {
            $due  = $so->tempo_due_date ? \Illuminate\Support\Carbon::parse($so->tempo_due_date)->startOfDay() : null;
            // Hitung mundur dalam HARI, bukan jam-menit: jam mundur berdetik membuat
            // tagihan terasa seperti ancaman, padahal tempo justru fasilitas.
            $days = $due ? (int) now()->startOfDay()->diffInDays($due, false) : null;

            $note = match (true) {
                $days === null => 'Pembayaran tempo',
                $days > 1      => "Jatuh tempo {$days} hari lagi",
                $days === 1    => 'Jatuh tempo besok',
                $days === 0    => 'Jatuh tempo hari ini',
                default        => 'Lewat jatuh tempo ' . abs($days) . ' hari',
            };

            return ['state' => 'tempo', 'label' => 'Tempo', 'note' => $note,
                    'paid' => $paid, 'total' => $total,
                    'due_date' => $due?->toDateString(), 'days_left' => $days];
        }

        if ($paid > 0) {
            return ['state' => 'dp', 'label' => 'DP diterima', 'note' => 'DP diterima, sisa dilunasi sebelum dikirim',
                    'paid' => $paid, 'total' => $total, 'due_date' => null, 'days_left' => null];
        }

        return ['state' => self::UNPAID, 'label' => 'Belum dibayar', 'note' => 'Menunggu pembayaran',
                'paid' => 0.0, 'total' => $total, 'due_date' => null, 'days_left' => null];
    }

    /**
     * Tahap packing mencakup ANTREAN, bukan hanya saat barang benar-benar dikemas —
     * keputusan 18 Agu 2026. Surat jalan dipakai sekadar mempertajam keterangannya,
     * bukan menentukan tahapnya, supaya tahap ini tidak pernah bergantung pada
     * dokumen yang terbitnya bisa telat.
     */
    private function packingNote($delivery, array $payment, bool $pickup): string
    {
        if ($payment['state'] === 'dp') {
            return 'Menunggu pelunasan sebelum ' . ($pickup ? 'diserahkan' : 'dikirim');
        }

        return $delivery ? 'Sedang dikemas' : 'Masuk antrean packing';
    }

    private function shippingNote($delivery): string
    {
        if (! $delivery?->tracking_number) {
            return 'Menunggu diserahkan ke kurir';
        }

        $kurir = $delivery->courier_name ?: $delivery->shipping_courier_code;

        return trim('Dalam perjalanan' . ($kurir ? " bersama {$kurir}" : ''));
    }
}
