<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\PayrollSetting;

/**
 * Hitung potongan BPJS (Kesehatan, JHT, JP) & PPh 21 (TER bulanan, PMK 168/2023).
 * Dipakai di Summary Absensi & saat post Bayar Gaji ke jurnal.
 */
class PayrollTaxBpjsCalculator
{
    public function __construct(protected ?PayrollSetting $setting = null)
    {
        $this->setting = $setting ?: PayrollSetting::singleton();
    }

    /**
     * Hitung semua komponen potongan & beban perusahaan untuk satu karyawan dalam satu periode.
     *
     * @return array{
     *   bpjs_kesehatan_employee: float, bpjs_kesehatan_company: float,
     *   bpjs_jht_employee: float, bpjs_jht_company: float,
     *   bpjs_jp_employee: float, bpjs_jp_company: float,
     *   bpjs_jkk_company: float, bpjs_jkm_company: float,
     *   bpjs_tk_employee_total: float, bpjs_tk_company_total: float,
     *   bpjs_kesehatan_total: float, bpjs_tk_liability_total: float,
     *   pph21: float, total_potongan: float,
     * }
     */
    public function compute(Karyawan $k, float $bruto): array
    {
        $bpjsKesEmp = 0;
        $bpjsKesComp = 0;
        if ($k->ikut_bpjs_kesehatan) {
            $base = min($bruto, (float) $this->setting->bpjs_kesehatan_max_base);
            $bpjsKesEmp  = $this->pct($base, $this->setting->bpjs_kesehatan_employee_rate);
            $bpjsKesComp = $this->pct($base, $this->setting->bpjs_kesehatan_company_rate);
        }

        $jhtEmp = $jhtComp = $jpEmp = $jpComp = $jkkComp = $jkmComp = 0;
        if ($k->ikut_bpjs_tk) {
            $jhtEmp  = $this->pct($bruto, $this->setting->bpjs_jht_employee_rate);
            $jhtComp = $this->pct($bruto, $this->setting->bpjs_jht_company_rate);
            $jkkComp = $this->pct($bruto, $this->setting->bpjs_jkk_company_rate);
            $jkmComp = $this->pct($bruto, $this->setting->bpjs_jkm_company_rate);

            $jpBase = min($bruto, (float) $this->setting->bpjs_jp_max_base);
            $jpEmp  = $this->pct($jpBase, $this->setting->bpjs_jp_employee_rate);
            $jpComp = $this->pct($jpBase, $this->setting->bpjs_jp_company_rate);
        }

        $pph21 = 0;
        if ($this->setting->pph21_enabled && $k->ptkp_category && $k->ptkp_category !== 'NONE') {
            $rate = self::lookupTerRate($k->ptkp_category, $bruto);
            $pph21 = round($bruto * $rate, 2);
        }

        $bpjsKesTotal = $bpjsKesEmp;
        $bpjsTkEmpTotal = $jhtEmp + $jpEmp;
        $bpjsTkCompTotal = $jhtComp + $jpComp + $jkkComp + $jkmComp;

        $totalPotongan = $bpjsKesEmp + $bpjsTkEmpTotal + $pph21;

        return [
            'bpjs_kesehatan_employee'  => $bpjsKesEmp,
            'bpjs_kesehatan_company'   => $bpjsKesComp,
            'bpjs_jht_employee'        => $jhtEmp,
            'bpjs_jht_company'         => $jhtComp,
            'bpjs_jp_employee'         => $jpEmp,
            'bpjs_jp_company'          => $jpComp,
            'bpjs_jkk_company'         => $jkkComp,
            'bpjs_jkm_company'         => $jkmComp,
            'bpjs_tk_employee_total'   => $bpjsTkEmpTotal,
            'bpjs_tk_company_total'    => $bpjsTkCompTotal,
            'bpjs_kesehatan_total'     => $bpjsKesTotal,
            'bpjs_tk_liability_total'  => $bpjsTkEmpTotal + $bpjsTkCompTotal,
            'pph21'                    => $pph21,
            'total_potongan'           => $totalPotongan,
        ];
    }

    protected function pct(float $base, $rate): float
    {
        return round($base * ((float) $rate / 100), 2);
    }

    /**
     * Tabel TER bulanan PMK 168/2023.
     * Tiap entri = [batas_atas (exclusive, null = tanpa batas), tarif_desimal].
     */
    public static function lookupTerRate(string $kategori, float $bruto): float
    {
        $table = match ($kategori) {
            'A' => self::TER_A,
            'B' => self::TER_B,
            'C' => self::TER_C,
            default => [],
        };
        foreach ($table as [$upper, $rate]) {
            if ($upper === null || $bruto < $upper) {
                return $rate;
            }
        }
        return 0.34;
    }

