<?php

namespace App\Modules\CRM\Support;

use App\Modules\CRM\Models\CrmOutboxMessage;

/**
 * Katalog jenis notifikasi pesanan: apa saja yang dikirim ERP ke pelanggan,
 * kapan dipicu, bunyi kalimatnya, dan boleh-tidaknya berangkat.
 *
 * Ada karena tiga pertanyaan ini selalu muncul dan sebelumnya hanya bisa
 * dijawab dengan membaca kode: "notifikasinya apa saja?", "bunyinya bagaimana?",
 * "yang ini aktif tidak?". Selama jawabannya tersembunyi di kode, tak ada yang
 * berani memastikan apa yang sebenarnya diterima pelanggan.
 *
 * Teksnya TIDAK ditulis ulang di sini — dirangkai dari TemplateResmi, sumber
 * yang sama dengan yang benar-benar dikirim. Kalau ditulis ulang, contoh di
 * layar dan kalimat sungguhan akan berbeda pelan-pelan tanpa ada yang sadar,
 * dan layar ini justru jadi sumber keyakinan yang keliru.
 *
 * Menambah jenis BERBAYAR berarti mengajukan template baru ke Meta, dan
 * mengirim terlalu sering mengundang blokir — karena itu yang berbayar tetap
 * tiga. Yang menyusul justru karena TIDAK berbayar: "Stok Sudah Ada" lewat
 * WAHA saja atas permintaan pelanggan sendiri (TemplateResmi::hanyaWaha()),
 * dan "Pancingan" yang gratis selama jendelanya masih terbuka — templatenya
 * hanya dipakai bila pancingan gratisnya terlewat.
 *
 * Pengajuan template ke Meta dilakukan DARI SINI, bukan dari layar Template
 * Pesan. Layar itu untuk kalimat yang dipilih manusia; yang di sini dikirim
 * kode, dan bunyinya memang tidak boleh disunting dari layar mana pun —
 * satu bunyi berangkat lewat dua jalur (WAHA & resmi) dan wajib sama persis.
 */
class JenisNotifikasi
{
    public const DAFTAR = [
        CrmOutboxMessage::EVENT_PEMBAYARAN => [
            'label'  => 'Pembayaran Diterima',
            'pemicu' => 'Otomatis begitu pembayaran pelanggan diposting — DP maupun pelunasan. Satu pembayaran, satu pesan.',
            'contoh' => ['Budi', '150.000', 'SO-2609-0012', 'DP diterima, sisa 350.000'],
        ],
        CrmOutboxMessage::EVENT_SIAP_AMBIL => [
            'label'  => 'Siap Diambil',
            'pemicu' => 'Saat pesanan ambil-di-toko selesai dikerjakan. Satu-satunya yang menunggu jam buka — pesan ini mengajak orang datang.',
            'contoh' => ['Budi', 'SO-2609-0012', 'AMB-4471', 'Senin–Sabtu 08.00–16.00'],
        ],
        CrmOutboxMessage::EVENT_DIKIRIM => [
            'label'  => 'Pesanan Dikirim',
            'pemicu' => 'Saat surat jalan sudah punya nomor resi. Tanpa resi, tidak dikirim — tak ada yang bisa dilacak.',
            'contoh' => ['Budi', 'SO-2609-0012', 'JNE', 'JX1234567890'],
        ],
        CrmOutboxMessage::EVENT_JATUH_TEMPO => [
            'label'  => 'Jatuh Tempo',
            'pemicu' => 'Pesanan tempo yang belum lunas: 3 hari sebelum jatuh tempo, lalu pada hari-H. '
                      . 'Satu-satunya jenis yang WAJIB lewat jalur resmi berbayar, apa pun driver yang dipilih.',
            'contoh' => ['Budi', 'SO-2609-0012', '500.000', '12 Oktober 2026', '08998844666'],
        ],
        CrmOutboxMessage::EVENT_TAGIHAN => [
            'label'  => 'Tagihan Pembayaran',
            'pemicu' => 'Pesanan non-marketplace yang tautan bayarnya sudah dibuat tapi belum dibayar: '
                      . 'hari ke-1, 2, 3, lalu tiap minggu. Lewat 4 minggu, pesanannya dibatalkan otomatis.',
            'contoh' => [
                'Budi',
                'SO-2609-0012',
                '500.000',
                'https://noudakrilik.com/pay/contoh-token',
                '7 Oktober 2026',
                '08998844666',
            ],
        ],
        CrmOutboxMessage::EVENT_PANCINGAN => [
            'label'  => 'Pancingan Sebelum Sesi Habis',
            'pemicu' => 'Sekitar sejam sebelum jendela 24 jam sebuah percakapan habis, '
                      . 'bila pembahasannya masih hidup (belum diarsipkan, sudah dua arah). '
                      . 'Pesan bertombol: sekali ditekan pelanggan, jendelanya diperbarui — '
                      . 'dan selama jendelanya belum tutup, pesan ini GRATIS. '
                      . 'Jendela yang habis tengah malam dipancing lebih awal, pada jam sopan terakhir.',
            'contoh' => ['Kak Budi', 'Senin–Sabtu 08.00–16.00'],
        ],
        CrmOutboxMessage::EVENT_STOK_TERSEDIA => [
            'label'  => 'Stok Sudah Ada',
            'pemicu' => 'Saat stok siap sebuah SKU mencukupi titipan "kabari kalau ada" yang ditandai admin dari panel Produk. Sekali kirim, lalu tandanya lepas sendiri.',
            'contoh' => [
                'Budi',
                'Akrilik Frame Poster A3',
                'https://noudakrilik.com/produk/akrilik-frame-poster-a3',
                '08998844666',
            ],
        ],
    ];

