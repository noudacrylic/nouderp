<?php

namespace App\Modules\Shipping;

/**
 * Pemetaan kurir → berkas logo di public/img/kurir.
 *
 * Kode kurir datang dari provider apa adanya dan bentuknya beda-beda
 * ('jne', 'jt_cargo', 'J&T Cargo', 'JNE REG'), jadi pencocokan dilakukan atas
 * teks yang sudah dinormalkan (huruf besar, non-alfanumerik dibuang).
 *
 * Kurir tanpa logo sengaja dibiarkan null — label jatuh ke teks nama kurir,
 * lebih baik daripada memaksakan logo yang salah.
 */
class CourierBrand
{
    /**
     * Urutan tidak menentukan: pencocokan persis didahulukan, lalu awalan
     * dengan pola terpanjang menang ('JTCARGO' tidak boleh kalah oleh 'JT').
     */
    private const BRANDS = [
        'jne.png'          => ['JNE'],
        'jnt.png'          => ['JT', 'JNT', 'JTEXPRESS', 'JNTEXPRESS'],
        'jnt-cargo.png'    => ['JTCARGO', 'JNTCARGO'],
        'tiki.png'         => ['TIKI'],
        'anteraja.png'     => ['ANTERAJA'],
        'id-express.png'   => ['IDEXPRESS', 'IDE'],
        'lion-parcel.png'  => ['LIONPARCEL', 'LION'],
        'paxel.png'        => ['PAXEL'],
        'gosend.png'       => ['GOSEND', 'GOJEK'],
        'grab-express.png' => ['GRABEXPRESS', 'GRAB'],
    ];

    /**
     * URL logo kurir, atau null bila tidak ada yang cocok.
     * Kandidat dicoba berurutan — biasanya kode kurir dulu, baru nama layanan.
     */
    public static function logo(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $file = self::match($candidate);
            if ($file) {
                return asset('img/kurir/' . $file);
            }
        }

        return null;
    }

    private static function match(?string $candidate): ?string
    {
        $key = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $candidate));
        if ($key === '') {
            return null;
        }

        foreach (self::BRANDS as $file => $patterns) {
            if (in_array($key, $patterns, true)) {
                return $file;
            }
        }

        // Awalan: pola terpanjang lebih dulu supaya 'JTCARGOREG' tidak jadi J&T biasa.
        $prefixes = [];
        foreach (self::BRANDS as $file => $patterns) {
            foreach ($patterns as $p) {
                $prefixes[] = [$p, $file];
            }
        }
        usort($prefixes, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));

        foreach ($prefixes as [$pattern, $file]) {
            if (str_starts_with($key, $pattern)) {
                return $file;
            }
        }

        return null;
    }
}
