<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\ProductionCostComponent;
use App\Modules\Analysis\Services\ProductionCostRateService;
use App\Modules\Production\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Invarian halaman Biaya & Tarif Divisi.
 *
 * Yang dilindungi terutama SATU hal: gaji dan biaya non-gaji tidak boleh memakai
 * pembagi yang sama. Gaji dibagi jam-orang (ikut jumlah karyawan), biaya non-gaji
 * dibagi jam operasional (tidak ikut jumlah karyawan). Pernah tertukar sehingga
 * tarif gaji divisi berkaryawan banyak tampak berkali lipat dari yang sebenarnya.
 *
 * Angka fixture sengaja dibuat supaya jam-orang ≠ jam operasional; kalau keduanya
 * kebetulan sama, tes tidak akan menangkap tertukarnya pembagi.
 */
class ProductionCostRateServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Hari kerja AKTUAL periode, seperti tercatat di slip gaji. */
    private const HARI_KERJA = 24;

    /** 08:00–16:00 dipotong istirahat 1 jam = 7 jam/hari × 24 hari kerja aktual. */
    private const SEC_PER_EMPLOYEE = 7 * 3600 * self::HARI_KERJA;   // 604.800 detik/bulan

    private ProductionCostRateService $svc;
    private Department $cnc;
    private Department $assembling;
    private Department $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(ProductionCostRateService::class);

        $this->cnc        = $this->department('PRD-001', 'CNC', 'produksi');
        $this->assembling = $this->department('PRD-002', 'Assembling', 'produksi');
        $this->admin      = $this->department('MKT-001', 'Admin', 'non_produksi');

        // Jumlah karyawan sengaja berbeda-beda supaya jam-orang tiap divisi berbeda
        // dari jam operasionalnya (yang tidak dikali jumlah orang).
        $periodeId = $this->periode();
        $this->staff($this->cnc, 1, 3_000_000, $periodeId);
        $this->staff($this->assembling, 2, 3_000_000, $periodeId);
        $this->staff($this->admin, 3, 3_000_000, $periodeId);
    }

    // ==========================================================
    // PEMBAGI GAJI
    // ==========================================================

    public function test_gaji_divisi_dibagi_jam_orang_bukan_jam_operasional(): void
    {
        $cnc = $this->build()['departments'][$this->cnc->id];

        // Assembling punya 2 orang: jam-orangnya 2× jam operasionalnya.
        $asm = $this->build()['departments'][$this->assembling->id];

        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE, $cnc['labor_seconds'], 0.01);
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE * 2, $asm['labor_seconds'], 0.01);

        // Inti bug lama: jam operasional TIDAK ikut jumlah karyawan.
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE, $asm['operating_seconds'], 0.01);
        $this->assertNotEqualsWithDelta($asm['labor_seconds'], $asm['operating_seconds'], 0.01);

        // Gaji 2 orang × 3 jt dibagi jam-orang 2 orang → tarifnya sama dengan divisi
        // 1 orang bergaji sama. Kalau dibagi jam operasional, Assembling akan 2× CNC.
        $this->assertEqualsWithDelta(
            $cnc['labor_rate_per_second'],
            $asm['labor_rate_per_second'],
            0.000001,
            'Tarif gaji per jam-orang harus sama walau jumlah karyawannya berbeda.'
        );
    }

    public function test_baris_gaji_non_produksi_memakai_jam_orang_non_produksi(): void
    {
        $result = $this->build();
        $gaji   = $this->lineNamed($result['groups']['non_produksi']['components'], 'Gaji Karyawan Non Produksi');

        // Admin 3 orang — bukan jam operasional produksi (2 divisi × 1 jadwal).
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE * 3, $gaji['divisor'], 0.01);
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE * 2, $result['production_operating_seconds'], 0.01);

        $this->assertSame('jam-orang', $gaji['divisor_label']);
        $this->assertSame('168 jam × 3 orang', $gaji['divisor_note']);
        $this->assertEqualsWithDelta(9_000_000 / (self::SEC_PER_EMPLOYEE * 3) * 3600, $gaji['rate'], 0.01);
    }

    public function test_biaya_non_gaji_dibagi_jam_operasional(): void
    {
        $this->addComponent('non_produksi', null, 'Sewa Gedung', 5_460_000);

        $result = $this->build();
        $sewa   = $this->lineNamed($result['groups']['non_produksi']['components'], 'Sewa Gedung');

        // Sewa gedung tidak ada hubungannya dengan jumlah divisi maupun jumlah orang:
        // pembaginya lamanya PABRIK buka (1×), bukan dikali 2 divisi atau 6 karyawan.
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE, $sewa['divisor'], 0.01);
        $this->assertSame('jam operasional pabrik', $sewa['divisor_label']);
        $this->assertSame('24 hari × 7 jam', $sewa['divisor_note']);
        $this->assertEqualsWithDelta(5_460_000 / self::SEC_PER_EMPLOYEE * 3600, $sewa['rate'], 0.01);
    }

    public function test_jam_memakai_hari_kerja_aktual_dari_slip_gaji(): void
    {
        $result = $this->build();

        $this->assertTrue($result['working_days_actual']);
        $this->assertEqualsWithDelta(self::HARI_KERJA, $result['working_days'], 0.01);
        $this->assertEqualsWithDelta(7, $result['hours_per_day'], 0.01);

        // Hari kerja teoretis dari jadwal (5 hari/minggu × 52/12 ≈ 21,7 hari) sengaja
        // dibuat berbeda dari hari kerja aktual (24), supaya kalau perhitungan kembali
        // memakai 52/12 minggu tesnya langsung merah.
        $teoretis = 7 * 3600 * 5 * (52 / 12);
        $this->assertNotEqualsWithDelta($teoretis, $result['departments'][$this->cnc->id]['labor_seconds'], 1);
        $this->assertEqualsWithDelta(7 * 3600 * self::HARI_KERJA, $result['departments'][$this->cnc->id]['labor_seconds'], 0.01);
    }

    public function test_jam_pembagi_biaya_tetap_tidak_dikali_jumlah_divisi(): void
    {
        $result = $this->build();

        // Pernah ditampilkan 364 jam (182 × 2 divisi) sebagai pembagi sewa & iklan.
        // Itu keliru: biaya tetap tidak bertambah karena divisinya bertambah.
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE, $result['factory_operating_seconds'], 0.01);
        $this->assertSame('24 hari × 7 jam', $result['factory_operating_note']);

        // Jumlah jam seluruh divisi tetap dihitung, tapi HANYA sebagai penimbang
        // pembagian ke divisi — bukan sebagai pembagi yang ditampilkan.
        $this->assertEqualsWithDelta(self::SEC_PER_EMPLOYEE * 2, $result['production_operating_seconds'], 0.01);
    }

    public function test_biaya_bersama_dibagi_habis_tidak_terhitung_dua_kali(): void
    {
        $this->addComponent('non_produksi', null, 'Sewa Gedung', 5_460_000);

        $result = $this->build();

        // Rp/jam grup memakai jam pabrik (1×), tapi yang mengalir ke divisi tetap
        // dibagi habis — kalau tiap divisi menyerap tarif penuh, biayanya dobel.
        $totalShare = array_sum(array_column($result['departments'], 'general_share'));

        $this->assertEqualsWithDelta($result['pool_total'], $totalShare, 0.01);
    }

    // ==========================================================
    // STRUKTUR POHON
    // ==========================================================

    public function test_hanya_divisi_produksi_yang_punya_node_sendiri(): void
    {
        $result = $this->build();

        $this->assertArrayHasKey($this->cnc->id, $result['departments']);
        $this->assertArrayHasKey($this->assembling->id, $result['departments']);
        $this->assertArrayNotHasKey(
            $this->admin->id,
            $result['departments'],
            'Divisi non-produksi harus melebur ke grup Non Produksi, bukan berdiri sendiri.'
        );
    }

    public function test_baris_gaji_terkunci_tidak_bisa_dihapus(): void
    {
        $result = $this->build();
        $gaji   = $this->lineNamed($result['departments'][$this->cnc->id]['components'], 'Gaji Karyawan');

        $this->assertTrue($gaji['locked']);
        $this->assertNull($gaji['id'], 'Baris gaji tidak boleh punya id komponen — dihitung dari slip gaji.');
    }

    public function test_biaya_bersama_dibagi_sesuai_porsi_jam_operasional(): void
    {
        $this->addComponent('non_produksi', null, 'Sewa Gedung', 5_460_000);

        $result = $this->build();

        // Pool = gaji non-produksi 9 jt + sewa 5,46 jt. Dua divisi produksi punya jam
        // operasional sama, jadi bagiannya persis separuh-separuh.
        $this->assertEqualsWithDelta(14_460_000, $result['pool_total'], 0.01);
        $this->assertEqualsWithDelta(7_230_000, $result['departments'][$this->cnc->id]['general_share'], 0.01);
        $this->assertEqualsWithDelta(7_230_000, $result['departments'][$this->assembling->id]['general_share'], 0.01);
    }

    public function test_total_pohon_menjumlah_utuh(): void
    {
        $this->addComponent('non_produksi', null, 'Sewa Gedung', 5_460_000);
        $this->addComponent('overhead_produksi', null, 'Perbaikan Mesin', 1_000_000);
        $this->addComponent('divisi', $this->cnc->id, 'Tabung Laser', 546_000);

        $result = $this->build();

        $nonProduksi = 9_000_000 + 5_460_000;              // gaji admin + sewa
        $divisi      = (3_000_000 + 546_000) + 6_000_000;  // CNC (gaji+tabung) + Assembling
        $produksi    = 1_000_000 + $divisi;                // overhead + divisi

        $this->assertEqualsWithDelta($nonProduksi, $result['groups']['non_produksi']['total'], 0.01);
        $this->assertEqualsWithDelta($produksi, $result['produksi_total'], 0.01);
        $this->assertEqualsWithDelta($nonProduksi + $produksi, $result['grand_total'], 0.01);
    }

    public function test_seluruh_biaya_terserap_ke_tarif_divisi_produksi(): void
    {
        $this->addComponent('non_produksi', null, 'Sewa Gedung', 5_460_000);
        $this->addComponent('overhead_produksi', null, 'Perbaikan Mesin', 1_000_000);

        $result = $this->build();

        // Tidak ada biaya yang menguap: total yang ditanggung semua divisi produksi
        // harus persis sama dengan total biaya tetap perusahaan.
        $ditanggung = array_sum(array_column($result['departments'], 'total_monthly'));

        $this->assertEqualsWithDelta($result['grand_total'], $ditanggung, 0.01);
    }

    public function test_packing_diukur_per_transaksi_bukan_per_jam(): void
    {
        $this->deliveries(200, 'posted');
        $this->deliveries(50, 'void'); // surat jalan batal tidak pernah dipacking
        $this->addComponent('packing', null, 'Overhead Packing', 2_000_000);

        $result   = $this->build();
        $group    = $result['groups']['packing'];
        $perMonth = $result['transactions_per_month'];

        // 250 surat jalan dibuat, hanya 200 yang ter-post yang dihitung — lalu
        // dinormalkan ke "per bulan" dengan pembagi yang sama seperti kolom Rp/Bulan.
        $this->assertEqualsWithDelta(200 / $result['period']['months'], $perMonth, 0.01);

        $this->assertSame('transaksi', $group['allocation']['rate_unit']);
        $this->assertEqualsWithDelta(2_000_000 / $perMonth, $group['allocation']['rate'], 0.01);
        $this->assertEqualsWithDelta(
            2_000_000 / $perMonth,
            $this->lineNamed($group['components'], 'Overhead Packing')['rate'],
            0.01
        );
    }

    public function test_packing_tetap_dibebankan_ke_divisi_produksi(): void
    {
        // Cara mengukurnya saja yang berbeda — uangnya tetap masuk HPP lewat pool,
        // sama seperti Non Produksi dan Overhead Produksi.
        $this->deliveries(200, 'posted');
        $this->addComponent('packing', null, 'Overhead Packing', 2_000_000);

        $result     = $this->build();
        $ditanggung = array_sum(array_column($result['departments'], 'total_monthly'));

        $this->assertEqualsWithDelta($result['grand_total'], $ditanggung, 0.01);
        $this->assertGreaterThanOrEqual(2_000_000, $result['grand_total']);
    }

    public function test_packing_tanpa_surat_jalan_memperingatkan_bukan_membagi_nol(): void
    {
        $this->addComponent('packing', null, 'Overhead Packing', 2_000_000);

        $result = $this->build();

        $this->assertNull($result['groups']['packing']['allocation']['rate']);
        $this->assertNotEmpty(array_filter(
            $result['warnings'],
            fn ($w) => str_contains($w, 'Packing tidak bisa diukur per transaksi')
        ));
    }

    // ==========================================================
    // SEAM UNTUK HALAMAN HPP
    // ==========================================================

    public function test_tarif_langsung_divisi_tidak_memuat_biaya_bersama(): void
    {
        // Halaman HPP menampilkan Non Produksi & Overhead sebagai kolom sendiri, jadi
        // tarif divisinya harus bersih dari porsi biaya bersama — kalau tidak, biaya
        // bersama masuk dua kali ke HPP.
        $this->addComponent('non_produksi', null, 'Sewa Gedung', 10_000_000);
        $this->addComponent('overhead_produksi', null, 'Perbaikan Mesin', 1_000_000);
        $this->addComponent('divisi', $this->cnc->id, 'Tabung Laser', 500_000);

        $row = $this->build()['departments'][$this->cnc->id];

        $gaji        = $row['labor'] / $row['labor_seconds'];
        $biayaSendiri = 500_000 / $row['operating_seconds'];

        $this->assertEqualsWithDelta($gaji + $biayaSendiri, $row['direct_rate_per_second'], 0.000001);

        // Tarif gabungan memang lebih besar — bedanya persis porsi biaya bersamanya.
        $this->assertGreaterThan($row['direct_rate_per_second'], $row['rate_per_second']);
        $this->assertEqualsWithDelta(
            $row['general_share'] / $row['operating_seconds'],
            $row['rate_per_second'] - $row['direct_rate_per_second'],
            0.000001
        );
    }

    public function test_rentang_bulan_tidak_kelebihan_sehari(): void
    {
        // Dibuka di akhir bulan panjang — dulu $to yang endOfDay membuat selisihnya
        // 30,99999 hari lalu masih ditambah +1, sehingga Juli dihitung ~32 hari dan
        // semua rata-rata bulanan tampak ±3% lebih kecil.
        $this->travelTo('2026-07-31 09:00:00');

        [$from, $to, $months] = app(ProductionCostRateService::class)->resolvePeriod(['months' => 1]);

        $this->assertSame('2026-07-01', $from->toDateString());
        $this->assertSame('2026-07-31', $to->toDateString());
        $this->assertEqualsWithDelta(31 / 30.4375, $months, 0.0001);
    }

    public function test_mundur_bulan_tidak_meluber_di_tanggal_31(): void
    {
        // 31 Juli mundur 5 bulan = 31 Februari yang tidak ada → Carbon meluber ke Maret,
        // membuat "6 bulan terakhir" hanya menarik 5 bulan.
        $this->travelTo('2026-07-31 09:00:00');

        [$from] = app(ProductionCostRateService::class)->resolvePeriod(['months' => 6]);

        $this->assertSame('2026-02-01', $from->toDateString());
    }

    // ==========================================================
    // FIXTURE
    // ==========================================================

    private function build(): array
    {
        // Instance baru tiap panggilan supaya cache per-instance tidak menutupi
        // komponen yang baru ditambahkan di dalam tes.
        return app()->makeWith(ProductionCostRateService::class, [])->build(['months' => 1]);
    }

    private function department(string $code, string $name, string $type): Department
    {
        return Department::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true]);
    }

    private function periode(): int
    {
        return DB::table('sdm_periode_penggajian')->insertGetId([
            'code'       => 'PRD-TEST',
            'bulan'      => (int) now()->format('n'),
            'tahun'      => (int) now()->format('Y'),
            'label'      => 'Periode Tes',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date'   => now()->endOfMonth()->toDateString(),
            'status'     => 'finalized',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Sekian karyawan bergaji sama, masing-masing berjadwal Senin–Jumat 08:00–16:00. */
    private function staff(Department $dept, int $count, float $gaji, int $periodeId): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $karyawanId = DB::table('sdm_karyawan')->insertGetId([
                'staf_code'     => $dept->code . '-' . $i,
                'name'          => $dept->name . ' ' . $i,
                'department_id' => $dept->id,
                'is_active'     => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            foreach ([1, 2, 3, 4, 5] as $day) {
                DB::table('sdm_karyawan_schedule')->insert([
                    'karyawan_id'         => $karyawanId,
                    'day_of_week'         => $day,
                    'jam_masuk'           => '08:00:00',
                    'jam_pulang'          => '16:00:00',
                    'jam_istirahat_start' => '12:00:00',
                    'jam_istirahat_end'   => '13:00:00',
                    'is_off'              => 0,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            DB::table('sdm_slip_gaji')->insert([
                'periode_id'         => $periodeId,
                'karyawan_id'        => $karyawanId,
                'code'               => 'SLIP-' . $dept->code . '-' . $i,
                'total_gaji'         => $gaji,
                // Inilah sumber jam pembagi: hari kerja nyata periode itu, bukan 52/12 minggu.
                'hari_kerja_periode' => self::HARI_KERJA,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    /** Sekian surat jalan bertanggal dalam periode uji — pembagi biaya Packing. */
    private function deliveries(int $count, string $status): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'delivery_number' => 'SJ-' . $status . '-' . $i,
                'warehouse_id'    => 1, // wajib tapi tanpa FK; tidak dipakai perhitungan
                'delivery_date'   => now()->startOfMonth()->toDateString(),
                'status'          => $status,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }

        DB::table('sales_deliveries')->insert($rows);
    }

    private function addComponent(string $group, ?int $deptId, string $name, float $amount): void
    {
        ProductionCostComponent::create([
            'group_key'      => $group,
            'department_id'  => $deptId,
            'name'           => $name,
            'source'         => 'manual',
            'amount_monthly' => $amount,
            'is_active'      => true,
        ]);
    }

    /** @param  array<int,array>  $lines */
    private function lineNamed(array $lines, string $name): array
    {
        foreach ($lines as $line) {
            if ($line['name'] === $name) {
                return $line;
            }
        }

        $this->fail("Baris \"{$name}\" tidak ditemukan. Yang ada: " . implode(', ', array_column($lines, 'name')));
    }
}
