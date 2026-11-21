<?php

namespace App\Modules\CRM\Services;

use App\Models\Customer;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Payment\Services\PaymentLinkService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Support\Str;

/**
 * SO dari chat + rincian/tautan bayarnya — satu jalur untuk tombol "Buat SO" admin di
 * Inbox dan alat `buat_pesanan` agen AI WhatsApp (keputusan owner 16 Nov 2026: logika
 * Buat SO Inbox dipindah ke service, dipakai admin & AI).
 */
class PesananChatService
{
    public function __construct(
        private SalesOrderService $salesOrder,
        private PaymentLinkService $links,
    ) {}

    /**
     * Buat SO draft dari data keranjang yang SUDAH divalidasi.
     *
     * @throws \RuntimeException pesan siap tampil (alamat kurang, pelanggan kosong, dll)
     */
    public function buat(CrmConversation $conversation, array $data, ?int $userId = null): SalesOrder
    {
        if ($galat = $this->alamatKirimKurang($data)) {
            throw new \RuntimeException($galat);
        }

        $customerId = $this->pelangganUntukSo($conversation, $data);

        if (($data['delivery_method'] ?? 'kurir') !== 'ambil_toko') {
            $this->simpanAlamatPelanggan($customerId, $conversation, $data);
        }

        $so = $this->salesOrder->createDraftFromData($data + [
            'customer_id' => $customerId,
            'order_date'  => now()->toDateString(),
        ]);

        /*
         * Kesepakatan dagang (batas DP, keep stock, tempo) disetel setelahnya:
         * createDraftFromData dipakai juga oleh jalur marketplace yang tak punya
         * konsep ini, dan menyelipkannya ke sana akan memaksa mereka ikut
         * memikirkan medan yang tidak pernah mereka isi.
         */
        $kesepakatan = array_filter([
            'min_dp_percent'  => $data['min_dp_percent'] ?? null,
            'allow_backorder' => isset($data['allow_backorder']) ? (bool) $data['allow_backorder'] : null,
            'is_tempo'        => isset($data['is_tempo']) ? (bool) $data['is_tempo'] : null,
            // Termin boleh kosong: tempo tanpa batas waktu tetap sah, yang hilang
            // cuma peringatan jatuh temponya.
            'tempo_days'      => $data['tempo_days'] ?? null,
            // Gambar cetak dibuat Admin Desain → kartu di meja Desain setelah pembeli membayar.
            'desain_gambar'   => ! empty($data['desain_gambar']) ? true : null,
            'desain_brief'    => ! empty($data['desain_gambar']) ? (trim((string) ($data['desain_brief'] ?? '')) ?: null) : null,
        ], fn ($v) => $v !== null);

        if ($kesepakatan) {
            $so->forceFill($kesepakatan)->save();
        }

        // CRM Tahap 3: SO yang lahir dari chat langsung tertaut ke chat ini,
        // dan labelnya ikut bergerak (draft → Menunggu Pembayaran).
        app(LabelPesananService::class)->tautkan($conversation, $so, $userId);

        return $so;
    }

    /**
     * Rincian pesanan siap kirim: daftar barang, ongkir, total, plus tautan bayar.
     *
     * Kalimat pembayarannya diambil dari PaymentLinkService — sumber yang sama
     * dengan modal "Link Bayar" di halaman SO. Menulis versi sendiri di sini
     * berarti ada dua tempat yang masing-masing berjanji kepada pelanggan soal
     * masa berlaku tautan, dan keduanya akan cepat berbeda isi.
     *
     * @return array{teks: string, url: ?string}
     */
    public function rincian(SalesOrder $order, ?int $userId = null): array
    {
        $order->load('items.product');

        $baris = ['Berikut rincian pesanan ' . $order->order_number . ':', ''];

        foreach ($order->items as $item) {
            // description didahulukan: barang custom disepakati lewat kalimat di
            // chat, dan nama master produknya sering terlalu umum untuk dikenali
            // pembeli ("Akrilik 3mm" untuk sesuatu yang ia sebut "box mahar").
            $nama = $item->description ?: ($item->product?->name ?? 'Produk');

            $baris[] = '• ' . $nama . ' — ' . rtrim(rtrim(number_format((float) $item->qty, 2, ',', '.'), '0'), ',')
                . ' × ' . $this->rupiah($item->unit_price)
                . ' = ' . $this->rupiah($item->line_total);
        }

        $baris[] = '';

        if ((float) $order->discount_total > 0 || (float) $order->global_discount_amount > 0) {
            $baris[] = 'Diskon: −' . $this->rupiah((float) $order->discount_total + (float) $order->global_discount_amount);
        }

        if ((float) $order->shipping_cost > 0) {
            $baris[] = 'Pengiriman' . ($order->shipping_service_name ? ' (' . $order->shipping_service_name . ')' : '')
                . ': ' . $this->rupiah($order->shipping_cost);
        } elseif ($order->delivery_method === 'ambil_toko') {
            $baris[] = 'Pengiriman: diambil di toko';
        }

        $baris[] = 'Total: ' . $this->rupiah($order->grand_total);

        $sisa = (int) round($order->sisaTagihan());

        /*
         * Tautan bayar hanya dibuat kalau memang masih ada yang harus dibayar.
         * Mengirim tautan untuk pesanan lunas membuat pelanggan mengira ada
         * tagihan kedua — dan itu jenis kebingungan yang berujung telepon.
         */
        if ($sisa <= 0) {
            $baris[] = '';
            $baris[] = 'Pesanan ini sudah lunas. Terima kasih!';

            return ['teks' => implode("\n", $baris), 'url' => null];
        }

        $trx = $this->links->getOrCreateForSalesOrder($order, $userId, $order->minDpAmount());

        return [
            'teks' => $this->links->waTextSo($trx, $order->customer?->name ?? 'Kak', $order->order_number, $sisa, $baris),
            'url'  => $this->links->publicUrl($trx),
        ];
    }

