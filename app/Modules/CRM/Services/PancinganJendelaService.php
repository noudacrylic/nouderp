<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Support\JenisNotifikasi;
use App\Modules\CRM\Support\TemplateResmi;
use Carbon\Carbon;

/**
 * Memancing pelanggan menekan tombol SEBELUM jendela 24 jam-nya habis.
 *
 * Alasannya seluruhnya soal biaya. Selama jendela masih terbuka, pesan
 * bertombol adalah pesan sesi biasa: gratis. Semenit sesudah tutup, kalimat
 * yang sama persis hanya boleh keluar sebagai template Meta, dan template
 * dibayar per kirim. Jadi yang dikerjakan penjadwal ini sederhana — memindahkan
 * pancingan ke sisi jendela yang masih gratis, supaya jalur berbayar tinggal
 * jadi jaring pengaman, bukan kebiasaan.
 *
 * Yang dipancing hanya percakapan yang PEMBAHASANNYA MASIH HIDUP: aktif (belum
 * diarsipkan) dan sudah dua arah. Batasan kedua itu penting — nomor yang baru
 * sekali menyapa lalu diam bukan diskusi yang menggantung, dan memancingnya
 * berarti mengirim pesan kepada orang yang belum pernah kita ajak bicara.
 *
 * Yang dikerjakan kelas ini MENGANTREKAN, bukan mengirim. Sengaja, supaya
 * pancingan mewarisi seluruh yang sudah dimiliki notifikasi lain: saklar
 * mati-nyala di layar yang sama, riwayat per pelanggan, alasan kalau gagal, dan
 * pengulangan saat jalurnya sedang mati. Pengirimannya sendiri di
 * CrmOutboxSender, yang memilih jalur gratis atau berbayar menurut jendelanya.
 */
class PancinganJendelaService
{
    /**
     * @return array{diperiksa:int, diantrekan:int, dilewati:string}
     */
    public function jalankan(?Carbon $sekarang = null): array
    {
        $sekarang = $sekarang ?: now();

        /*
         * Saklarnya sama dengan jenis notifikasi lain, di layar yang sama.
         * Dimatikan = TIDAK DIANTREKAN sama sekali, bukan dicatat 'dilewati':
         * mencatatnya membakar kunci dedupe jendela ini, dan pancingannya tak
         * akan pernah bisa berangkat lagi meski jenisnya dinyalakan semenit
         * kemudian — padahal jendelanya belum habis.
         */
        if (! JenisNotifikasi::aktif(CrmOutboxMessage::EVENT_PANCINGAN)) {
            return $this->hasil(0, 0, 'jenis notifikasi ini dimatikan');
        }

        if (! $this->jamSopan($sekarang)) {
            return $this->hasil(0, 0, 'di luar jam sopan');
        }

        $batas = $this->batasJendela($sekarang);

        $daftar = CrmConversation::query()
            ->where('status', CrmConversation::STATUS_AKTIF)
            ->whereNotNull('window_expires_at')
            ->where('window_expires_at', '>', $sekarang)
            ->where('window_expires_at', '<=', $batas)
            /*
             * Dua arah. Percakapan yang belum pernah kita jawab bukan diskusi
             * yang menggantung — ia antrean yang belum disentuh, dan yang
             * dibutuhkannya jawaban, bukan tombol.
             */
            ->whereNotNull('last_inbound_at')
            ->whereNotNull('last_outbound_at')
            /*
             * Belum dipancing UNTUK JENDELA INI. Perbandingannya ke nilai
             * jendelanya, bukan ke waktu kirim: begitu pelanggan membalas,
             * jendelanya bergeser, angkanya tak lagi cocok, dan pancingan
             * berikutnya boleh berangkat tanpa aturan kedaluwarsa apa pun.
             */
            ->where(fn ($q) => $q
                ->whereNull('pancingan_untuk_jendela_at')
                ->orWhereColumn('pancingan_untuk_jendela_at', '!=', 'window_expires_at'))
            ->orderBy('window_expires_at')
            ->limit((int) config('crm.pancingan.maks_per_jalan', 50))
            ->get();

        $diantrekan = 0;

        foreach ($daftar as $percakapan) {
            $baris = CrmOutboxMessage::antrekan(
                /*
                 * Kunci dedupe memuat JENDELANYA, bukan tanggalnya. Pelanggan
                 * membalas → jendela bergeser → kuncinya berganti sendiri, dan
                 * pancingan untuk jendela yang baru boleh berangkat tanpa satu
                 * pun aturan kedaluwarsa yang perlu ditulis.
                 */
                'pancingan:' . $percakapan->id . ':' . $percakapan->window_expires_at->timestamp,
                [
                    'event'           => CrmOutboxMessage::EVENT_PANCINGAN,
                    'conversation_id' => $percakapan->id,
                    'recipient'       => $percakapan->contact_key,
                    'template_name'   => TemplateResmi::TEMPLATE_PANCINGAN,
                    'template_body'   => [
                        $percakapan->sapaan(),
                        (string) config('crm.store_hours_text', 'jam kerja'),
                    ],
                    'status'          => CrmOutboxMessage::STATUS_MENUNGGU,
                    'scheduled_at'    => null,
                ]
            );

            if (! $baris) {
                continue;
            }

            $diantrekan++;

            /*
             * Penandanya dipasang saat DIANTREKAN, bukan saat terkirim. Kunci
             * dedupe sudah menjaga pesannya tak kembar; kolom ini yang menjaga
             * penjadwal tidak memeriksa ulang percakapan yang sama tiap
             * seperempat jam sampai jendelanya habis.
             */
            $percakapan->forceFill(['pancingan_untuk_jendela_at' => $percakapan->window_expires_at])->save();
        }

        return $this->hasil($daftar->count(), $diantrekan, '');
    }

    /**
     * Sampai kapan jendela dianggap "hampir habis" pada saat ini.
     *
     * Biasanya sejam ke depan. Yang membuatnya tidak sesederhana itu adalah
     * MALAM: jendela yang habis pukul tiga pagi akan memicu pancingan pukul
     * dua pagi, dan pesan pukul dua pagi merusak lebih banyak daripada yang
     * diselamatkannya. Maka pada jam sopan yang terakhir, semua jendela yang
     * akan habis sepanjang malam ditarik maju sekaligus — dikirim lebih awal,
     * selagi masih ada orang yang membacanya.
     */
    private function batasJendela(Carbon $sekarang): Carbon
    {
        $akhir = (int) config('crm.pancingan.jam_akhir', 21);

        if ($sekarang->hour === $akhir - 1) {
            return $sekarang->copy()->addDay()->setTime((int) config('crm.pancingan.jam_mulai', 7), 0);
        }

        return $sekarang->copy()->addMinutes(CrmReplyService::AMBANG_PANCINGAN_MENIT);
    }

    /** Jam yang pantas untuk mengganggu orang. Bukan jam toko — lihat config. */
    private function jamSopan(Carbon $sekarang): bool
    {
        $mulai = (int) config('crm.pancingan.jam_mulai', 7);
        $akhir = (int) config('crm.pancingan.jam_akhir', 21);

        return $sekarang->hour >= $mulai && $sekarang->hour < $akhir;
    }

    private function hasil(int $diperiksa, int $diantrekan, string $dilewati): array
    {
        return compact('diperiksa', 'diantrekan', 'dilewati');
    }
}
