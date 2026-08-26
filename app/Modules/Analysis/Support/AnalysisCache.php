<?php

namespace App\Modules\Analysis\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penyimpan hasil hitungan halaman Analisa, dikunci SIDIK JARI DATA.
 *
 * Seluruh halaman Analisa menghitung ulang dari nol setiap kali dibuka: HPP 1,4 detik
 * (1.172 query), Harga Produk 2,4 detik (2.872 query). Padahal angkanya baru berubah
 * kalau ada data yang berubah — dan di hari biasa itu terjadi beberapa kali saja.
 *
 * ── KENAPA SIDIK JARI, BUKAN TENGGAT WAKTU ────────────────────────────────
 *
 * Cache berumur ("simpan 10 menit") membuat angka bisa basi tanpa ada yang tahu. Untuk
 * halaman yang dipakai menetapkan harga jual, itu tidak sepadan: yang membacanya sedang
 * memutuskan, bukan sedang melihat-lihat.
 *
 * ── KENAPA BUKAN OBSERVER DI TIAP MODEL ───────────────────────────────────
 *
 * Membersihkan cache lewat Observer berarti harus INGAT semua jalur tulis — termasuk
 * `DB::table(...)->update()` yang tersebar di service, command, dan importir Excel. Satu
 * yang terlewat menghasilkan angka salah yang diam. Sidik jari tidak bisa lupa: ia
 * membaca keadaan tabelnya, bukan mempercayai kita memanggil pembersihnya.
 *
 * Caranya: satu putaran query murah (`COUNT` + `MAX(id)` + `MAX(updated_at)`) atas tabel
 * yang menyuapi angka Analisa, diringkas jadi satu hash. Data berubah → hash berubah →
 * kunci cache berubah → hitung ulang. Data diam → jawaban lama dipakai apa adanya.
 *
 * Yang TIDAK tertangkap: perubahan pada baris yang tidak menyentuh `updated_at`, `id`,
 * maupun jumlah baris (mis. `DB::table()->update()` pada tabel tanpa kolom `updated_at`).
 * Karena itu tombol "Hitung ulang" manual tetap ada di setiap halaman — lihat `bump()`.
 */
class AnalysisCache
{
    /** Umur maksimum satu entri. Bukan alat kesegaran (itu tugas sidik jari), hanya sapu. */
    private const TTL_MINUTES = 720;   // 12 jam

    private const KEY_GENERATION = 'analisa:generasi';

    /**
     * Tabel yang menyuapi angka Analisa. Ditambah bila ada sumber data baru — tabel yang
     * TERLEWAT di sini berarti perubahannya tidak memicu hitung ulang.
     */
    private const TABLES = [
        // Waktu produksi & sampelnya
        'production_orders', 'production_order_steps', 'production_order_step_executors',
        'production_step_time_logs', 'production_order_materials', 'production_order_outputs',
        'production_time_sample_exclusions', 'production_time_assumptions',
        // Resep (BOM) — `bom_outputs.qty_per_cycle` adalah hasil per siklus yang dipakai membagi
        // durasi timer jadi detik-per-unit, dan dari situ mengalir ke HPP lalu ke Harga.
        // BOM-nya disunting di modul Produksi, jauh dari halaman yang ikut berubah.
        'boms', 'bom_outputs', 'bom_materials',
        // Kapasitas & slot
        'production_quota_slots', 'production_quota_excluded_dates',
        'production_department_executors', 'production_departments', 'production_machine_downtimes',
        // Biaya
        'production_cost_components', 'production_product_packing_costs',
        'sdm_slip_gaji', 'sales_deliveries',
        // Bagan akun — memindahkan sebuah akun menggeser pool biaya tetap
        'accounts',
        // Bahan & produk
        'products', 'product_prices', 'stock_layers', 'material_price_assumptions',
        'bundle_components', 'product_bundles',
        // Harga per kanal
        'product_channel_prices', 'price_channel_fee_components',
        // Jadwal orang — menentukan jam tersedia
        'sdm_karyawan', 'sdm_karyawan_schedule', 'sdm_attendance', 'sdm_attendance_overrides',
        'sdm_national_holidays',
    ];