    // PTKP kategori A: TK/0, TK/1, K/0
    public const TER_A = [
        [5_400_000,     0.0000],
        [5_650_000,     0.0025],
        [5_950_000,     0.0050],
        [6_300_000,     0.0075],
        [6_750_000,     0.0100],
        [7_500_000,     0.0125],
        [8_550_000,     0.0150],
        [9_650_000,     0.0175],
        [10_050_000,    0.0200],
        [10_350_000,    0.0225],
        [10_700_000,    0.0250],
        [11_050_000,    0.0300],
        [11_600_000,    0.0350],
        [12_500_000,    0.0400],
        [13_750_000,    0.0500],
        [15_100_000,    0.0600],
        [16_950_000,    0.0700],
        [19_750_000,    0.0800],
        [24_150_000,    0.0900],
        [26_450_000,    0.1000],
        [28_000_000,    0.1100],
        [30_050_000,    0.1200],
        [32_400_000,    0.1300],
        [35_400_000,    0.1400],
        [39_100_000,    0.1500],
        [43_850_000,    0.1600],
        [47_800_000,    0.1700],
        [51_400_000,    0.1800],
        [56_300_000,    0.1900],
        [62_200_000,    0.2000],
        [68_600_000,    0.2100],
        [77_500_000,    0.2200],
        [89_000_000,    0.2300],
        [103_000_000,   0.2400],
        [125_000_000,   0.2500],
        [157_000_000,   0.2600],
        [206_000_000,   0.2700],
        [337_000_000,   0.2800],
        [454_000_000,   0.2900],
        [550_000_000,   0.3000],
        [695_000_000,   0.3100],
        [910_000_000,   0.3200],
        [1_400_000_000, 0.3300],
        [null,          0.3400],
    ];

    // PTKP kategori B: TK/2, TK/3, K/1, K/2
    public const TER_B = [
        [6_200_000,     0.0000],
        [6_500_000,     0.0025],
        [6_850_000,     0.0050],
        [7_300_000,     0.0075],
        [9_200_000,     0.0100],
        [10_750_000,    0.0150],
        [11_250_000,    0.0200],
        [11_600_000,    0.0250],
        [12_600_000,    0.0300],
        [13_600_000,    0.0400],
        [14_950_000,    0.0500],
        [16_400_000,    0.0600],
        [18_450_000,    0.0700],
        [21_850_000,    0.0800],
        [26_000_000,    0.0900],
        [27_700_000,    0.1000],
        [29_350_000,    0.1100],
        [31_450_000,    0.1200],
        [33_950_000,    0.1300],
        [37_100_000,    0.1400],
        [41_100_000,    0.1500],
        [45_800_000,    0.1600],
        [49_500_000,    0.1700],
        [53_800_000,    0.1800],
        [58_500_000,    0.1900],
        [64_000_000,    0.2000],
        [71_000_000,    0.2100],
        [80_000_000,    0.2200],
        [93_000_000,    0.2300],
        [109_000_000,   0.2400],
        [129_000_000,   0.2500],
        [163_000_000,   0.2600],
        [211_000_000,   0.2700],
        [374_000_000,   0.2800],
        [459_000_000,   0.2900],
        [555_000_000,   0.3000],
        [704_000_000,   0.3100],
        [957_000_000,   0.3200],
        [1_405_000_000, 0.3300],
        [null,          0.3400],
    ];

    // PTKP kategori C: K/3
    public const TER_C = [
        [6_600_000,     0.0000],
        [6_950_000,     0.0025],
        [7_350_000,     0.0050],
        [7_800_000,     0.0075],
        [8_850_000,     0.0100],
        [9_800_000,     0.0125],
        [10_950_000,    0.0150],
        [11_200_000,    0.0175],
        [12_050_000,    0.0200],
        [12_950_000,    0.0300],
        [14_150_000,    0.0400],
        [15_550_000,    0.0500],
        [17_050_000,    0.0600],
        [19_500_000,    0.0700],
        [22_700_000,    0.0800],
        [26_600_000,    0.0900],
        [28_100_000,    0.1000],
        [30_100_000,    0.1100],
        [32_600_000,    0.1200],
        [35_100_000,    0.1300],
        [38_200_000,    0.1400],
        [42_000_000,    0.1500],
        [47_400_000,    0.1600],
        [51_200_000,    0.1700],
        [55_800_000,    0.1800],
        [60_400_000,    0.1900],
        [66_700_000,    0.2000],
        [74_500_000,    0.2100],
        [83_200_000,    0.2200],
        [95_600_000,    0.2300],
        [110_000_000,   0.2400],
        [134_000_000,   0.2500],
        [169_000_000,   0.2600],
        [221_000_000,   0.2700],
        [390_000_000,   0.2800],
        [463_000_000,   0.2900],
        [561_000_000,   0.3000],
        [709_000_000,   0.3100],
        [965_000_000,   0.3200],
        [1_419_000_000, 0.3300],
        [null,          0.3400],
    ];

    public static function kategoriLabel(string $code): string
    {
        return match ($code) {
            'A' => 'A (TK/0, TK/1, K/0)',
            'B' => 'B (TK/2, TK/3, K/1, K/2)',
            'C' => 'C (K/3)',
            default => 'Tidak kena pajak',
        };
    }
}
