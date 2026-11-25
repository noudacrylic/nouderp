<?php

namespace App\Modules\Production\Mesin;

/**
 * Pembaca status pengontrol laser Ruida lewat LAN — HANYA perintah baca memori (DA 00 <alamat>),
 * tidak pernah perintah gerak/laser.
 *
 * Diuji langsung di bengkel 25 Nov 2026 (rekaman pktmon RDWorks):
 *  - 'udp' (Mesin 2 & 3, kartu 0x79107910) = Ruida standar: kirim ke UDP 50200 dari port lokal
 *    40200, paket = checksum 2 byte (jumlah byte teracak, big-endian) + isi teracak.
 *  - 'tcp' (Mesin 1, kartu 0x90109010): UDP 50200 ditolak; baca memori lewat TCP 50200,
 *    isi teracak TANPA checksum.
 * Balasan: CC (ACK) lalu DA 01 <alamat 2 byte> <nilai 5 byte, 7 bit per byte>.
 * Pengacak (swizzle) magic 0x88 untuk keduanya.
 *
 * ⚠ Pengontrol mudah macet bila dibanjiri (pemindaian 4.000 perintah cepat membuat LAN-nya
 * berhenti menjawab sampai mesin dinyalakan ulang) — pemanggil wajib membaca pelan.
 */
class RuidaKlien
{
    public const MAGIC = 0x88;
    public const PORT_MESIN = 50200;
    public const PORT_LOKAL_UDP = 40200;

    public const ALAMAT_STATUS = 0x0400;
    public const ALAMAT_X = 0x0421;
    public const ALAMAT_Y = 0x0431;

    /** Bit status "pekerjaan berjalan" (MeerK40t MACHINE_STATUS_JOB_RUNNING; terbukti di ketiga mesin). */
    public const BIT_JALAN = 0x1;

    /** @var resource|null */
    private static $udp = null;
    /** @var resource|null */
    private $tcp = null;
    private string $sisa = '';

    public function __construct(public readonly string $ip, public readonly string $protokol, private float $batasDetik = 1.0) {}

    public static function swizzle(int $b): int
    {
        $b ^= ($b >> 7) & 0xFF;
        $b ^= ($b << 7) & 0xFF;
        $b ^= ($b >> 7) & 0xFF;
        $b ^= self::MAGIC;

        return ($b + 1) & 0xFF;
    }

    public static function unswizzle(int $b): int
    {
        $b = ($b - 1) & 0xFF;
        $b ^= self::MAGIC;
        $b ^= ($b >> 7) & 0xFF;
        $b ^= ($b << 7) & 0xFF;
        $b ^= ($b >> 7) & 0xFF;

        return $b;
    }

    public static function acak(string $data): string
    {
        return implode('', array_map(fn ($c) => chr(self::swizzle(ord($c))), str_split($data)));
    }

    public static function buka(string $data): string
    {
        return $data === '' ? '' : implode('', array_map(fn ($c) => chr(self::unswizzle(ord($c))), str_split($data)));
    }

    /** Paket UDP: checksum (jumlah byte teracak, 16 bit big-endian) + isi teracak. */
    public static function paketUdp(string $data): string
    {
        $acak = self::acak($data);

        return pack('n', array_sum(array_map('ord', str_split($acak))) & 0xFFFF) . $acak;
    }

    public static function perintahBaca(int $alamat): string
    {
        return chr(0xDA) . chr(0x00) . chr($alamat >> 8) . chr($alamat & 0xFF);
    }

    /**
     * Cari bingkai DA 01 <alamat> + 5 byte nilai di aliran yang sudah dibuka.
     * @return array{0: ?int, 1: string} [nilai|null, sisa aliran sesudah bingkai]
     */
    public static function ambilNilai(string $aliran, int $alamat): array
    {
        $kepala = chr(0xDA) . chr(0x01) . chr($alamat >> 8) . chr($alamat & 0xFF);
        $i = strpos($aliran, $kepala);
        if ($i === false || strlen($aliran) < $i + 9) return [null, $aliran];
        $v = 0;
        foreach (str_split(substr($aliran, $i + 4, 5)) as $c) $v = ($v << 7) | (ord($c) & 0x7F);

        return [$v, substr($aliran, $i + 9)];
    }

    /** Baca satu alamat memori; null bila tak dijawab dalam batas waktu. */
    public function baca(int $alamat): ?int
    {
        return $this->protokol === 'tcp' ? $this->bacaTcp($alamat) : $this->bacaUdp($alamat);
    }

    private function bacaUdp(int $alamat): ?int
    {
        if (!self::$udp) {
            self::$udp = @stream_socket_server('udp://0.0.0.0:' . self::PORT_LOKAL_UDP, $no, $pesan, STREAM_SERVER_BIND);
            if (!self::$udp) throw new \RuntimeException("Port UDP " . self::PORT_LOKAL_UDP . " tak bisa dipakai: $pesan");
        }
        // Buang balasan basi dari pembacaan sebelumnya.
        stream_set_blocking(self::$udp, false);
        for ($i = 0; $i < 50; $i++) {
            $basi = @stream_socket_recvfrom(self::$udp, 2048);
            if ($basi === false || $basi === '') break;
        }
        stream_set_blocking(self::$udp, true);

        stream_socket_sendto(self::$udp, self::paketUdp(self::perintahBaca($alamat)), 0, $this->ip . ':' . self::PORT_MESIN);
        $aliran = '';
        $akhir = microtime(true) + $this->batasDetik;
        while (($sisa = $akhir - microtime(true)) > 0) {
            $baca = [self::$udp]; $tulis = $kecuali = null;
            if (!@stream_select($baca, $tulis, $kecuali, 0, (int) ($sisa * 1e6))) break;
            $data = stream_socket_recvfrom(self::$udp, 2048, 0, $dari);
            if (!$data || !str_starts_with((string) $dari, $this->ip . ':')) continue;
            $aliran .= self::buka($data);
            [$v] = self::ambilNilai($aliran, $alamat);
            if ($v !== null) return $v;
        }

        return null;
    }

    private function bacaTcp(int $alamat): ?int
    {
        if (!$this->tcp) {
            $this->tcp = @stream_socket_client("tcp://{$this->ip}:" . self::PORT_MESIN, $no, $pesan, $this->batasDetik);
            if (!$this->tcp) return null;
            $this->sisa = '';
        }
        if (@fwrite($this->tcp, self::acak(self::perintahBaca($alamat))) === false) {
            $this->tutup();
            return null;
        }
        $akhir = microtime(true) + $this->batasDetik;
        while (true) {
            [$v, $this->sisa] = self::ambilNilai($this->sisa, $alamat);
            if ($v !== null) return $v;
            $sisa = $akhir - microtime(true);
            if ($sisa <= 0) break;
            $baca = [$this->tcp]; $tulis = $kecuali = null;
            if (!@stream_select($baca, $tulis, $kecuali, 0, (int) ($sisa * 1e6))) break;
            $data = fread($this->tcp, 512);
            if ($data === '' || $data === false) { $this->tutup(); return null; }
            $this->sisa .= self::buka($data);
        }
        // Tak dijawab: koneksi bisa basi — buka ulang di pembacaan berikutnya.
        $this->tutup();

        return null;
    }

    public function tutup(): void
    {
        if ($this->tcp) @fclose($this->tcp);
        $this->tcp = null;
        $this->sisa = '';
    }
}
