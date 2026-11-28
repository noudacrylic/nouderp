<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use App\Modules\Analysis\Models\CustomPriceCategory;
use App\Modules\Analysis\Models\CustomPriceMaterial;
use App\Modules\Analysis\Models\CustomPriceSample;
use App\Modules\Analysis\Services\ChannelPricingService;
use App\Modules\Analysis\Services\CustomPriceService;
use App\Modules\Analysis\Services\MaterialRecipeService;
use App\Modules\Analysis\Services\ProductHppService;
use App\Modules\Analysis\Services\ProductionQuotaService;
use App\Modules\Production\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Harga Custom — resep Kotak + rumus harga yang disepakati pemilik 15 Okt 2026.
 *
 * Angka uji dipilih supaya bisa dihitung tangan: kotak 30×20×15 bertutup engsel 2 dan satu
 * sekat melintang, bahan 2 mm Rp405.000 per lembar 122×244.
 */
class CustomPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    public const TARIF = 36_000.0;      // Rp per slot-jam → Rp10 per detik
    public const JAM_PER_SLOT = 7.0;
    public const LUAS_LEMBAR = 244 * 122;

    /**
     * Waktu dasar kotak uji (rumus 25 Nov 2026):
     *  CNC   = keliling 5900 mm ÷ 25 mm/dtk + 30 mnt/lembar × 3000 cm² ÷ luas lembar
     *  rakit = 6 komponen × 15 dtk + 210 cm lem × 2,2 dtk + 2 engsel × 60 dtk = 672
     */
    private function cncDasar(): float
    {
        return 5900 / 25 + 1800 * 3000 / self::LUAS_LEMBAR;
    }

    public const RAKIT_DASAR = 672.0;

    private int $cnc;
    private int $asm;
    private int $bahan;
    private int $engsel;
    private CustomPriceCategory $kotak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cnc = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true])->id;
        $this->asm = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true])->id;

        $this->bahan = DB::table('products')->insertGetId([
            'sku' => 'L-4x8-2-T', 'name' => 'Akrilik Lembaran 2 mm Bening', 'last_cost' => 405_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_raw_materials')->insert([
            'product_id' => $this->bahan, 'panjang' => 244, 'lebar' => 122, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->engsel = DB::table('products')->insertGetId([
            'sku' => 'ENG-1', 'name' => 'Engsel Akrilik Kecil', 'last_cost' => 2_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->kotak = CustomPriceCategory::where('code', 'kotak')->firstOrFail();
        $this->kotak->update([
            'cnc_department_id'      => $this->cnc,
            'assembly_department_id' => $this->asm,
            'setup_cost'             => 100_000,
            'markup_percent'         => 60,
            'waste_percent'          => 15,
            'min_order_enabled'      => true,
        ]);

        $this->stubKuota();
    }

    private function input(array $extra = []): array
    {
        return array_merge([
            'p' => '30', 'l' => '20', 't' => '15',
            'bahan' => ['utama' => $this->bahan],
            'sekat_lt' => '1',
            'tutup' => '1', 'tutup_tipe' => 'engsel', 'engsel_product_id' => $this->engsel, 'engsel_qty' => '2',
        ], $extra);
    }

    public function test_geometri_kotak_panel_lem_dan_waktu_dasar(): void
    {
        $geo = app(CustomPriceService::class)->geometry($this->kotak, $this->input());

        $this->assertTrue($geo['ok'], implode(' ', $geo['errors'] ?? []));

        // alas 600 + depan/belakang 2×450 + samping 2×300 + sekat 300 + tutup 600
        $this->assertEqualsWithDelta(3000, $geo['area_total'], 0.001);
        // dinding: 4×15 + 2×(30+20) = 160; sekat melintang: 2×15 + 20 = 50
        $this->assertEqualsWithDelta(210, $geo['lem_cm'], 0.001);
        // keliling: 1000 + 1800 + 1400 + 700 + 1000 mm
        $this->assertEqualsWithDelta(5900, $geo['cut_mm'], 0.001);

        // 2 mm → 25 mm/dtk (dibaca dari nama) + persiapan per lembar dibagi luas
        $this->assertEqualsWithDelta($this->cncDasar(), $geo['cnc_base_sec'], 0.001);
        // Komponen dilem: alas, 4 dinding, 1 sekat (tutup tidak) = 6
        $this->assertEqualsWithDelta(6, $geo['komponen'], 0.001);
        $this->assertEqualsWithDelta(self::RAKIT_DASAR, $geo['asm_base_sec'], 0.001);

        $perCm2 = 405_000 / (244 * 122);
        $this->assertEqualsWithDelta(3000 * $perCm2 * 1.15, $geo['material_cost'], 0.01);
        // Engsel ikut harga beli terakhir produknya.
        $this->assertEqualsWithDelta(4_000, $geo['accessory_cost'], 0.001);
    }

    public function test_ukuran_kosong_ditolak(): void
    {
        $geo = app(CustomPriceService::class)->geometry($this->kotak, $this->input(['t' => '']));

        $this->assertFalse($geo['ok']);
        $this->assertNotEmpty($geo['errors']);
    }

    public function test_harga_setup_dibagi_qty_dan_minimal_order_setengah_hari(): void
    {
        $svc = app(CustomPriceService::class);
        $r1  = $svc->calculate($this->kotak, $this->input(), 1);
        $r10 = $svc->calculate($this->kotak, $this->input(), 10);

        $perCm2   = 405_000 / (244 * 122);
        $variabel = 3000 * $perCm2 * 1.15 + 4_000 + ($this->cncDasar() + self::RAKIT_DASAR) * 10;   // Rp10/detik, faktor 1

        $this->assertEqualsWithDelta($variabel + 100_000, $r1['current']['hpp'], 0.01);
        $this->assertEqualsWithDelta($variabel + 10_000, $r10['current']['hpp'], 0.01);

        // Markup 60%, dibulatkan KE ATAS berakhiran 900.
        $harga = $r10['current']['price'];
        $this->assertSame(900.0, fmod($harga, 1000));
        $this->assertGreaterThanOrEqual($r10['current']['hpp'] * 1.6, $harga);
        $this->assertLessThan($r10['current']['hpp'] * 1.6 + 1000, $harga);

        // Kapasitas: 3 slot × 7 jam per divisi. Rakit (672 dtk) lebih lambat dari CNC (±417 dtk),
        // jadi rakit yang menentukan: 75.600 / 672 = 112,5 pcs/hari → ½ hari = 57 pcs.
        $this->assertSame('Assembling', $r10['capacity']['bottleneck']);
        $this->assertSame(57, $r10['min_order']);
        $this->assertTrue($r10['current']['below_min']);
        $this->assertNotEmpty(array_filter($r10['warnings'], fn ($w) => str_contains($w, 'minimal order')));
    }

    public function test_minimal_order_bisa_dimatikan(): void
    {
        $this->kotak->update(['min_order_enabled' => false]);

        $r = app(CustomPriceService::class)->calculate($this->kotak, $this->input(), 1);

        $this->assertNull($r['min_order']);
        $this->assertFalse($r['current']['below_min']);
    }

    public function test_bahan_beda_per_bagian_dan_kecepatan_diisi_sendiri(): void
    {
        $hitam = DB::table('products')->insertGetId([
            'sku' => 'L-4x8-3-H', 'name' => 'Akrilik Lembaran 3 mm Hitam', 'last_cost' => 650_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_raw_materials')->insert([
            'product_id' => $hitam, 'panjang' => 244, 'lebar' => 122, 'created_at' => now(), 'updated_at' => now(),
        ]);
        CustomPriceMaterial::create(['product_id' => $hitam, 'tebal_mm' => 3, 'cut_speed' => 10]);

        $geo = app(CustomPriceService::class)->geometry($this->kotak, $this->input([
            'bahan' => ['utama' => $this->bahan, 'alas' => $hitam],
        ]));

        $alas = collect($geo['panels'])->firstWhere('bagian', 'Alas');
        $this->assertSame($hitam, $alas['material_id']);

        // Alas (1000 mm) dipotong 10 mm/dtk, sisanya 25 mm/dtk.
        $this->assertEqualsWithDelta(1000 / 10 + 4900 / 25 + 1800 * 3000 / self::LUAS_LEMBAR, $geo['cnc_base_sec'], 0.001);
    }

    public function test_tutup_geser_memakai_rail_sepanjang_p(): void
    {
        $geo = app(CustomPriceService::class)->geometry($this->kotak, $this->input([
            'tutup_tipe' => 'geser', 'sekat_lt' => '0',
        ]));

        $rail = collect($geo['panels'])->firstWhere('bagian', 'Rail tutup geser');
        $this->assertSame(4.0, $rail['qty']);
        $this->assertEqualsWithDelta(30, $rail['p'], 0.001);
        $this->assertEqualsWithDelta(1, $rail['l'], 0.001);
        $this->assertSame([], $geo['accessories']);   // engsel tidak ikut kalau geser
        $this->assertEqualsWithDelta(160 + 4 * 30, $geo['lem_cm'], 0.001);
    }

    public function test_faktor_dari_sampel_berbobot_dan_sampel_dicoret_tidak_ikut(): void
    {
        $c = $this->cncDasar();
        $a = self::RAKIT_DASAR;
        $svc = $this->serviceDenganTimer([
            101 => [$this->cnc => $c * 2 * 10, $this->asm => $a * 1.5 * 10],   // 10 unit, CNC 2×, rakit 1,5×
            102 => [$this->cnc => $c * 1 * 10, $this->asm => $a * 1.0 * 10],   // 10 unit, pas
            103 => [$this->cnc => $c * 9 * 10, $this->asm => $a * 9.0 * 10],   // ngawur, dicoret
        ]);

        foreach ([101, 102, 103] as $op) {
            CustomPriceSample::create([
                'category_id' => $this->kotak->id, 'production_order_id' => $op, 'units' => 10,
                'input' => $this->input(), 'excluded' => $op === 103, 'exclude_reason' => $op === 103 ? 'timer lupa ditutup' : null,
            ]);
        }

        $f = $svc->factors($this->kotak);

        $this->assertEqualsWithDelta(1.5, $f['cnc']['data'], 0.0001);
        $this->assertEqualsWithDelta(1.25, $f['assembly']['data'], 0.0001);
        $this->assertSame(2, $f['cnc']['n']);
        $this->assertSame('data', $f['cnc']['source']);

        // Kunci manual menang atas data.
        $this->kotak->update(['cnc_factor_manual' => 1.1]);
        $f = $svc->factors($this->kotak->fresh());
        $this->assertEqualsWithDelta(1.1, $f['cnc']['effective'], 0.0001);
        $this->assertSame('manual', $f['cnc']['source']);
        $this->assertEqualsWithDelta(1.25, $f['assembly']['effective'], 0.0001);
    }

    public function test_halaman_hitung_faktor_dan_pengaturan_terbuka(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('analisa.harga-custom.index'))
            ->assertOk()->assertSee('Hitung harga');

        $this->actingAs($user)->get(route('analisa.harga-custom.index', $this->input(['qty' => '10', 'nama' => 'Kotak Amal'])))
            ->assertOk()
            ->assertSee('Teks siap kirim')
            ->assertSee('Kotak Amal 30 x 20 x 15 cm')
            ->assertSee('Minimal order');

        $this->actingAs($user)->get(route('analisa.harga-custom.faktor'))
            ->assertOk()->assertSee('Tambah sampel');

        $this->actingAs($user)->get(route('analisa.harga-custom.pengaturan'))
            ->assertOk()->assertSee('Akrilik Lembaran 2 mm Bening');
    }

    public function test_simpan_pengaturan_dan_parameter(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->post(route('analisa.harga-custom.pengaturan.save'), [
            'kategori'               => 'kotak',
            'setup_cost'             => '150.000',
            'markup_percent'         => '75',
            'waste_percent'          => '20',
            'cnc_department_id'      => $this->cnc,
            'assembly_department_id' => $this->asm,
            'params'                 => ['harga_grendel' => '12.500', 'rakit_detik_per_komponen' => ''],
        ])->assertRedirect();

        $k = $this->kotak->fresh();
        $this->assertEqualsWithDelta(150_000, $k->setup_cost, 0.001);
        $this->assertEqualsWithDelta(75, $k->markup_percent, 0.001);
        $this->assertFalse($k->min_order_enabled);   // checkbox tidak dikirim = mati

        $params = app(CustomPriceService::class)->params($k);
        $this->assertEqualsWithDelta(12_500, $params['harga_grendel'], 0.001);
        $this->assertEqualsWithDelta(15, $params['rakit_detik_per_komponen'], 0.001);   // kosong = bawaan
    }

    // ── Frame Mahar (25 Nov 2026) ────────────────────────────────────────────────

    private function mahar(): CustomPriceCategory
    {
        $m = CustomPriceCategory::where('code', 'mahar')->firstOrFail();
        $m->update(['cnc_department_id' => $this->cnc, 'assembly_department_id' => $this->asm]);

        return $m->fresh();
    }

    public function test_kategori_mahar_setting_50rb_tanpa_minimal_order(): void
    {
        $m = $this->mahar();

        $this->assertEqualsWithDelta(50_000, $m->setup_cost, 0.001);
        $this->assertEqualsWithDelta(60, $m->markup_percent, 0.001);
        $this->assertEqualsWithDelta(15, $m->waste_percent, 0.001);
        $this->assertFalse($m->min_order_enabled);
    }

    public function test_geometri_mahar_40x30x6_body_dan_tutup_beda_bahan(): void
    {
        $putih = DB::table('products')->insertGetId([
            'sku' => 'L-4x8-2-PS', 'name' => 'Akrilik Lembaran 2 mm Putih Susu', 'last_cost' => 400_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_raw_materials')->insert([
            'product_id' => $putih, 'panjang' => 244, 'lebar' => 122, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $geo = app(CustomPriceService::class)->geometry($this->mahar(), [
            'p' => '40', 'l' => '30', 't' => '6',
            'bahan' => ['utama' => $putih, 'tutup' => $this->bahan],
        ]);

        $this->assertTrue($geo['ok'], implode(' ', $geo['errors'] ?? []));
        // bawah 1200 + samping 480 + depan-belakang 360 + sliding 4×48 + tutup 1200
        $this->assertEqualsWithDelta(3432, $geo['area_total'], 0.001);
        // keliling bawah 140 + 4 rusuk 24 + 4 sliding 160
        $this->assertEqualsWithDelta(324, $geo['lem_cm'], 0.001);
        $this->assertEqualsWithDelta(9, $geo['komponen'], 0.001);
        // keliling potong 140 + 184 + 144 + 329,6 + 140 = 937,6 cm
        $this->assertEqualsWithDelta(9376, $geo['cut_mm'], 0.001);
        $this->assertEqualsWithDelta(9376 / 25 + 1800 * 3432 / self::LUAS_LEMBAR, $geo['cnc_base_sec'], 0.001);
        $this->assertEqualsWithDelta(9 * 15 + 324 * 2.2, $geo['asm_base_sec'], 0.001);

        $tutup = collect($geo['panels'])->firstWhere('bagian', 'Tutup');
        $sliding = collect($geo['panels'])->firstWhere('bagian', 'Sliding');
        $this->assertSame($this->bahan, $tutup['material_id']);
        $this->assertSame($putih, $sliding['material_id']);   // sliding ikut body

        $bening = 405_000 / self::LUAS_LEMBAR;
        $psCm2  = 400_000 / self::LUAS_LEMBAR;
        $this->assertEqualsWithDelta((2232 * $psCm2 + 1200 * $bening) * 1.15, $geo['material_cost'], 0.01);
        $this->assertSame(['AM-40x30x6', 'AM-30x40x6'], $geo['ready_skus']);
        // Volumetrik (40+6)(30+5)(6+5) ÷ 5000
        $this->assertEqualsWithDelta(46 * 35 * 11 / 5000, $geo['volumetric_kg'], 0.0001);
    }

    public function test_packing_kayu_rumus_sheet_pemilik(): void
    {
        $svc  = app(CustomPriceService::class);
        $kayu = $svc->packingKayu(['p' => 40, 'l' => 30, 't' => 6], $svc->params($this->mahar()));

        // Peti 32 × 44 × 8: atas-bawah 2816 cm², samping 1216 cm² (sama dengan sheet: Rp29.206)
        $this->assertEqualsWithDelta(2816, $kayu['luas_ab'], 0.001);
        $this->assertEqualsWithDelta(1216, $kayu['luas_samping'], 0.001);
        $this->assertEqualsWithDelta(2816 * 4.464 + 1216 * 5.456 + 10_000, $kayu['total'], 0.01);

        $input = ['p' => '40', 'l' => '30', 't' => '6', 'bahan' => ['utama' => $this->bahan]];
        $instan = $svc->calculate($this->mahar(), $input, 1, 0, 'instant');
        $peti   = $svc->calculate($this->mahar(), $input, 1, 0, 'kayu');
        $this->assertEqualsWithDelta($kayu['total'], $peti['current']['hpp'] - $instan['current']['hpp'], 0.01);
    }

    public function test_pembulatan_berakhiran_900(): void
    {
        $svc = app(CustomPriceService::class);

        $this->assertSame(103_900.0, $svc->roundPrice(103_080));
        $this->assertSame(103_900.0, $svc->roundPrice(103_900));
        $this->assertSame(104_900.0, $svc->roundPrice(103_900.5));
        $this->assertSame(900.0, $svc->roundPrice(120));
    }

    public function test_harga_mp_untung_sama_dan_potongan_tambahan_custom(): void
    {
        $svc = app(CustomPriceService::class);
        $m   = $this->mahar();
        $r   = $svc->calculate($m, ['p' => '40', 'l' => '30', 't' => '6', 'bahan' => ['utama' => $this->bahan]], 10);

        $fees = $svc->channelFees($svc->params($m));
        $this->assertEqualsWithDelta(3, $fees['shopee']['extra'], 0.001);
        $this->assertEqualsWithDelta(3, $fees['tiktok_tokopedia']['extra'], 0.001);
        $this->assertEqualsWithDelta(0, $fees['lazada']['extra'], 0.001);

        $dasar = $r['current']['hpp'] * 1.6;
        foreach ($r['current']['channels'] as $key => $ch) {
            $f = $fees[$key];
            $this->assertSame($svc->roundPrice(($dasar + $f['fixed']) / (1 - $f['percent'] / 100)), $ch['price'], $key);
            // Untung bersih di MP tidak kurang dari untung Non-MP sebelum pembulatan.
            $this->assertGreaterThanOrEqual($dasar - $r['current']['hpp'] - 0.01, $ch['profit'], $key);
        }

        // Pesanan lebih banyak = biaya setting per pcs lebih kecil = lebih murah.
        $t = collect($r['tiers'])->keyBy('qty');
        $this->assertGreaterThan($t[10]['price'], $t[1]['price']);
        $this->assertGreaterThanOrEqual($t[20]['price'], $t[10]['price']);
    }

    public function test_harga_grosir_terkunci_per_kelompok_dan_untung_pesanan(): void
    {
        $svc   = app(CustomPriceService::class);
        $m     = $this->mahar();
        $input = ['p' => '40', 'l' => '30', 't' => '6', 'bahan' => ['utama' => $this->bahan]];
        $harga = fn (int $q) => $svc->calculate($m, $input, $q)['current']['price'];

        $r4 = $svc->calculate($m, $input, 4);
        $g  = $r4['grosir'];
        $this->assertSame(['1 pcs', '2–5 pcs', '6 pcs ke atas'], array_column($g['groups'], 'label'));
        $this->assertSame([$harga(1), $harga(2), $harga(6)], array_column($g['groups'], 'price'));
        $this->assertSame('2–5 pcs', $g['berlaku']);

        // Pesanan 4 pcs dibayar harga kelompok 2–5 (dihitung pada 2 pcs), HPP pada 4 pcs.
        $non = $g['pesanan']['nonmp'];
        $this->assertSame($harga(2), $non['price']);
        $this->assertEqualsWithDelta(($harga(2) - $r4['current']['hpp']) * 4, $non['untung'], 0.01);

        // 10 dan 50 pcs: harga grosir sama.
        $g10 = $svc->calculate($m, $input, 10)['grosir'];
        $g50 = $svc->calculate($m, $input, 50)['grosir'];
        $this->assertSame('6 pcs ke atas', $g10['berlaku']);
        $this->assertSame($harga(6), $g10['pesanan']['nonmp']['price']);
        $this->assertSame($g10['pesanan']['nonmp']['price'], $g50['pesanan']['nonmp']['price']);
        $this->assertSame($g10['pesanan']['shopee']['price'], $g50['pesanan']['shopee']['price']);
    }

    public function test_geometri_tempat_obat_19x8x6(): void
    {
        $to = CustomPriceCategory::where('code', 'tempat_obat')->firstOrFail();
        $to->update(['cnc_department_id' => $this->cnc, 'assembly_department_id' => $this->asm]);

        $geo = app(CustomPriceService::class)->geometry($to->fresh(), [
            'p' => '19', 'l' => '8', 't' => '6', 'bahan' => ['utama' => $this->bahan],
        ]);

        $this->assertTrue($geo['ok'], implode(' ', $geo['errors'] ?? []));
        // bawah 19,5×8 + samping 2×19,5×6 + depan-belakang 2×8×6 + kantong 8×4,2
        $this->assertEqualsWithDelta(156 + 234 + 96 + 33.6, $geo['area_total'], 0.001);
        // keliling bawah 55 + 4 rusuk 24 + 2 sisi kantong 8,4
        $this->assertEqualsWithDelta(87.4, $geo['lem_cm'], 0.001);
        $this->assertEqualsWithDelta(6, $geo['komponen'], 0.001);
        // ±4,7 menit — timer nyata Tempat Obat ready 4,9 menit/pcs
        $this->assertEqualsWithDelta(6 * 15 + 87.4 * 2.2, $geo['asm_base_sec'], 0.001);
        $this->assertEqualsWithDelta(2374, $geo['cut_mm'], 0.001);
        $this->assertSame(['TO-8-M2-2'], $geo['ready_skus']);

        $geo2 = app(CustomPriceService::class)->geometry($to->fresh(), [
            'p' => '8', 'l' => '19', 't' => '6', 'bahan' => ['utama' => $this->bahan],
        ]);
        $this->assertSame(['TO-8-M2-2'], $geo2['ready_skus']);
    }

    public function test_grosir_tempat_obat_di_bawah_50_tetap_harga_normal(): void
    {
        $svc = app(CustomPriceService::class);
        $to  = CustomPriceCategory::where('code', 'tempat_obat')->firstOrFail();
        $to->update(['cnc_department_id' => $this->cnc, 'assembly_department_id' => $this->asm]);
        $to  = $to->fresh();
        $input = ['p' => '19', 'l' => '8', 't' => '6', 'bahan' => ['utama' => $this->bahan]];
        $harga = fn (int $q) => $svc->calculate($to, $input, $q)['current']['price'];

        $g = $svc->calculate($to, $input, 10)['grosir'];
        $this->assertSame(['1–99 pcs', '100 pcs ke atas'], array_column($g['groups'], 'label'));
        $this->assertSame([$harga(50), $harga(100)], array_column($g['groups'], 'price'));
        $this->assertSame('1–99 pcs', $g['berlaku']);
        $this->assertSame($harga(50), $g['pesanan']['nonmp']['price']);   // 10 pcs bayar harga normal

        $this->assertSame('100 pcs ke atas', $svc->calculate($to, $input, 250)['grosir']['berlaku']);
    }

    public function test_cek_silang_produk_ready_ukuran_sama(): void
    {
        DB::table('products')->insert([
            'sku' => 'AM-40x30x6', 'name' => 'Frame Mahar Akrilik 40x30x6 Instant', 'base_price' => 94_900,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = app(CustomPriceService::class)->calculate($this->mahar(), [
            'p' => '30', 'l' => '40', 't' => '6', 'bahan' => ['utama' => $this->bahan],
        ], 1);

        $this->assertSame('AM-40x30x6', $r['ready']['sku']);
        $this->assertEqualsWithDelta(94_900, $r['ready']['base_price'], 0.001);
    }

    public function test_halaman_hitung_mahar(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->mahar();

        $this->actingAs($user)->get(route('analisa.harga-custom.index', [
            'kategori' => 'mahar', 'p' => '40', 'l' => '30', 't' => '6',
            'bahan' => ['utama' => $this->bahan], 'qty' => '5', 'packing' => 'kayu',
        ]))
            ->assertOk()
            ->assertSee('Frame Mahar')
            ->assertSee('Harga Non-MP')
            ->assertSee('Packing kayu')
            ->assertSee('Shopee')
            ->assertDontSee('Simpan jadi bawaan');   // masih sama dengan bawaan

        // Coba markup 45% & setting 30rb: hanya untuk hitungan ini, kategori tidak berubah.
        $this->actingAs($user)->get(route('analisa.harga-custom.index', [
            'kategori' => 'mahar', 'p' => '40', 'l' => '30', 't' => '6',
            'bahan' => ['utama' => $this->bahan], 'qty' => '2', 'setup' => '30.000', 'markup' => '45',
        ]))
            ->assertOk()
            ->assertSee('Simpan jadi bawaan Frame Mahar')
            ->assertSee('Rp15.000');   // 30.000 ÷ 2 pcs
        $this->assertEqualsWithDelta(60, $this->mahar()->markup_percent, 0.001);

        // Simpan jadi bawaan.
        $this->actingAs($user)->post(route('analisa.harga-custom.pengaturan.harga'), [
            'kategori' => 'mahar', 'setup_cost' => '30000', 'markup_percent' => '45',
        ])->assertRedirect();
        $m = $this->mahar();
        $this->assertEqualsWithDelta(30_000, $m->setup_cost, 0.001);
        $this->assertEqualsWithDelta(45, $m->markup_percent, 0.001);
    }

    // ── Bantuan ──────────────────────────────────────────────────────────────────

    /** Service dengan waktu timer palsu per OP, supaya tes faktor tidak perlu membangun OP lengkap. */
    private function serviceDenganTimer(array $secByOrder): CustomPriceService
    {
        return new class(app(ProductionQuotaService::class), app(MaterialRecipeService::class), app(ChannelPricingService::class), app(ProductHppService::class), $secByOrder) extends CustomPriceService {
            public function __construct($q, $m, $c, $h, private array $secByOrder)
            {
                parent::__construct($q, $m, $c, $h);
            }

            public function measuredSeconds(array $orderIds): array
            {
                $out = [];
                foreach ($orderIds as $id) {
                    $out[$id] = [
                        'order_number' => 'OP-' . $id, 'product_name' => null, 'production_date' => null,
                        'sec' => $this->secByOrder[$id] ?? [], 'unfinished' => false,
                    ];
                }

                return $out;
            }
        };
    }

    private function stubKuota(): void
    {
        $slots = [];
        foreach ([$this->cnc, $this->asm] as $dept) {
            for ($i = 0; $i < 3; $i++) {
                $slots[] = ['department_id' => $dept, 'hours_per_day' => self::JAM_PER_SLOT, 'working_days' => 24];
            }
        }

        $this->app->instance(ProductionQuotaService::class, new class($slots) extends ProductionQuotaService {
            public function __construct(private array $stubSlots)
            {
            }

            public function build(array $filters = []): array
            {
                return [
                    'slots' => $this->stubSlots,
                    'cost'  => [
                        'rate_per_slot_hour'      => CustomPriceServiceTest::TARIF,
                        'packing_per_transaction' => 0.0,
                    ],
                ];
            }
        });
    }
}
