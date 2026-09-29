<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Sales\Models\SalesOrder;
use App\Services\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pelanggan kembar: satu orang tercatat dua kali karena nomornya ditulis beda
 * ('+62 812-5252-7212' di master, '081252527212' dari checkout web).
 *
 * Aturan: nomor + nama sama → digabung otomatis. Nomor sama tapi nama beda →
 * TIDAK digabung sendiri, menunggu keputusan admin.
 */
class GabungPelangganKembarTest extends TestCase
{
    use RefreshDatabase;

    /** Pelanggan "lama" ditulis tanpa observer, meniru data sebelum fitur ini ada. */
    private function lama(array $a = []): Customer
    {
        return Customer::withoutEvents(fn () => Customer::create(array_merge([
            'code' => 'CUST-' . uniqid(), 'name' => 'Nilam', 'phone' => '+62 812-5252-7212', 'is_active' => true,
        ], $a)));
    }

    private function so(Customer $c): SalesOrder
    {
        return SalesOrder::create([
            'order_number' => 'SO-' . uniqid(), 'customer_id' => $c->id,
            'warehouse_id' => Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id,
            'order_date' => now()->toDateString(), 'global_discount_type' => 'nominal',
            'status' => 'confirmed', 'grand_total' => 100000,
        ]);
    }

    public function test_pelanggan_baru_bernomor_dan_bernama_sama_langsung_digabung(): void
    {
        $lama = $this->lama();

        $baru = Customer::create([
            'code' => 'WEB-1', 'name' => 'nilam ', 'phone' => '081252527212',
            'email' => 'nilam@contoh.id', 'web_order_pin' => Hash::make('1234'), 'is_active' => true,
        ]);

        $baru->refresh();
        $this->assertSame($lama->id, $baru->merged_into_id);
        $this->assertFalse((bool) $baru->is_active);
        $this->assertTrue($baru->penampung()->is($lama));
        // Isian yang kosong di pelanggan lama diwarisi — PIN web ikut, jadi login tetap jalan.
        $this->assertSame('nilam@contoh.id', $lama->fresh()->email);
        $this->assertTrue(Hash::check('1234', $lama->fresh()->web_order_pin));
    }

    public function test_dokumen_ikut_pindah_ke_penampung(): void
    {
        $lama = $this->lama();
        $web  = $this->lama(['code' => 'WEB-2', 'phone' => '081252527212']);
        $so   = $this->so($web);
        $chat = CrmConversation::findOrCreateFor('6281252527212');
        $chat->forceFill(['customer_id' => $web->id])->save();

        $hasil = app(CustomerMergeService::class)->gabungOtomatis();

        $this->assertCount(1, $hasil);
        $this->assertSame($lama->id, $so->fresh()->customer_id);
        $this->assertSame($lama->id, $chat->fresh()->customer_id);
        $this->assertSame($lama->id, $web->fresh()->merged_into_id);
    }

    public function test_nomor_sama_nama_beda_tidak_digabung_otomatis(): void
    {
        $tama = $this->lama(['name' => 'Tama', 'phone' => '0882008062401']);
        $aji  = Customer::create(['code' => 'CUST-AJI', 'name' => 'Aji', 'phone' => '0882-0080-62401', 'is_active' => true]);

        app(CustomerMergeService::class)->gabungOtomatis();

        $this->assertNull($aji->fresh()->merged_into_id);
        $this->assertNull($tama->fresh()->merged_into_id);

        $kelompok = app(CustomerMergeService::class)->kelompokKembar()->first();
        $this->assertTrue($kelompok['cek'], 'harus masuk daftar perlu dicek');
    }

    public function test_admin_bisa_menggabung_manual_yang_namanya_beda(): void
    {
        $tama = $this->lama(['name' => 'Tama', 'phone' => '0882008062401']);
        $aji  = $this->lama(['name' => 'Aji', 'phone' => '0882008062401']);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('customers.kembar'))->assertOk()
            ->assertSee('Nama beda — perlu dicek');

        $this->actingAs($admin)->post(route('customers.kembar.gabung'), ['ke' => $tama->id, 'dari' => [$aji->id]])
            ->assertSessionHas('success');

        $this->assertSame($tama->id, $aji->fresh()->merged_into_id);
    }

    public function test_checkout_web_mengenali_nomor_yang_ditulis_beda(): void
    {
        Cache::flush();
        $key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $key]);
        $lama = $this->lama(['web_order_pin' => Hash::make('1234'), 'address' => 'Jl. Pemuda 1']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'])
            ->postJson('/api/storefront/orders/profile', ['phone' => '081252527212', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Jl. Pemuda 1');

        $this->assertSame(1, Customer::count(), 'tidak boleh lahir pelanggan baru');
        $this->assertTrue($lama->exists);
    }

    public function test_pelanggan_marketplace_tidak_ikut_digabung(): void
    {
        $this->lama(['name' => 'Shopee', 'phone' => '0811111111', 'is_marketplace' => true]);
        $b = Customer::create(['code' => 'MP-2', 'name' => 'Shopee', 'phone' => '0811111111', 'is_marketplace' => true, 'is_active' => true]);

        $this->assertNull($b->fresh()->merged_into_id);
    }
}
