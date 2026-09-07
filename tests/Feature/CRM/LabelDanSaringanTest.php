<?php

namespace Tests\Feature\CRM;

use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmLabel;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Label yang bisa diganti namanya + saringan kepala kolom kiri.
 *
 * Yang dijaga di sini adalah dua janji yang gampang patah diam-diam:
 *
 *  - mengganti NAMA label tidak boleh menyentuh percakapan yang memakainya
 *    (yang tersimpan kode, bukan tulisan) — kalau patah, semua chat lama jadi
 *    yatim dan tak muncul di saringan mana pun;
 *  - angka di tombol harus SAMA dengan isi yang dibukanya; angka yang dihitung
 *    dari seluruh percakapan sementara daftarnya disaring per pemilik akan
 *    menampilkan "5" lalu membuka daftar berisi satu.
 */
class LabelDanSaringanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function chat(string $nomor, array $attrs = []): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor);

        $p->forceFill($attrs + [
            'queue_state'     => CrmConversation::QUEUE_KITA,
            'status'          => CrmConversation::STATUS_AKTIF,
            'unread_count'    => 0,
            'last_message_at' => now(),
        ])->save();

        return $p;
    }

    /* ------------------------------------------------------------ master label */

    public function test_label_bawaan_terpasang_dari_migrasi(): void
    {
        $this->assertSame(
            ['menunggu_kita', 'menunggu_pelanggan', 'menunggu_desain', 'dingin'],
            CrmLabel::terpakai()->pluck('kode')->all()
        );
    }

    public function test_ganti_nama_label_tidak_mengubah_percakapan(): void
    {
        $chat  = $this->chat('628111000111');
        $label = CrmLabel::where('kode', CrmConversation::QUEUE_KITA)->first();

        $this->actingAs($this->admin())
            ->post(route('crm.label.update', $label->id), [
                'nama' => 'Perlu Dibalas', 'warna' => 'red', 'urutan' => 1, 'aktif' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(CrmConversation::QUEUE_KITA, $chat->fresh()->queue_state);
        $this->assertSame('Perlu Dibalas', CrmLabel::nama(CrmConversation::QUEUE_KITA));
    }

    public function test_label_yang_masih_dipakai_tidak_bisa_dihapus(): void
    {
        $this->chat('628111000112');
        $label = CrmLabel::where('kode', CrmConversation::QUEUE_KITA)->first();

        $this->actingAs($this->admin())
            ->delete(route('crm.label.destroy', $label->id))
            ->assertRedirect();

        $this->assertDatabaseHas('crm_labels', ['id' => $label->id]);
    }

    public function test_label_kosong_boleh_dihapus(): void
    {
        $label = CrmLabel::where('kode', 'dingin')->first();

        $this->actingAs($this->admin())
            ->delete(route('crm.label.destroy', $label->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('crm_labels', ['id' => $label->id]);
    }

    public function test_label_baru_bisa_dipilih_sebagai_antrean_percakapan(): void
    {
        $admin = $this->admin();
        $chat  = $this->chat('628111000113');

        $this->actingAs($admin)->post(route('crm.label.store'), [
            'nama' => 'Menunggu Bayar', 'warna' => 'amber',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->post(route('crm.inbox.antrean', $chat->id), ['queue_state' => 'menunggu_bayar'])
            ->assertRedirect();

        $this->assertSame('menunggu_bayar', $chat->fresh()->queue_state);
    }

    /* --------------------------------------------------------------- saringan */

    public function test_saringan_belum_dibaca_hanya_menampilkan_yang_belum_dibaca(): void
    {
        $this->chat('628111000114', ['unread_count' => 3, 'display_name' => 'Ada Kabar']);
        $this->chat('628111000115', ['unread_count' => 0, 'display_name' => 'Sudah Dibaca']);

        $this->actingAs($this->admin())
            ->get('/erp/crm?pemilik=semua&belum_dibaca=1')
            ->assertOk()
            ->assertSee('Ada Kabar')
            ->assertDontSee('Sudah Dibaca');
    }

    public function test_angka_belum_dibaca_menghitung_chat_bukan_pesan(): void
    {
        $this->chat('628111000116', ['unread_count' => 7]);
        $this->chat('628111000117', ['unread_count' => 4]);

        $this->actingAs($this->admin())
            ->get('/erp/crm?pemilik=semua')
            ->assertOk()
            ->assertSee('Belum dibaca <span class="">2</span>', false);
    }

    public function test_pencarian_menemukan_chat_lewat_nomor_pesanan(): void
    {
        $pelanggan = Customer::create(['code' => 'CUST-RINA', 'name' => 'Bu Rina', 'is_active' => true]);
        $this->chat('628111000118', ['customer_id' => $pelanggan->id]);
        $this->chat('628111000119', ['display_name' => 'Orang Lain']);

        $gudang = \App\Core\Inventory\Warehouse::create([
            'code' => 'GD-UJI', 'name' => 'Gudang Uji', 'is_active' => 1,
        ]);

        SalesOrder::create([
            'order_number' => 'SO-2609-0007',
            'customer_id'  => $pelanggan->id,
            'warehouse_id' => $gudang->id,
            'order_date'   => now()->toDateString(),
            'status'       => 'draft',
        ]);

        $this->actingAs($this->admin())
            ->get('/erp/crm?pemilik=semua&search=SO-2609-0007')
            ->assertOk()
            ->assertSee('Bu Rina')
            ->assertDontSee('Orang Lain');
    }

    public function test_angka_label_mengikuti_saringan_pemilik(): void
    {
        $admin = $this->admin();
        $lain  = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->chat('628111000120', ['owner_user_id' => $admin->id]);
        $this->chat('628111000121', ['owner_user_id' => $lain->id]);
        $this->chat('628111000122', ['owner_user_id' => $lain->id]);

        // Milik saya: satu chat berlabel "Menunggu Kita", bukan tiga.
        $isi = $this->actingAs($admin)
            ->get('/erp/crm?pemilik=' . $admin->id)
            ->assertOk()
            ->getContent();

        // Dicocokkan lewat pola, bukan potongan HTML mentah: yang dijaga adalah
        // ANGKANYA, dan tes yang ikut mematok spasi akan merah tiap kali kelas
        // Tailwind-nya digeser sedikit.
        $this->assertMatchesRegularExpression('/Menunggu Kita\s*<span[^>]*>1<\/span>/', $isi);
    }
}