    /**
     * Jenis ini boleh berangkat? Dimatikan = barisnya tetap dibuat dengan
     * status 'dilewati' beserta alasannya, bukan hilang tanpa jejak — supaya
     * "kenapa pelanggan ini tidak dapat kabar?" tetap ada jawabannya.
     */
    public static function aktif(string $event): bool
    {
        $peta = (array) config('crm.notifikasi.aktif', []);

        // Belum pernah diatur = aktif. Bawaan yang mendiamkan notifikasi
        // adalah bawaan yang berbahaya: pelanggan berhenti dapat kabar dan
        // tidak ada gejalanya sama sekali.
        return (bool) ($peta[$event] ?? true);
    }

    /**
     * Bunyi yang BIASANYA diterima pelanggan untuk satu jenis.
     *
     * Hampir selalu sama dengan bunyi templatenya. Pancingan satu-satunya yang
     * berbeda, dan bedanya penting untuk ditampilkan apa adanya: yang biasanya
     * berangkat adalah versi sesi yang GRATIS, sedangkan templatenya cuma
     * cadangan saat jendelanya terlanjur tutup. Menampilkan bunyi template di
     * sini akan membuat layar ini memperlihatkan kalimat yang justru paling
     * jarang benar-benar dikirim.
     */
    private static function bunyi(string $event, string $template, array $contoh): ?string
    {
        if ($event === CrmOutboxMessage::EVENT_PANCINGAN) {
            return TemplateResmi::bunyiPancinganSesi(...array_pad(array_slice($contoh, 0, 2), 2, ''));
        }

        return TemplateResmi::render(
            $template,
            $contoh,
            rtrim((string) config('crm.storefront_url'), '/') . '/pesanan/contoh-token'
        );
    }

    public static function label(string $event): string
    {
        return self::DAFTAR[$event]['label'] ?? $event;
    }

    /**
     * Katalog siap tampil: label, pemicu, teks contoh, status aktif, status Meta.
     *
     * @param array<string, array<string,int>> $hitungan   [event][status] => jumlah
     * @param array<string, string>            $statusMeta [nama template] => PENDING|APPROVED|REJECTED
     */
    public static function katalog(array $hitungan = [], array $statusMeta = []): array
    {
        $hasil = [];

        foreach (self::DAFTAR as $event => $meta) {
            $template = CrmOutboxMessage::TEMPLATES[$event] ?? '';

            $hasil[] = [
                'event'    => $event,
                'label'    => $meta['label'],
                'pemicu'   => $meta['pemicu'],
                'template' => $template,
                'teks'     => self::bunyi($event, $template, $meta['contoh']),
                /*
                 * Yang khusus WAHA TIDAK punya baris Meta sama sekali, dan itu
                 * bukan kelalaian: nadanya mengingatkan & menawarkan, jadi Meta
                 * akan menggolongkannya MARKETING — dan penggolongan itu
                 * menempel pada NOMORNYA, bukan pada satu template. Menampilkan
                 * tombol "Ajukan" untuk keduanya berarti menyediakan satu klik
                 * yang merugikan seluruh jalur resmi.
                 */
                'ke_meta'     => ! TemplateResmi::hanyaWaha($template),
                'status_meta' => $statusMeta[$template] ?? null,
                'wajib_resmi' => TemplateResmi::wajibResmi($template),
                'aktif'    => self::aktif($event),
                'hitungan' => [
                    'terkirim' => $hitungan[$event]['terkirim'] ?? 0,
                    'menunggu' => $hitungan[$event]['menunggu'] ?? 0,
                    'gagal'    => $hitungan[$event]['gagal'] ?? 0,
                    'dilewati' => $hitungan[$event]['dilewati'] ?? 0,
                ],
            ];
        }

        return $hasil;
    }
}
