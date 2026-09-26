<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chip di baris daftar chat: label, pemilik, dan lencana jendela.
 *
 * Dua janji yang dijaga di sini, dan keduanya soal apa yang dilihat operator
 * sepanjang hari:
 *
 *  - label & pemilik bisa diganti LANGSUNG dari barisnya lewat dropdown yang
 *    berlabuh di chipnya, dan tiap pilihan LANGSUNG mengirim formnya. Kalau
 *    chipnya berubah jadi teks mati lagi, satu-satunya jalan kembali ke titik
 *    tiga — tiga klik untuk aksi yang dipakai puluhan kali sehari;
 *  - lencana "jendela tutup" hanya muncul pada jalur BERBAYAR. Sejak chat
 *    pindah ke WAHA tak ada jendela 24 jam sama sekali di sana, jadi lencana
 *    itu akan menempel di hampir setiap baris sebagai peringatan yang tidak
 *    menghalangi apa pun — alarm palsu yang persis sama sudah pernah
 *    dibereskan di pita "pesan masuk tidak sampai".
 */
class ChipBarisDaftarTest extends TestCase
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

    public function test_chip_label_dan_pemilik_membuka_dropdown_di_tempat(): void
    {
        $this->chat('628111222001');

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('bukaChip($event', false)
            ->assertSee("'label')", false)
            ->assertSee("'oper')", false);
    }

    /**
     * Tiap pilihan di dropdown adalah tombol kirim form-nya sendiri.
     *
     * Inilah yang membedakannya dari popup lama: memilih SUDAH berarti
     * menyimpan. Kalau ia kembali jadi <select> + tombol Simpan, jumlah kliknya
     * balik seperti semula dan seluruh gunanya hilang.
     */
    public function test_tiap_pilihan_dropdown_langsung_menyimpan(): void
    {
        $this->chat('628111222005');

        $html = $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="queue_state" value="menunggu_kita"', $html);
        $this->assertStringContainsString('name="owner_user_id" value=""', $html);
        $this->assertStringNotContainsString('<select name="queue_state"', $html);
        $this->assertStringNotContainsString('<select name="owner_user_id"', $html);
    }

    /** Tanpa pemilik chipnya tetap ada — kalau hilang, tak ada yang bisa diklik untuk mengoper. */
    public function test_chip_pemilik_muncul_meski_belum_dioper(): void
    {
        $this->chat('628111222002', ['owner_user_id' => null]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('title="Oper chat ke agen lain"', false)
            ->assertSee('border-dashed', false);
    }

    public function test_lencana_jendela_tidak_muncul_di_thread_waha(): void
    {
        $this->chat('628111222003', [
            'channel'           => CrmConversation::KANAL_CERMIN,
            'window_expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertDontSee('jendela tutup');
    }

    public function test_lencana_jendela_tetap_muncul_di_jalur_resmi_berbayar(): void
    {
        $this->chat('628111222004', [
            'channel'           => CrmConversation::KANAL_RESMI,
            'window_expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('jendela tutup');
    }
}
