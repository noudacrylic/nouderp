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
 * SENGAJA cuma tiga. Menambah jenis berarti mengajukan template baru ke Meta,
 * dan mengirim terlalu sering mengundang blokir.
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

    public static function label(string $event): string
    {
        return self::DAFTAR[$event]['label'] ?? $event;
    }

    /**
     * Katalog siap tampil: label, pemicu, teks contoh, status aktif.
     *
     * @param array<string, array<string,int>> $hitungan [event][status] => jumlah
     */
    public static function katalog(array $hitungan = []): array
    {
        $hasil = [];

        foreach (self::DAFTAR as $event => $meta) {
            $template = CrmOutboxMessage::TEMPLATES[$event] ?? '';

            $hasil[] = [
                'event'    => $event,
                'label'    => $meta['label'],
                'pemicu'   => $meta['pemicu'],
                'template' => $template,
                'teks'     => TemplateResmi::render(
                    $template,
                    $meta['contoh'],
                    rtrim((string) config('crm.storefront_url'), '/') . '/pesanan/contoh-token'
                ),
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