    /**
     * Pelanggan untuk SO dari chat.
     *
     * Percakapan yang belum tertaut master (Lead) adalah keadaan NORMAL, bukan
     * kesalahan — kebanyakan pesanan lahir dari nomor asing. Jadi di sini
     * pelanggan baru boleh dibuat, TAPI hanya dari nama yang diketik operator:
     * membuatnya diam-diam dari tiap chat akan menyampahi master pelanggan
     * dengan nomor yang tidak pernah jadi pesanan.
     */
    public function pelangganUntukSo(CrmConversation $conversation, array $data): int
    {
        if (! empty($data['customer_id'])) {
            $id = (int) $data['customer_id'];
        } else {
            $nama = trim((string) ($data['customer_name'] ?? ''));

            if ($nama === '') {
                throw new \RuntimeException('Pilih pelanggan, atau isi nama untuk membuat pelanggan baru.');
            }

            $id = (int) Customer::create([
                'code'      => 'CUST-' . strtoupper(Str::random(6)),
                'name'      => $nama,
                'phone'     => $conversation->contact_key,
                'wa_opt_in' => true,
                'is_active' => true,
            ])->penampung()->id;   // kembar nomor + nama → pelanggan lama
        }

        // Percakapan ikut ditautkan: sekali pesan, chat berikutnya dari nomor itu
        // langsung mendarat pada pelanggan yang sama beserta riwayat pesanannya.
        if (! $conversation->customer_id) {
            $conversation->forceFill(['customer_id' => $id])->save();
        }

        return $id;
    }

    /**
     * Pesanan yang DIKIRIM wajib punya alamat jalan: tanpa itu resi tak bisa
     * dipesan, dan kekurangannya baru ketahuan saat barang mau keluar.
     *
     * Kosong masih boleh bila pelanggannya sudah punya alamat tersimpan —
     * alamat itu yang dipakai, dan memang tidak ditimpa oleh isian kosong.
     * Diperiksa SEBELUM pelanggan baru dibuat, supaya penolakan tidak
     * meninggalkan pelanggan yatim.
     */
    public function alamatKirimKurang(array $data): ?string
    {
        if (($data['delivery_method'] ?? 'kurir') === 'ambil_toko'
            || trim((string) ($data['shipping_address'] ?? '')) !== '') {
            return null;
        }

        $tersimpan = ! empty($data['customer_id'])
            ? Customer::whereKey((int) $data['customer_id'])->value('shipping_address')
            : null;

        return filled($tersimpan) ? null : 'Alamat lengkap wajib diisi untuk pesanan yang dikirim.';
    }

    /**
     * Tulis alamat pengiriman dari layar chat ke pelanggan.
     *
     * Hanya medan yang BERISI yang ditulis: SO kedua dari chat yang sama sering
     * dibuat tanpa mencari wilayah lagi, dan itu tidak boleh menghapus kecamatan
     * & id area yang sudah benar dari pesanan sebelumnya.
     */
    public function simpanAlamatPelanggan(int $customerId, CrmConversation $conversation, array $data): void
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return;
        }

        $ubah = array_filter(
            ['shipping_address' => trim((string) ($data['shipping_address'] ?? ''))]
                + array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) ($data['shipping_area'] ?? [])),
            fn ($v) => $v !== null && $v !== ''
        );

        // Nomor chat = nomor penerima yang paling mungkin benar, dan kurir wajib
        // punya nomor. Hanya bila belum ada — nomor yang sudah diisi orang menang.
        if (blank($customer->recipient_phone) && $conversation->channel === 'whatsapp') {
            $ubah['recipient_phone'] = $conversation->contact_key;
        }

        if ($ubah) {
            $customer->forceFill($ubah)->save();
        }
    }

    private function rupiah(float|int|string $n): string
    {
        return 'Rp ' . number_format((float) $n, 0, ',', '.');
    }
}