    /**
     * Tabel besar yang cukup diperiksa lewat `MAX(id)` + `MAX(updated_at)` saja.
     * `COUNT(*)` di InnoDB memindai indeks — untuk tabel puluhan ribu baris itu ongkos
     * yang dibayar SETIAP membuka halaman, sementara yang ingin kita tangkap (baris baru
     * & baris disunting) sudah tertangkap keduanya.
     */
    private const TABLES_TANPA_COUNT = ['journal_lines'];

    private ?string $sidik = null;

    /** Cap waktu TERTUA di antara jawaban yang dilayani sepanjang permintaan ini. */
    private ?Carbon $dilayaniPada = null;

    /**
     * Jawaban yang sudah ada dipakai; kalau belum ada, dihitung sekali lalu disimpan.
     *
     * $key    nama perhitungan ('hpp.all', 'quota.build', …)
     * $bagian pembeda antar-pemanggilan yang sama-sama sah (filter, kanal, produk)
     */
    public function remember(string $key, array $bagian, Closure $fn): mixed
    {
        $penuh = $this->cacheKey($key, $bagian);
        $entri = Cache::get($penuh);

        if (is_array($entri) && array_key_exists('data', $entri)) {
            $this->catatDilayani(isset($entri['at']) ? Carbon::parse($entri['at']) : now());

            return $entri['data'];
        }

        $data = $fn();

        Cache::put($penuh, ['at' => now()->toIso8601String(), 'data' => $data],
            now()->addMinutes(self::TTL_MINUTES));
        $this->catatDilayani(now());

        return $data;
    }

    /**
     * Kapan angka di halaman ini dihitung — untuk label "Dihitung 3 menit lalu".
     *
     * Yang dilaporkan sengaja yang PALING TUA di antara semua bagian yang dipakai halaman
     * itu. Satu halaman memakai beberapa perhitungan sekaligus; menyebut yang paling baru
     * akan membuat angka lama tampak lebih segar daripada kenyataannya.
     */
    public function servedAt(): ?Carbon
    {
        return $this->dilayaniPada;
    }

    private function catatDilayani(Carbon $waktu): void
    {
        if ($this->dilayaniPada === null || $waktu->lt($this->dilayaniPada)) {
            $this->dilayaniPada = $waktu;
        }
    }

    /** Kapan satu perhitungan tertentu dihitung. */
    public function computedAt(string $key, array $bagian = []): ?Carbon
    {
        $entri = Cache::get($this->cacheKey($key, $bagian));
        $at    = is_array($entri) ? ($entri['at'] ?? null) : null;

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Paksa hitung ulang (tombol manual).
     *
     * Menaikkan nomor generasi, bukan menyapu cache: penyapuan menyeluruh akan ikut
     * membuang cache modul lain yang menumpang penyimpan yang sama, dan pada penyimpan
     * file `Cache::flush()` menghapus seluruh direktori. Entri lama mati sendiri lewat TTL.
     */
    public function bump(): void
    {
        Cache::forever(self::KEY_GENERATION, $this->generation() + 1);
        $this->sidik        = null;
        $this->dilayaniPada = null;
    }

    public function generation(): int
    {
        return (int) Cache::get(self::KEY_GENERATION, 1);
    }

    /**
     * Sidik jari seluruh data yang menyuapi Analisa. Dihitung sekali per permintaan —
     * satu halaman memanggil banyak perhitungan, dan semuanya harus melihat cap yang sama.
     */
    public function fingerprint(): string
    {
        if ($this->sidik !== null) {
            return $this->sidik;
        }

        // Tanggal hari ini ikut disidik: beberapa perhitungan memakai jendela yang
        // bergerak sendiri ("30 hari terakhir sampai kemarin"). Tanpa ini, angka yang
        // dihitung kemarin sore akan terus dipakai lewat tengah malam padahal jendelanya
        // sudah bergeser — data tidak berubah, jawabannya yang seharusnya berubah.
        $cap = ['hari' => now()->toDateString()] + $this->capSemuaTabel();

        return $this->sidik = substr(md5(json_encode($cap)), 0, 12);
    }

    // ==========================================================

    /**
     * Cap seluruh tabel dalam SATU perjalanan ke basis data.
     *
     * Tiga puluh satu query kecil terpisah menghabiskan ~90 ms yang hampir seluruhnya
     * ongkos bolak-balik, bukan kerja — dan ongkos itu dibayar pada SETIAP permintaan,
     * termasuk yang jawabannya sudah tersimpan. Digabung jadi satu UNION ALL, ongkosnya
     * tinggal satu perjalanan.
     *
     * @return array<string,array>
     */
    private function capSemuaTabel(): array
    {
        $peta  = $this->kolomMap();
        $bagian = [];

        foreach ([...self::TABLES, ...self::TABLES_TANPA_COUNT] as $tabel) {
            $kolom = $peta[$tabel] ?? null;
            if (!$kolom) {
                continue;
            }

            $hitung = in_array($tabel, self::TABLES_TANPA_COUNT, true) ? 'NULL' : 'COUNT(*)';
            $id     = $kolom['id'] ? 'MAX(id)' : 'NULL';
            $ubah   = $kolom['updated_at'] ? 'MAX(updated_at)' : 'NULL';

            $bagian[] = sprintf(
                "SELECT %s AS t, %s AS c, %s AS i, %s AS u FROM `%s`",
                DB::getPdo()->quote($tabel), $hitung, $id, $ubah, $tabel
            );
        }

        if (!$bagian) {
            return [];
        }

        $cap = [];
        foreach (DB::select(implode(' UNION ALL ', $bagian)) as $baris) {
            $cap[$baris->t] = [$baris->c, $baris->i, $baris->u];
        }

        ksort($cap);   // urutan baris UNION tidak dijamin; hash harus stabil

        return $cap;
    }

    // ==========================================================
    // ==========================================================

    private function cacheKey(string $key, array $bagian): string
    {
        $sisa = $bagian ? substr(md5(json_encode($this->normalkan($bagian))), 0, 10) : 'polos';

        return sprintf('analisa:%d:%s:%s:%s', $this->generation(), $key, $sisa, $this->fingerprint());
    }

    /**
     * Filter yang isinya sama harus menghasilkan kunci yang sama walau urutannya berbeda,
     * dan nilai kosong tidak boleh membedakan — kalau tidak, `?search=` dan tanpa `search`
     * jadi dua entri berisi angka yang persis sama.
     */
    private function normalkan(array $bagian): array
    {
        $bersih = [];

        foreach ($bagian as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $bersih[$k] = is_array($v) ? $this->normalkan($v) : (is_object($v) ? (string) $v : $v);
        }

        ksort($bersih);

        return $bersih;
    }

    /**
     * Peta tabel → kolom yang bisa dijadikan cap. Hasil `Schema::hasTable()` datang dari
     * information_schema; menanyakannya untuk 30 tabel pada SETIAP permintaan lebih mahal
     * daripada perhitungan yang mau dihemat, jadi petanya sendiri ikut disimpan.
     *
     * Kuncinya membawa sidik jari DAFTAR TABEL, bukan nama tetap. Kalau tidak, menambah
     * tabel baru ke `TABLES` tidak berpengaruh apa-apa sampai peta lama kedaluwarsa —
     * sampai sehari penuh perubahan pada tabel yang baru didaftarkan tetap tak tertangkap,
     * dan diamnya persis seperti kalau tabel itu belum ditambahkan sama sekali.
     *
     * @return array<string,array{id:bool,updated_at:bool}>
     */
    private function kolomMap(): array
    {
        static $memo = null;

        $daftar = [...self::TABLES, ...self::TABLES_TANPA_COUNT];
        $kunci  = 'analisa:kolom-cap:' . substr(md5(json_encode($daftar)), 0, 8);

        return $memo ??= Cache::remember($kunci, now()->addDay(), function () use ($daftar) {
            $peta = [];

            foreach ($daftar as $tabel) {
                if (!Schema::hasTable($tabel)) {
                    continue;
                }
                $kolom = Schema::getColumnListing($tabel);
                $peta[$tabel] = [
                    'id'         => in_array('id', $kolom, true),
                    'updated_at' => in_array('updated_at', $kolom, true),
                ];
            }

            return $peta;
        });
    }
}
